# WhatsApp (WAHA) — تشغيل وصيانة

## متطلبات `.env`

```env
WAHA_URL=
WAHA_API_KEY=
WAHA_SESSION=session_xxxxxxxx          # اسم الجلسة الفعلي من WAHA
WHATSAPP_NOTIFY_ENABLED=true
WAHA_SYNC_ALERTS=true                  # أو false + worker لطابور whatsapp
WAHA_QUEUE=whatsapp
WHATSAPP_DELAY_MIN=1
WHATSAPP_DELAY_MAX=3
WHATSAPP_DAILY_LIMIT=50
WHATSAPP_NOTIFY_CUSTOMERS=true
WHATSAPP_COALESCE_SECONDS=60
WHATSAPP_ALERT_EMAILS=ops@example.com
WHATSAPP_CARD_SIZE=1080x1350
WHATSAPP_CARD_QUALITY=85
APP_LINKS_BASE=https://mg-plastic.com
```

## أوامر السيرفر

```bash
cd /home/mgplasti/mgplastic_app
php artisan migrate --force
php artisan config:clear
php artisan whatsapp:preview --all
php artisan whatsapp:health
```

معاينات الكروت: `storage/app/whatsapp-previews/*.jpg`

## Cron على cPanel (إلزامي للـ schedule)

```cron
* * * * * cd /home/mgplasti/mgplastic_app && php artisan schedule:run >> /dev/null 2>&1
```

الجدول الحالي:

| أمر | التكرار |
|-----|---------|
| `whatsapp:health` | كل 5 دقائق |
| `whatsapp:prune-logs --days=60` | يوميًا 03:15 |
| `whatsapp:prune-cards --days=7` | يوميًا 03:30 |

عند `WAHA_SYNC_ALERTS=false` شغّل عامل الطابور:

```bash
php artisan queue:work --queue=whatsapp --tries=3
```

## ملاحظات أمان

- OTP لا يتأخر ولا يُحسب في الحد اليومي ولا يُخزَّن في `whatsapp_logs` كنص.
- لا تُسجَّل API keys أو نصوص الرسائل الكاملة في اللوج.
- الروابط deep link تحوي معرفات فقط (`/open/{type}/{id}`).
