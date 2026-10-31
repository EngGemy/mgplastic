# Runbook: Timeouts & WAHA reliability

## Symptoms → diagnosis → fix

### Website "Request timed out"
```bash
tail -n 200 storage/logs/performance.log
php artisan whatsapp:health; echo $?
```
If WAHA is DOWN and `WAHA_SYNC_ALERTS=true`, set `WAHA_SYNC_ALERTS=false`, `php artisan config:cache`, restart worker.

### WAHA 401 Unauthorized
```bash
# Confirm key matches WAHA dashboard (do not print .env)
php artisan tinker --execute="echo strlen((string) config('services.waha.key'));"
php artisan config:cache
pkill -u "$USER" -f 'queue:work' || true
# start scripts/queue-worker.sh via cron
php artisan whatsapp:health
```

### WAHA SCAN_QR_CODE / FAILED
```bash
php artisan whatsapp:restart-session --wait=120
php artisan whatsapp:restart-session --status
# If SCAN_QR_CODE: open WAHA UI, scan QR, then:
php artisan whatsapp:health
```

### Breaker stuck open
```bash
php artisan tinker --execute="app(\App\Services\WhatsAppNotifier::class)->resetBreaker();"
php artisan whatsapp:health
```

### Memory exhaustion (512MB)
```bash
grep -E 'slow_request|Allowed memory' storage/logs/*.log | tail -n 50
```
Note method+path from `performance.log`; fix unbounded `get()`/`all()`; do not raise memory_limit.

### Worker not running
```bash
pgrep -af queue:work || echo 'NO WORKER'
# crontab:
# * * * * * /bin/bash $HOME/mgplastic_app/scripts/queue-worker.sh >> $HOME/mgplastic_app/storage/logs/queue-worker.log 2>&1
# * * * * * cd $HOME/mgplastic_app && php artisan schedule:run >> /dev/null 2>&1
```

### After any .env / config change
```bash
php artisan optimize:clear && php artisan config:cache
php artisan queue:restart
pkill -u "$USER" -f 'queue:work' || true
```

### Deep health (cached WAHA — never calls WAHA live)
```bash
curl -sS -H "X-Health-Token: $HEALTH_DEEP_TOKEN" https://YOUR_DOMAIN/health/deep
```
