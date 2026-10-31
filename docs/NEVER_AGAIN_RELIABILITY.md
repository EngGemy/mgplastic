# Never again — reliability checklist (code review)

- [ ] No external HTTP in a web request without `connectTimeout` + `timeout` (≤6s for non-OTP).
- [ ] No remote calls inside DB transactions.
- [ ] No `Model::all()` / unbounded `->get()` on growing tables (invoices, users, logs, products).
- [ ] Every new list API/page paginates or chunks.
- [ ] Every new report has an index plan (or uses existing indexes).
- [ ] Every notification/WhatsApp alert goes through the queue (`WAHA_SYNC_ALERTS=false`).
- [ ] Every scheduled task uses `withoutOverlapping()`.
- [ ] Never call `env()` outside `config/` files (breaks `config:cache`).
- [ ] Do not "fix" timeouts by raising `memory_limit` / `max_execution_time`.
- [ ] OTP channel choice is honored (WhatsApp XOR SMS); Marsol is Libya-only.
