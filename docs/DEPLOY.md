# Production deploy notes (cPanel)

## Do not run `optimize:clear` in production

`php artisan optimize:clear` also runs `cache:clear`, which wipes:

- queue worker heartbeat (false CRITICAL “no heartbeat”)
- per-check alert state (duplicate “new problem” emails)
- WhatsApp circuit breaker + daily send counters
- OCR daily quotas / Gemini cap counters (file cache)

Use selective clears + rebuild instead:

```bash
php artisan migrate --force

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

php artisan config:cache
php artisan route:cache   # may skip if closure routes
php artisan view:cache
php artisan event:cache   # optional

php artisan queue:restart
```

Scripts already follow this: `deploy/deploy.sh`, `deploy/scripts/post-deploy.sh`.

## Monitoring durability

Heartbeat and alert state live in `system_health_states` (DB), so cache clears no longer reset them.
After `queue:restart`, `queue_worker` check applies a **3-minute grace** before CRITICAL on missing heartbeat.

## Push logs

Users without device tokens get `notification_logs.status = skipped_no_token` (not `failed`).

Backfill legacy rows:

```bash
php artisan notifications:backfill-push-logs --dry-run   # default dry-run
php artisan notifications:backfill-push-logs --apply
```
