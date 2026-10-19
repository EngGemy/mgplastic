# Biometric verification — React Native handoff

**Base:** `https://mg-plastic.com/api/v1/mobile`  
**Auth header (after login):** `Authorization: Bearer {sanctum_token}`  
**Settings flag:** `GET /settings` → `data.biometric.enabled === true`

This document is for the **React Native** app team. Backend is live after migrate.

---

## 1) Goal

Let the user unlock the app with **Face ID / Touch ID / fingerprint** without typing password every time, while the server still issues a real Sanctum token.

Flow:

1. User logs in normally (password / OTP).
2. App enables biometrics → backend returns a one-time `unlock_token`.
3. App stores `unlock_token` in **SecureStore / Keychain**, gated by local biometric.
4. Next cold start: biometric unlock → `challenge` → `verify` → new Bearer token → continue as logged in.

---

## 2) Suggested packages (RN)

```bash
npx expo install expo-local-authentication expo-secure-store expo-crypto expo-application
# or bare RN:
# react-native-biometrics + react-native-keychain
```

| Package | Use |
|---------|-----|
| `expo-local-authentication` | Prompt Face ID / fingerprint |
| `expo-secure-store` | Store `unlock_token` + `device_id` |
| `expo-application` | Stable `device_id` (`getAndroidId` / `ios.applicationId` + install id) |

---

## 3) API endpoints

### A) Register device (after password login)

```http
POST /api/v1/mobile/biometric/register
Authorization: Bearer {token}
Content-Type: application/json

{
  "device_id": "ios-XXXX-YYYY",
  "device_name": "iPhone 15",
  "platform": "ios",
  "public_key": null
}
```

`public_key` is optional (PEM or base64 SPKI). If you use signed challenges later, send it here.

**Response `data`:**

```json
{
  "device": {
    "id": 1,
    "device_id": "ios-XXXX-YYYY",
    "platform": "ios",
    "has_public_key": false,
    "is_active": true
  },
  "unlock_token": "ONE_TIME_PLAIN_TOKEN_SHOW_ONCE",
  "instructions": [
    "store_unlock_token_in_secure_storage_behind_biometric",
    "never_log_or_sync_unlock_token",
    "use_challenge_then_verify_to_get_a_new_sanctum_token"
  ]
}
```

**RN must:**

1. Prompt biometric (`LocalAuthentication.authenticateAsync`).
2. On success, save:

```ts
await SecureStore.setItemAsync('mg_biometric_unlock_token', unlock_token, {
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
});
await SecureStore.setItemAsync('mg_biometric_device_id', device_id);
await SecureStore.setItemAsync('mg_biometric_login', phoneOrEmail);
```

3. **Never** put `unlock_token` in AsyncStorage, Redux, logs, or Sentry.

---

### B) Challenge (no auth)

```http
POST /api/v1/mobile/biometric/challenge
Content-Type: application/json

{
  "login": "0912345678",
  "device_id": "ios-XXXX-YYYY"
}
```

**Response `data`:**

```json
{
  "challenge_id": "uuid",
  "nonce": "random-string",
  "expires_at": "2026-10-19T12:00:00+00:00",
  "device_id": "ios-XXXX-YYYY"
}
```

TTL ≈ **120 seconds**.

---

### C) Verify → new Sanctum token (no auth)

```http
POST /api/v1/mobile/biometric/verify
Content-Type: application/json

{
  "challenge_id": "uuid",
  "device_id": "ios-XXXX-YYYY",
  "unlock_token": "ONE_TIME_PLAIN_TOKEN_FROM_SECURE_STORE"
}
```

Optional instead of / in addition to unlock token:

```json
{ "signature": "<base64 SHA256 signature of nonce with device private key>" }
```

**Response `data`:**

```json
{
  "token": "1|sanctum...",
  "user": { "...UserProfileResource..." },
  "device": { "device_id": "ios-XXXX-YYYY" },
  "auth_method": "biometric"
}
```

Save `token` like a normal login and use it for all APIs.

---

### D) Manage devices (auth)

| Method | Path |
|--------|------|
| GET | `/biometric/devices` |
| DELETE | `/biometric/devices/{deviceId}` |
| POST | `/biometric/revoke-all` |

On **logout**, call `DELETE …/biometric/devices/{deviceId}` if the user disables “Remember with Face ID”, or keep registration and only clear the in-memory Sanctum token.

On **logout-all** or password change (recommended): call `POST /biometric/revoke-all` and wipe SecureStore keys.

---

## 4) Recommended RN flow (pseudocode)

```ts
// after successful password login
async function enableBiometric(userLogin: string, deviceId: string) {
  const ok = await LocalAuthentication.authenticateAsync({
    promptMessage: 'تفعيل الدخول بالبصمة',
  });
  if (!ok.success) return;

  const res = await api.post('/biometric/register', {
    device_id: deviceId,
    device_name: Device.modelName,
    platform: Platform.OS, // ios | android
  });

  await SecureStore.setItemAsync('mg_biometric_unlock_token', res.data.unlock_token);
  await SecureStore.setItemAsync('mg_biometric_device_id', deviceId);
  await SecureStore.setItemAsync('mg_biometric_login', userLogin);
}

// cold start
async function tryBiometricLogin() {
  const deviceId = await SecureStore.getItemAsync('mg_biometric_device_id');
  const login = await SecureStore.getItemAsync('mg_biometric_login');
  const unlockToken = await SecureStore.getItemAsync('mg_biometric_unlock_token');
  if (!deviceId || !login || !unlockToken) return null;

  const bio = await LocalAuthentication.authenticateAsync({
    promptMessage: 'تسجيل الدخول بالبصمة',
  });
  if (!bio.success) return null;

  const challenge = await api.post('/biometric/challenge', { login, device_id: deviceId });
  const verified = await api.post('/biometric/verify', {
    challenge_id: challenge.data.challenge_id,
    device_id: deviceId,
    unlock_token: unlockToken,
  });

  await setAuthToken(verified.data.token);
  return verified.data.user;
}
```

---

## 5) UX rules

- Always offer **password login** fallback.
- If `challenge` / `verify` returns 422/401 → clear SecureStore biometric keys and show password screen.
- Do not enable biometric on shared/demo accounts unless product asks.
- Android: prefer `BIOMETRIC_STRONG` when available.
- iOS: Face ID usage string in `Info.plist` / app.json.

---

## 6) Security notes (backend already enforces)

- `unlock_token` is hashed (SHA-256) server-side; plain value shown **once** at register.
- Challenges are single-use and expire in 2 minutes.
- Verify is throttled (`20/min`).
- Inactive / revoked devices cannot verify.
- Approvals / account freeze still apply after biometric login.

---

## 7) Quick checklist for RN

- [ ] Read `GET /settings` → `biometric.enabled`
- [ ] After login → optional “تفعيل البصمة”
- [ ] `POST /biometric/register` → store `unlock_token` in SecureStore
- [ ] Cold start → LocalAuthentication → challenge → verify → Bearer token
- [ ] Settings screen: list devices + revoke
- [ ] Logout / password change → revoke + clear SecureStore
