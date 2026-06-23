# Testing and deployment

## Test data (DemoSeeder)

The repository ships a `DemoSeeder` that wipes the relevant tables and
seeds a realistic mix of accounts, forms, submissions, and email jobs.
Re-run it any time with:

```bash
php artisan db:seed --class="Database\\Seeders\\DemoSeeder"
```

### Accounts

| Email | Password | Purpose |
| --- | --- | --- |
| `admin@example.com` | `password` | Full access. Use this to manage forms, submissions, and email jobs. |
| `marketing@example.com` | `password` | Receives the newsletter signups. |
| `support@example.com` | `password` | Receives the contact form submissions. |

### Forms

| Slug | Purpose | Storage | Email | Auto-discover |
| --- | --- | --- | --- | --- |
| `contact` | Public contact form with name/email/phone/subject/message | yes | yes | yes |
| `newsletter-signup` | Email + first name | no | yes | yes |
| `bug-report` | Title, reporter, severity (radio), components (checkbox), URL, description | yes | yes | no |
| `customer-survey` | NPS score, date, time, name, email | yes | yes | no |
| `quick-feedback` | No fields - relies on auto-discovery on first submission | yes | yes | yes |
| `strict-intake` | No fields, auto-discovery disabled - rejects everything (422) | yes | yes | no |
| `server-heartbeat` | Internal fire-and-forget endpoint | no | yes | no |
| `disabled-demo` | Both flags off - returns 410 | no | no | no |
| `old-careers` | Archived - returns 410 | yes | yes | no |

### Pulling the API keys

The keys are regenerated every time the seeder runs, so grab them at
test time with:

```bash
php artisan tinker --execute='echo \App\Models\Form::query()->pluck("api_key","slug")->toJson(JSON_PRETTY_PRINT);'
```

Or from the dashboard: sign in as `admin@example.com` and visit
`/dashboard/forms/{slug}/edit` - the key is shown in the
"API credentials" card.

### Smoke-testing the API

The five responses the application is expected to produce:

```bash
# 201 - new submission
curl -X POST http://localhost:8088/api/forms/contact \
  -H "X-Form-Key: <key>" -H "Content-Type: application/json" \
  -d '{"data":{"full_name":"Jane","email":"jane@example.com","subject":"Sales","message":"Hi"}}'

# 422 - validation error
curl -X POST http://localhost:8088/api/forms/contact \
  -H "X-Form-Key: <key>" -H "Content-Type: application/json" -d '{"data":{}}'

# 401 - bad / missing key
curl -X POST http://localhost:8088/api/forms/contact \
  -H "Content-Type: application/json" -d '{"data":{}}'

# 410 - archived or disabled form
curl -X POST http://localhost:8088/api/forms/old-careers \
  -H "X-Form-Key: <key>" -H "Content-Type: application/json" -d '{"data":{}}'

# 404 - unknown slug
curl -X POST http://localhost:8088/api/forms/does-not-exist -d '{}'
```

---

## VPS system requirements

The application is a Laravel 13 + Livewire 4 + Flux UI 2 app. The
production image is defined in `docker/php/Dockerfile` and the
stack in `docker-compose.yml`. The numbers below are derived from
those files plus the resource limits the compose file sets.

### Minimum (single VPS, light traffic, < 10k submissions/day)

| Resource | Minimum | Recommended |
| --- | --- | --- |
| OS | Ubuntu 22.04 LTS or Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 vCPU | 2-4 vCPU |
| RAM | 2 GB | 4 GB |
| Disk | 20 GB SSD | 40 GB SSD (logs + backups) |
| Network | 100 Mbps | 1 Gbps |

The docker compose file caps the app at 1.5 CPU / 1024 MB. With
under that, you can comfortably run:

* nginx + PHP-FPM
* 2 queue workers (`numprocs=2` in `supervisord.conf`)
* the scheduler (one-shot per minute)

### Sizing for real traffic

| Submissions/day | vCPU | RAM | Notes |
| --- | --- | --- | --- |
| < 10k | 2 | 2 GB | one small VPS is enough |
| 10k - 100k | 4 | 4 GB | raise PHP `pm.max_children` to ~30, queue `numprocs=4` |
| 100k - 1M | 8+ | 8 GB+ | split queue + scheduler onto their own container; add a managed MySQL/Redis or move to a separate DB host |

The MySQL and Redis containers in `docker-compose.yml` default to 768 MB
and 256 MB respectively. Move them to a managed instance (RDS,
PlanetScale, Upstash) before crossing 100k submissions/day.

### Software

| Component | Version | Notes |
| --- | --- | --- |
| PHP | 8.3 or 8.4 | `composer.json` requires `^8.3`; the Docker image is pinned to 8.4. |
| Extensions | `bcmath`, `curl`, `intl`, `mbstring`, `opcache`, `pdo_mysql`, `pdo_sqlite`, `session`, `zip` | all built from source in the Dockerfile. |
| PECL | `redis` | required for the production cache/queue driver. |
| Composer | 2.x | install via the official image. |
| Node.js | 20+ (build only) | only needed at build time to produce `public/build/`. Not required at runtime. |
| MySQL | 8.0+ (8.4 in compose) | also works on MariaDB 10.6+. |
| Redis | 6+ (7.4 in compose) | cache + queue broker. |
| Web server | nginx 1.18+ | `docker/nginx/default.conf` is the reference. |
| Process manager | supervisord | runs php-fpm, nginx, queue workers, scheduler. |
| TLS | Let's Encrypt via certbot or Caddy | required - the default config redirects via `TRUSTED_PROXIES=*` and assumes HTTPS. |

### PHP runtime settings

The `Dockerfile` ships an opinionated set; if you are running on a
bare VPS, match these in `/usr/local/etc/php/conf.d/zz-app.ini`:

```ini
memory_limit = 256M
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 60
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
```

For PHP-FPM, the included `zz-app.conf` uses dynamic pm with
`pm.max_children = 40`. Halve it on a 1 GB box, leave it as-is on 2+ GB.

### Network ports

| Port | Service | Notes |
| --- | --- | --- |
| 22 | SSH | restrict to your IPs. |
| 80 | nginx | redirects to 443. |
| 443 | nginx | serves the app and the public API. |
| 3306 | MySQL | do not expose to the internet - bind to the docker network only. |
| 6379 | Redis | same as MySQL. |
| 3307 | MySQL admin (host-side) | mapped by compose; only for ad-hoc `mysql -h 127.0.0.1` access. Disable if not needed. |

The compose file already binds MySQL and Redis to the internal
`forms-net` bridge network. Remove the host-side `3307` mapping if
you do not need it.

### Environment

Required `.env` keys for production (`docker compose up` reads these
from `.env.docker.example`):

```dotenv
APP_NAME=Forms
APP_ENV=production
APP_KEY=<generate with php artisan key:generate>
APP_DEBUG=false
APP_URL=https://forms.example.com
TRUSTED_PROXIES=*

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=forms
DB_USERNAME=forms
DB_PASSWORD=<rotate per environment>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379

MAIL_MAILER=smtp              # or ses / postmark / resend / log
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="${APP_NAME}"

FORMS_MAX_SUBMISSION_SIZE_KB=256
FORMS_SUBMISSION_RATE_LIMIT=60
```

### Provisioning checklist

```bash
# 1. Install the base stack
apt update && apt install -y docker.io docker-compose-plugin

# 2. Bring the project onto the box and generate APP_KEY
git clone <repo> /opt/forms
cd /opt/forms
cp .env.docker.example .env
docker compose run --rm app php artisan key:generate --show
# paste the result into .env as APP_KEY=base64:...

# 3. Boot the stack
docker compose up -d --build

# 4. Migrate + seed
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class="Database\\Seeders\\DemoSeeder"   # optional
docker compose exec app php artisan app:deploy --no-migrate

# 5. Set up TLS (Caddy is the easiest option, or certbot + nginx)
docker compose --profile caddy up -d

# 6. Verify
curl -fsS https://forms.example.com/up | head -1
curl -fsS https://forms.example.com/api/health
```

### Backup

The compose file ships an opt-in `backup` profile that runs daily
`mysqldump` jobs into a named volume. Enable it with:

```bash
docker compose --profile backup up -d
```

The default `BACKUP_RETENTION_DAYS=14`. Move the `forms-backups`
volume off the box on a schedule (restic, borg, or your storage
provider's CLI).
