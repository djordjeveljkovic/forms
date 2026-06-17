# Forms — Docker stack

This directory contains a production-shaped Docker setup for the Forms app:

- **`app`** — single container that runs **nginx + PHP-FPM + queue workers + scheduler** under `supervisord`
- **`mysql`** — MySQL 8.4 with persistent volume (`forms-mysql-data`)
- **`redis`** — Redis 7.4 (used for cache + queue broker) with persistent volume (`forms-redis-data`)
- **`backup`** — opt-in daily `mysqldump` runner (`docker compose --profile backup up -d`)

The application code itself is baked into the `app` image at build time and
**not** mounted from the host, so deployments are reproducible. Only the
runtime data (logs, cache, user uploads) is mounted as volumes.

---

## First-time setup

```bash
# 1. Copy the env template and generate a key
cp .env.docker.example .env
make -f Makefile.docker key

# 2. Build and start the stack
make -f Makefile.docker up

# 3. Run migrations and seed (optional)
make -f Makefile.docker migrate
make -f Makefile.docker seed

# 4. Cache config/routes/views for production
make -f Makefile.docker deploy

# 5. Visit the app
open http://localhost:8080   # or whatever APP_URL you set
```

A successful first run puts the app on `http://localhost:8080`, MySQL on
`localhost:3306` (only exposed on the host so you can `mysql -h 127.0.0.1 -uroot`),
and Redis on `localhost:6379`. The app container talks to MySQL and Redis over
the internal `forms-net` bridge network.

---

## Common commands

```bash
make -f Makefile.docker help        # full command list
make -f Makefile.docker logs         # tail all services
make -f Makefile.docker logs-app     # tail the app container only
make -f Makefile.docker shell        # bash inside the app
make -f Makefile.docker tinker       # php artisan tinker
make -f Makefile.docker artisan cmd="migrate:status"   # arbitrary artisan
make -f Makefile.docker test         # phpunit
make -f Makefile.docker pint         # format the codebase
make -f Makefile.docker phpstan      # static analysis
make -f Makefile.docker queue-restart  # restart the workers

# Database
make -f Makefile.docker mysql-shell  # open a MySQL prompt
make -f Makefile.docker mysql-dump   # write a backup to ./backups/

# Lifecycle
make -f Makefile.docker down         # stop stack (keeps volumes)
make -f Makefile.docker down -v      # stop AND wipe all data
make -f Makefile.docker rebuild      # rebuild the app image from scratch
```

## Persistent volumes

| Volume            | Mounted at                          | Purpose                            |
|-------------------|-------------------------------------|------------------------------------|
| `forms-mysql-data` | `/var/lib/mysql`                    | MySQL data files                   |
| `forms-redis-data` | `/data`                             | Redis AOF dump                     |
| `forms-storage`    | `/var/www/html/storage`             | Logs, framework cache/sessions, uploads |
| `forms-bootstrap-cache` | `/var/www/html/bootstrap/cache`  | Compiled config/routes/views       |
| `forms-backups`    | `/backups`                          | Daily `mysqldump` output (opt-in)  |

To list the volumes:
```bash
docker volume ls | grep forms
```

To wipe a single volume (DESTRUCTIVE):
```bash
docker volume rm forms-mysql-data
```

---

## Production deployment notes

1. **Generate a strong `APP_KEY`** before any deploy and commit `.env` only to your secrets manager — **never** to git.
2. Set `APP_URL` to your real domain and set `TRUSTED_PROXIES=*` (or a CIDR list) so the app reads the real client IP behind your load balancer.
3. Set `SESSION_SECURE_COOKIE=true` once you have HTTPS.
4. Configure a real mail driver (`MAIL_MAILER=smtp` or `ses`/`resend`/`postmark`).
5. Use the `backup` profile: `docker compose --profile backup up -d`. Backups are written to the `forms-backups` volume; mount that onto a host path or push to S3 with a sidecar if you need off-host retention.
6. For zero-downtime deploys, build the new image first, then:
   ```bash
   docker compose up -d --no-deps --build app
   ```
   The new container comes up; supervisord starts nginx + fpm + workers; once the healthcheck is green, drop the old one. Queue workers are restarted fresh on each container start, so in-flight jobs survive (they're stored in Redis).
7. The MySQL container is exposed on the host port for debugging, but in production you should remove the `ports:` mapping and only access it from the internal network (or via an SSH tunnel / sidecar like `mysql-server`).

---

## Image build overview

```
┌─────────────────────────┐
│ node:22-bookworm-slim    │  ← installs JS deps, runs `npm run build`
└──────────┬───────────────┘
           │ public/build/
           ▼
┌─────────────────────────┐
│ php:8.3-fpm-bookworm     │  ← base runtime, installs system deps + PHP extensions
└──────────┬───────────────┘
           │ composer's vendor/ + autoload
           ▼
┌─────────────────────────┐
│ forms-app:latest         │  ← final image with nginx + supervisord config
└─────────────────────────┘
```

A full rebuild takes ~3-5 minutes on a clean machine (most of it is
`composer install` and the Node module download).

---

## Deployment to a real VPS

For a full step-by-step guide to deploying on a VPS with Caddy + Let's Encrypt
+ automatic Let's Encrypt + UFW firewall + fail2ban + unattended-upgrades +
backups, see [`docs/DEPLOY.md`](docs/DEPLOY.md).

The quick path:

1. Get a VPS with Ubuntu 24.04 (Hetzner, DO, Vultr — pick your poison)
2. Point `forms.example.com` DNS A record to the VPS IP
3. SSH in and run `sudo bash scripts/setup-vps.sh` from the cloned repo
4. Edit `docker/caddy/Caddyfile` (change the domain + email) and drop it at `/etc/caddy/Caddyfile`
5. `cd /opt/forms && sudo -u forms make -f Makefile.docker key`
6. Edit `.env` (set `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `MAIL_*`)
7. `sudo -u forms make -f Makefile.docker up`
8. `sudo systemctl reload caddy`
9. Visit `https://forms.example.com` 🎉

Subsequent deploys: `sudo -E -u forms ./scripts/deploy.sh`

## Troubleshooting

**`app` container keeps restarting**
```bash
docker compose logs app --tail=200
```
Common causes: bad `APP_KEY`, MySQL still initialising (wait for the healthcheck to pass), or a permission issue with the `forms-storage` volume.

**Database won't accept connections**
```bash
docker compose ps mysql
docker compose logs mysql --tail=200
```
The MySQL container takes ~10-20s to initialise on first run. After that, it starts immediately.

**Stuck queue**
```bash
docker compose exec app supervisorctl status
docker compose exec app supervisorctl restart queue-worker-default:*
```

**"pull access denied for forms-app"**
Your previous build left a stale image tag in the local cache. The new
docker-compose.yml uses `pull_policy: build` and drops the fixed `image:`
tag, so this should not happen again. To recover:
```bash
docker compose down --remove-orphans
docker image prune -f        # remove the stale forms-app tag
make -f Makefile.docker up   # rebuild from scratch
```

**Want to wipe everything and start over**
```bash
docker compose down -v --remove-orphans
docker compose up -d --build
```

**Performance**
The default `deploy` resources limits are intentionally conservative (1.5 CPU + 1 GB RAM for the app, 1 CPU + 768 MB for MySQL). Increase them in `docker-compose.yml` for higher throughput.
