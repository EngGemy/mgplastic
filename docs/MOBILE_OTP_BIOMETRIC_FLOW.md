# تدفق التحقق OTP + البصمة — موبايل + GPT

تاريخ: 2026-10-21  
Base: `/api/v1`  
الغرض: إصلاح ثغرة الدخول بدون شاشة SMS، وإجبار اختيار **واتساب أو SMS** ثم إدخال الرمز، ثم عرض تفعيل البصمة.

---

## 0) برومبت سريع لـ GPT (شكل الشاشات)

```
صمّم 3 شاشات عربية RTL لتطبيق MG Plastic (مظهر مالي–ديني هادئ):

1) اختيار قناة التحقق — بعد نجاح كلمة المرور وقبل أي Home:
   عنوان: «توثيق رقم الهاتف»
   نص: «كيف تريد استلام رمز التحقق؟»
   زرّان كبيران متساويان: واتساب | رسالة SMS
   لا يوجد زر تخطّي. لا دخول للتطبيق من هذه الشاشة.

2) إدخال رمز التحقق (6 خانات):
   يظهر بعد اختيار القناة وإرسال الرمز.
   تلميح رقم: ••••••1234
   عدّاد إعادة إرسال 60 ثانية
   تبديل القناة: «أرسل عبر واتساب بدلًا من ذلك»
   ممنوع الرجوع للـ Home قبل نجاح verify.

3) تفعيل البصمة (أول دخول بعد OTP أو login_ok مع prompt_biometric=true):
   عنوان: «تفعيل الدخول السريع»
   نص قصير عن Face ID / البصمة
   أزرار: «تفعيل الآن» | «لاحقًا»
   بعد التفعيل أو التخطي → Home.

ألوان: أخضر ثقة عميق + ورق كريمي + ذهب خافت. بدون تخطي OTP.
```

---

## 1) ماذا كانت المشكلة؟

| سبب | أثر |
|-----|-----|
| التسجيل (`register` / `register-store` / …) كان يرجع **`token` قبل توثيق الهاتف** | التطبيق يدخل مباشرة |
| `login` كان يرسل OTP تلقائيًا ويرجع `status:true` بدون بوابة واضحة للقناة | الموبايل قد يتجاهل شاشة الاختيار |
| لا يوجد اختيار صريح `whatsapp` vs `sms` | المستخدم لا يختار القناة |
| توكن قديم لمستخدم `is_phone_verified=false` كان يمر على API | استمرار الجلسة بدون SMS |

---

## 2) التدفق الصحيح (إلزامي في التطبيق)

```
[تسجيل دخول / تسجيل]
        │
        ▼
requires_otp === true && token === null
        │
        ▼
شاشة اختيار القناة (واتساب | SMS)
        │
        ▼
POST /auth/send-otp  { otp_ticket, channel }
        │
        ▼
شاشة إدخال الرمز (6 أرقام)
        │
        ▼
POST /auth/verify-otp  { phone, otp, otp_ticket }
        │
        ▼
token + prompt_biometric === true ؟
        │
   نعم ├─► شاشة تفعيل البصمة ─► Home
   لا  └─► Home مباشرة
```

**قواعد الموبايل (حرجة)**

1. إذا `token == null` أو `requires_otp == true` أو `code == otp_required` → **ممنوع** فتح Home / تبويبات.
2. لا تستخدم بصمة (`biometric/verify`) قبل نجاح OTP مرة واحدة على الأقل (`is_phone_verified`).
3. عند `403` + `code: otp_required` أو `401` + `account_revoked` → امسح التخزين المحلي وارجع لشاشة الدخول.
4. `prompt_biometric: true` → اعرض شاشة البصمة **مرة واحدة** بعد أول توثيق/دخول ناجح بدون أجهزة بصمة مسجّلة.

---

## 3) APIs

### 3.1 تسجيل الدخول

`POST /api/v1/auth/login`  
Body: `{ "phone"|"login"|"email", "password" }`

#### أ) الهاتف غير موثّق → لا توكن، لا إرسال بعد

```json
{
  "status": true,
  "message": "أكمل التحقق من الهاتف قبل الدخول",
  "code": "otp_required",
  "requires_otp": true,
  "otp_sent": false,
  "token": null,
  "otp_ticket": "<مشفّر — صالح 15 دقيقة>",
  "otp_ticket_ttl_minutes": 15,
  "otp_channels": [
    { "key": "whatsapp", "label": "واتساب", "icon": "whatsapp" },
    { "key": "sms", "label": "رسالة SMS", "icon": "sms" }
  ],
  "user": { "id": 1, "phone": "0912…", "is_phone_verified": false }
}
```

#### ب) موثّق ونشط → توكن

```json
{
  "status": true,
  "code": "login_ok",
  "requires_otp": false,
  "token": "1|…",
  "prompt_biometric": true,
  "user": { "is_phone_verified": true }
}
```

`prompt_biometric` = `true` إذا لا يوجد جهاز بصمة نشط.

---

### 3.2 إرسال الرمز (بعد اختيار القناة)

`POST /api/v1/auth/send-otp`  
`POST /api/v1/auth/resend-otp` (نفس الجسم)

```json
{
  "otp_ticket": "<من login/register>",
  "channel": "whatsapp"
}
```

`channel`: `whatsapp` | `sms` فقط.

```json
{
  "status": true,
  "code": "otp_sent",
  "requires_otp": true,
  "otp_sent": true,
  "channel": "whatsapp",
  "otp_expires_at": "2026-10-21T14:00:00+02:00",
  "phone_hint": "••••••0333",
  "message": "تم إرسال رمز التحقق عبر واتساب"
}
```

أخطاء شائعة:

| HTTP | code | معنى |
|------|------|------|
| 401 | `otp_ticket_expired` | أعد login |
| 429 | `otp_cooldown` | انتظر 60 ثانية |
| 422 | `otp_send_failed` | جرّب القناة الأخرى |

---

### 3.3 التحقق

`POST /api/v1/auth/verify-otp`

```json
{
  "phone": "0912000333",
  "otp": "123456",
  "otp_ticket": "<مستحسن>"
}
```

```json
{
  "status": true,
  "code": "otp_verified",
  "token": "2|…",
  "requires_otp": false,
  "prompt_biometric": true,
  "user": { "is_phone_verified": true },
  "message": "تم توثيق الهاتف بنجاح"
}
```

احفظ `token` هنا فقط — ثم اعرض شاشة البصمة إن `prompt_biometric`.

---

### 3.4 التسجيل (سباك / متجر / …)

نفس بوابة OTP: **`token: null`** + `otp_ticket` + `otp_channels` + `requires_otp: true`.  
لا تدخل التطبيق قبل `verify-otp`.

---

### 3.5 إعدادات التطبيق

`GET /api/v1/mobile/settings` يتضمن:

```json
"otp": {
  "channels": [ { "key": "whatsapp", "label": "واتساب", "icon": "whatsapp" }, … ],
  "ticket_ttl_minutes": 15,
  "endpoints": {
    "send": "https://…/api/v1/auth/send-otp",
    "verify": "https://…/api/v1/auth/verify-otp",
    "resend": "https://…/api/v1/auth/resend-otp"
  }
},
"biometric": {
  "enabled": true,
  "prompt_after_otp": true,
  …
}
```

ابنِ أزرار القناة من `otp.channels` لا من hardcode إن أمكن.

---

## 4) البصمة (بعد OTP فقط)

راجع أيضًا: `docs/MOBILE_BIOMETRIC_RN.md`

| متى | ماذا |
|-----|------|
| بعد `otp_verified` أو `login_ok` و `prompt_biometric=true` | شاشة «تفعيل الدخول السريع» |
| المستخدم يوافق | `POST /mobile/biometric/register` ثم خزّن `unlock_token` |
| لاحقًا / تخطي | Home بدون تسجيل جهاز |
| الدخول التالي بالبصمة | `challenge` → `verify` — **لا تتخطى OTP للمستخدم غير الموثّق** |

الخلفية ترفض البصمة إذا `is_phone_verified=false`.

---

## 5) حراسة API (Backend)

أي Bearer لمستخدم:

- محذوف / `is_active=false` → `401` `account_revoked`
- `is_phone_verified=false` → `403` `otp_required` + مسح التوكنات

التطبيق يجب أن يتعامل مع الاثنين بمسح الجلسة المحلية.

---

## 6) حالات التنقّل (Navigation states)

| إشارة من API | الشاشة |
|--------------|--------|
| `code=otp_required`, `otp_sent=false` | اختيار القناة |
| `otp_sent=true` / بعد send ناجح | إدخال الرمز |
| `token` موجود + `prompt_biometric=true` | تفعيل البصمة |
| `token` موجود + `prompt_biometric=false` | Home |
| `otp_ticket_expired` | رجوع لتسجيل الدخول |
| `account_revoked` | تسجيل الدخول + رسالة إيقاف |

---

## 7) نصوص عربية جاهزة للواجهة

| موضع | نص |
|------|-----|
| اختيار قناة | توثيق رقم الهاتف |
| واتساب | استلام الرمز عبر واتساب |
| SMS | استلام الرمز عبر رسالة نصية |
| OTP | أدخل الرمز المكوّن من 6 أرقام |
| إعادة إرسال | إعادة الإرسال خلال {n} ث |
| تبديل قناة | أرسل بطريقة أخرى |
| بصمة عنوان | تفعيل الدخول بالبصمة |
| بصمة جسم | ادخل أسرع في المرات القادمة بدون كلمة مرور |
| تفعيل | تفعيل الآن |
| لاحقًا | لاحقًا |
| ممنوع تخطي | يجب توثيق الهاتف للمتابعة |

---

## 8) Checklist لمطوّر الموبايل

- [ ] لا Home إذا `token == null`
- [ ] بعد login غير موثّق → شاشة القنوات من `otp_channels`
- [ ] `send-otp` قبل شاشة الرمز
- [ ] `verify-otp` ثم حفظ التوكن
- [ ] إن `prompt_biometric` → شاشة البصمة مرة واحدة
- [ ] معالجة `otp_required` / `account_revoked` بمسح SecureStore
- [ ] إزالة أي منطق قديم يعتمد على `otp` في رد login (لم يعد يُرسل)

---

## 9) مراجع

- بصمة RN: `docs/MOBILE_BIOMETRIC_RN.md`
- إعدادات: `GET /api/v1/mobile/settings`
- اختبارات Backend: `tests/Feature/OtpGateFlowTest.php`
