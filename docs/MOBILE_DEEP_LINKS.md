# Deep Links — MG Mobile ↔ WhatsApp

WhatsApp captions end with a **tappable HTTPS** Universal Link (no interactive buttons — WAHA CORE limitation).

## URL shapes

| Kind | Example |
|------|---------|
| HTTPS (in WhatsApp) | `https://mg-plastic.com/open/invoice/42` |
| Custom scheme | `mgplastic://invoice/42` |
| Android intent | `intent://invoice/42#Intent;scheme=mgplastic;package=com.mgplastic.app;end` |
| Home | `https://mg-plastic.com/open/home/0` → `mgplastic://home` |

Types: `invoice` · `order` · `return` · `points` · `supply` · `withdrawal` · `home`  
IDs only — never OTP/secrets. Authorize inside the app after login; if logged out, store the path and resume after auth.

## Backend bridge

`GET /open/{type}/{id?}` detects Android/iOS, tries to open the app, then shows «افتح في التطبيق» + store buttons after ~1.5s.

Well-known (fill env before production):

- `/.well-known/assetlinks.json`
- `/.well-known/apple-app-site-association`

## React Native / Expo

```ts
// app.json / app.config
scheme: "mgplastic",
associatedDomains: ["applinks:mg-plastic.com"],
intentFilters: [{
  action: "VIEW",
  autoVerify: true,
  data: [{ scheme: "https", host: "mg-plastic.com", pathPrefix: "/open" }],
  category: ["BROWSABLE", "DEFAULT"],
}]
```

Handle incoming URL → path `invoice/42` → navigate to that screen (after Sanctum session).

## Env

```env
APP_LINKS_BASE=https://mg-plastic.com
APP_SCHEME=mgplastic
APP_PACKAGE=com.mgplastic.app
APP_ANDROID_URL=
APP_IOS_URL=
APP_ANDROID_SHA256=
APP_APPLE_TEAM_ID=
APP_APPLE_APP_ID=
```
