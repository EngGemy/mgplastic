# MG Plastic — cPanel CI/CD (GitHub Actions)

Production host: **mg-plastic.com** · cPanel user **`mgplasti`**

## Architecture (split deploy)

| Path | Purpose |
|------|---------|
| `/home/mgplasti/mgplastic_app` | Full Laravel app (not web-accessible) |
| `/home/mgplasti/public_html` | Document root (`index.php`, `build/`, assets) |

GitHub Actions builds assets + `vendor/`, uploads via **SSH**, runs migrations on the server.

Workflow: [`.github/workflows/deploy-production.yml`](../.github/workflows/deploy-production.yml)

---

## 1. cPanel preparation

### PHP 8.3

cPanel → **Select PHP Version** → **ea-php83** (already on server).

Enable: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.

### SSH

1. cPanel → **SSH Access** → enable for `mgplasti`
2. Note **hostname** (e.g. `s4936.fra1.stableserver.net` or `mg-plastic.com`)
3. Note **port** (often `22`; some hosts use `6802`)

### Database

1. cPanel → **MySQL Databases** → create DB + user
2. Grant all privileges
3. Configure in server `.env` only (never commit)

### First-time directories (SSH)

```bash
ssh mgplasti@YOUR_HOST -p PORT

mkdir -p ~/mgplastic_app
mkdir -p ~/public_html
mkdir -p ~/mgplastic_app/storage/framework/{cache/data,sessions,views}
mkdir -p ~/mgplastic_app/storage/logs
mkdir -p ~/mgplastic_app/storage/app/public
mkdir -p ~/mgplastic_app/bootstrap/cache
```

### Server `.env` (one-time, manual)

```bash
nano ~/mgplastic_app/.env
```

Copy from `.env.example` and set:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mg-plastic.com

# Obscure admin login — save this URL privately; /admin will return 404
ADMIN_PANEL_PATH=mg-cp-change-me

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=mgplasti_xxxxx
DB_USERNAME=mgplasti_xxxxx
DB_PASSWORD=your_password

QUEUE_CONNECTION=database
```

Then:

```bash
cd ~/mgplastic_app
php artisan key:generate
```

---

## 2. SSH deploy key

On your machine:

```bash
ssh-keygen -t ed25519 -C "github-actions-mgplastic" -f ~/.ssh/mgplastic_deploy -N ""
```

- **Private key** → GitHub secret `CPANEL_SSH_KEY`
- **Public key** → cPanel → SSH Access → Manage SSH Keys → Import → **Authorize**

Test:

```bash
ssh -i ~/.ssh/mgplastic_deploy -p PORT mgplasti@YOUR_HOST
```

---

## 3. GitHub Secrets

Repository: `EngGemy/mgplastic` → **Settings** → **Secrets and variables** → **Actions**

| Secret | Required | Example |
|--------|----------|---------|
| `CPANEL_HOST` | Yes | `s4936.fra1.stableserver.net` |
| `CPANEL_USER` | Yes | `mgplasti` |
| `CPANEL_SSH_KEY` | Yes | Full private key file contents |
| `CPANEL_PORT` | No | `22` (default) |
| `CPANEL_APP_PATH` | No | `/home/mgplasti/mgplastic_app` |
| `CPANEL_PUBLIC_PATH` | No | `/home/mgplasti/public_html` |

Never store `APP_KEY` or DB password in GitHub — only on server `.env`.

---

## 4. Push code to GitHub

From project root (`mgplastic/`):

```bash
git init
git add .
git commit -m "Initial commit: MG Plastic Laravel app with CI/CD"
git branch -M main
git remote add origin git@github.com:EngGemy/mgplastic.git
git push -u origin main
```

> If this folder was inside a larger git repo, use a **separate clone** or `git init` only inside `mgplastic/`.

---

## 5. What each deploy does

1. **CI job** (every PR + push): `composer install`, `php artisan test`, `npm run build`
2. **Deploy job** (push to `main` only):
   - `composer install --no-dev` + `npm run build`
   - Generate `public_html/index.php` pointing to `../mgplastic_app`
   - Upload app + `vendor/` via SSH tar
   - Upload `public/` to `public_html`
   - Run `deploy/scripts/post-deploy.sh`:
     - Storage symlink
     - `php artisan migrate --force`
     - `config:cache`, `view:cache`

**Never overwritten:** server `.env`, logs, user uploads in `storage/app/public`.

---

## 6. Queue worker (recommended)

Add cPanel **Cron Job** every minute:

```bash
cd /home/mgplasti/mgplastic_app && php artisan queue:work --stop-when-empty --max-time=55
```

Or use **Supervisor** if available on your plan.

---

## 7. Fallback: cPanel Git deploy

If GitHub SSH is blocked, use cPanel **Git™ Version Control** + [`.cpanel.yml`](../.cpanel.yml).

Limitations: you must run `composer install` and `npm run build` manually on first deploy.

---

## 8. Troubleshooting

| Issue | Fix |
|-------|-----|
| 500 after deploy | Check `~/mgplastic_app/storage/logs/laravel.log` |
| Application path not found | Verify `CPANEL_APP_PATH` / `CPANEL_PUBLIC_PATH` secrets |
| SSH connection refused | Check `CPANEL_PORT`, firewall, SSH enabled in cPanel |
| Assets 404 | Ensure `public/build` deployed to `public_html/build` |
| Migration fails | Verify DB credentials in server `.env` |

Manual post-deploy:

```bash
ssh mgplasti@HOST
bash ~/mgplastic_app/deploy/scripts/post-deploy.sh ~/mgplastic_app ~/public_html
```
