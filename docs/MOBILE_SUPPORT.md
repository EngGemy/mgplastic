# App support phone and WhatsApp

The support button in the app reads one public endpoint. Numbers are changed from the admin panel, not in the app.

**Admin:** إعدادات الموقع → تبويب **دعم التطبيق**  
**Production:** `GET https://mg-plastic.com/api/v1/mobile/settings`  
**Local:** `GET http://127.0.0.1:8000/api/v1/mobile/settings`

No login token. Call it on app launch, with the rest of the settings.

```http
Accept: application/json
```

---

## Response

`data.support` is the only new block. The rest of `/api/v1/mobile/settings` is unchanged.

```json
{
  "status": true,
  "message": "OK",
  "data": {
    "support": {
      "phone": "0916126065",
      "phone_url": "tel:+218916126065",
      "whatsapp": "0916126065",
      "whatsapp_url": "https://wa.me/218916126065"
    }
  }
}
```

| Field | Use |
|-------|-----|
| `phone` | Number to show on screen, as saved in admin |
| `phone_url` | Open with the phone dialer. `null` when no number is set |
| `whatsapp` | Number to show on screen, as saved in admin |
| `whatsapp_url` | Open WhatsApp. `null` when no number is set |

A Libyan number saved as `0916126065` becomes `218916126065` inside the links. The display fields stay `0916126065`.

If a field is empty in admin, its value and URL are `null`. Hide that button.

```json
"support": {
  "phone": null,
  "phone_url": null,
  "whatsapp": "0922159316",
  "whatsapp_url": "https://wa.me/218922159316"
}
```

Open `phone_url` for the call button and `whatsapp_url` for the WhatsApp button. Do not build the links in the app.
