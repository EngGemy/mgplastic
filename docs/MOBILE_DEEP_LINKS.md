# Deep Links — MG Mobile ↔ WhatsApp

WhatsApp captions end with a **tappable HTTPS** Universal Link (no interactive buttons — WAHA CORE limitation).

## URL shapes

| Kind | Example |
|------|---------|
| HTTPS (in WhatsApp) | `https://mg-plastic.com/open/invoice/42` |
| Custom scheme | `mgplastic://invoice/42` |
| Android intent | `intent://invoice/42#Intent;scheme=mgplastic;package=com.MgTools;end` |
| Home | `https://mg-plastic.com/open/home/0` → `mgplastic://home` |

Types: `invoice` · `order` · `return` · `points` · `supply` · `withdrawal` · `home`  
IDs only — never OTP/secrets. Authorize inside the app after login; if logged out, store the path and resume after auth.

## Backend bridge

`GET /open/{type}/{id?}` detects Android/iOS, tries to open the app, then shows «افتح في التطبيق» + store buttons after ~1.5s.

Well-known (must match the installed app — **no placeholders**):

- `https://mg-plastic.com/.well-known/assetlinks.json`
- `https://mg-plastic.com/.well-known/apple-app-site-association`

Expected production values:

| Platform | Field | Value |
|----------|-------|-------|
| Android | `package_name` | `com.MgTools` |
| Android | `sha256_cert_fingerprints` | two Play signing fingerprints (see env) |
| iOS | `appID` | `LP86Z6FUHH.com.mgPlumbers.app` |

If these are wrong (`TEAMIDXXXX…` / `com.mgplastic.app` / `AA:BB:CC…`), the phone opens Safari/Chrome first, then falls back to `mgplastic://`.

### Server fix

```env
APP_PACKAGE=com.MgTools
APP_APPLE_TEAM_ID=LP86Z6FUHH
APP_APPLE_APP_ID=LP86Z6FUHH.com.mgPlumbers.app
APP_IOS_BUNDLE_ID=com.mgPlumbers.app
APP_ANDROID_SHA256=2C:C3:68:A2:7B:57:DE:E8:19:DA:0A:05:B3:87:58:B6:68:1F:7D:94:BD:C6:A9:D8:68:18:FD:89:8A:C8:D9:E1,A7:96:F1:20:2C:86:8E:E1:ED:9A:70:D1:F8:7F:AE:5B:89:E5:7E:98:F7:A0:50:18:36:86:A4:BE:8F:E0:59:4B
```

Then:

```bash
php artisan config:clear
# optional if you cache config in production:
php artisan config:cache
```

Verify live JSON, then check Apple’s CDN (may lag up to ~24h):

```text
https://app-site-association.cdn-apple.com/a/v1/mg-plastic.com
```

## React Native / Expo

```ts
scheme: "mgplastic",
associatedDomains: ["applinks:mg-plastic.com"],
// Android applicationId / package must be com.MgTools
// iOS bundle id must be com.mgPlumbers.app
intentFilters: [{
  action: "VIEW",
  autoVerify: true,
  data: [{ scheme: "https", host: "mg-plastic.com", pathPrefix: "/open" }],
  category: ["BROWSABLE", "DEFAULT"],
}]
```

Handle incoming URL → path `invoice/42` → navigate to that screen (after Sanctum session).

## Env (reference)

```env
APP_LINKS_BASE=https://mg-plastic.com
APP_SCHEME=mgplastic
APP_PACKAGE=com.MgTools
APP_ANDROID_URL=
APP_IOS_URL=
APP_ANDROID_SHA256=2C:C3:68:A2:7B:57:DE:E8:19:DA:0A:05:B3:87:58:B6:68:1F:7D:94:BD:C6:A9:D8:68:18:FD:89:8A:C8:D9:E1,A7:96:F1:20:2C:86:8E:E1:ED:9A:70:D1:F8:7F:AE:5B:89:E5:7E:98:F7:A0:50:18:36:86:A4:BE:8F:E0:59:4B
APP_APPLE_TEAM_ID=LP86Z6FUHH
APP_APPLE_APP_ID=LP86Z6FUHH.com.mgPlumbers.app
APP_IOS_BUNDLE_ID=com.mgPlumbers.app
```
