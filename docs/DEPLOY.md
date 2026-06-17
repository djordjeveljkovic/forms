# Deploying to a VPS — the full guide

This walks you through a production deployment of the Forms stack on a fresh
Ubuntu VPS. Total time: ~30 minutes, mostly waiting for `apt` and `npm`.

## 0. Pick a VPS

Any VPS with at least **2 GB RAM / 1 vCPU / 25 GB disk** will do. Good options:
- [Hetzner](https://www.hetzner.com/cloud) — CX22 (cheapest, EU + US)
- [DigitalOcean](https://www.digitalocean.com) — basic droplet
- [Vultr](https://www.vultr.com) — high-frequency compute
- [OVH](https://www.ovhcloud.com) — Kimsufi / Starter

Use **Ubuntu 24.04 LTS** as the OS image. SSH into it once you have the IP.

## 1. Run the bootstrap script

```bash
# Clone the project anywhere (we'll move it into /opt/forms shortly)
git clone <your-repo-url> forms-app
cd forms-app

# Run the idempotent VPS bootstrap
sudo bash scripts/setup-vps.sh
```

This installs and configures:
- Docker Engine + Compose plugin
- Caddy web server
- UFW firewall (only 22, 80, 443 open)
- fail2ban (5 strikes → 1-week ban)
- unattended-upgrades (security patches auto-install)
- A non-root `forms` user with passwordless sudo
- Log rotation for Caddy + Docker
- `/opt/forms` directory owned by the `forms` user

## 2. Point DNS to the VPS

Before anything else, set up DNS. In your DNS provider's control panel:

| Type | Host | Value |
|------|------|-------|
| `A`   | `forms.example.com`  | `<VPS_IPv4>` |
| `AAAA`| `forms.example.com`  | `<VPS_IPv6>` (if you have one) |
| `A`   | `www.forms.example.com` | `<VPS_IPv4>` (optional, for www redirect) |

Wait for the change to propagate (`dig forms.example.com @1.1.1.1`) before continuing.

## 3. Clone the project

```bash
sudo -u forms git clone <your-repo-url> /opt/forms
sudo -u forms git -C /opt/forms lfs install    # if you use LFS
```

## 4. Configure the environment

```bash
cd /opt/forms
cp .env.docker.example .env
sudo -u forms make -f Makefile.docker key
# This prints the APP_KEY. Paste it into .env.
```

Now edit `.env` and set the production values:
```env
APP_NAME="Forms"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://forms.example.com
APP_KEY=base64:...                       # from the `make key` step

DB_PASSWORD=...                        # strong random
DB_ROOT_PASSWORD=...                    # different strong random

SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
MAIL_HOST=smtp.eu.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="noreply@forms.example.com"
MAIL_FROM_NAME="Forms"
```

## 5. Configure Caddy

Edit [`docker/caddy/Caddyfile`](../docker/caddy/Caddyfile), change the two
placeholders (the domain and the ACME email), then install it:

```bash
sudo nano /etc/caddy/Caddyfile
# paste the contents of docker/caddy/Caddyfile
# change `forms.example.com` -> your real domain
# change `ops@example.com` -> your real email

sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Caddy will:
- Auto-provision a Let's Encrypt TLS certificate for the domain
- Reverse-proxy HTTPS traffic to the Docker app on `127.0.0.1:8080`
- Forward the real client IP via `X-Forwarded-*` headers

Verify:
```bash
curl -I https://forms.example.com/up
# HTTP/2 200
```

## 6. Bring up the stack

```bash
cd /opt/forms
sudo -u forms make -f Makefile.docker up
```

This builds the `forms-app` image and starts the four services:
- `forms-app` — nginx + php-fpm + 2 queue workers + scheduler (port 8080)
- `forms-mysql` — MySQL 8.4 (port 3306, internal only)
- `forms-redis` — Redis 7.4 (port 6379, internal only)

Run the first-time migrations + seed (if you want demo data):
```bash
sudo -u forms make -f Makefile.docker migrate
sudo -u forms make -f Makefile.docker seed
sudo -u forms make -f Makefile.docker deploy
```

## 7. Open the dashboard

Visit `https://forms.example.com` and log in with `test@example.com` /
`password` (or whatever email you used in the seeder).

## 8. Enable daily backups

The stack ships with a backup profile that runs `mysqldump` once a day:

```bash
cd /opt/forms
docker compose --profile backup up -d
# Verify
docker compose logs forms-backup --tail=20
```

Backups land in the `forms-backups` named volume. To get them **off the host**, the easiest
option is to add a sidecar that syncs to S3 (or any S3-compatible storage) every hour:

```bash
# install a tiny cron job that does rclone sync of /backups to an S3 bucket
docker run -d \
  --name forms-s3-sync \
  --restart unless-stopped \
  -v forms-backups:/backups:ro \
  -e RCLONE_CONFIG=/etc/rclone/rclone.conf \
  -v /etc/rclone:/etc/rclone:ro \
  rclone/rclone \
  sync /backups forms-prod:backups/forms --retention 30d
```

## 9. Wire up a health check / monitoring

Your monitoring provider should hit `https://forms.example.com/up` every minute.
That endpoint:
- Returns 200 OK if the app boots correctly
- Is unauthenticated
- Is fast (no DB queries)
- Returns 503 if the app is in maintenance mode

Popular free options: [UptimeRobot](https://uptimerobot.com), [Healthchecks.io](https://healthchecks.io),
[BetterStack](https://betterstack.com).

For deeper monitoring, the queue worker logs are in `docker compose logs app` —
ship them to your log aggregator with a sidecar like [Vector](https://vector.dev).

## 10. Run future deploys

```bash
# from your laptop
git push

# on the VPS
cd /opt/forms
sudo -E -u forms ./scripts/deploy.sh
```

Or wire it up to GitHub Actions / GitLab CI / Forgejo Actions — the deploy
script is idempotent and safe to re-run.

## 11. Backups off-host (production hygiene)

Don't rely on the VPS's local disk for disaster recovery. Two layers of
protection:

1. **The `forms-backups` Docker volume** — survives container restarts.
2. **A daily sync to an off-host location** — S3, Backblaze B2, rsync.net, etc.

See step 8 for an `rclone`-based sync example.

To restore from a backup:
```bash
# Find the latest backup
docker compose exec mysql ls -lh /backups/

# Restore it (this OVERWRITES the database — be careful)
gunzip -c /backups/forms_20240612T030000Z.sql.gz \
  | docker compose exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"
```

## 12. Useful one-liners

```bash
# tail the app logs
cd /opt/forms && docker compose logs -f app

# open a shell in the app
cd /opt/forms && docker compose exec app bash

# open a tinker REPL
cd /opt/forms && docker compose exec app php artisan tinker

# MySQL shell
cd /opt/forms && docker compose exec mysql mysql -u forms -p forms

# Redis shell
cd /opt/forms && docker compose exec redis redis-cli

# restart the queue workers
cd /opt/forms && docker compose exec app supervisorctl restart queue-worker-default:*

# check disk usage
cd /opt/forms && docker system df
du -sh /opt/forms/storage

# view a one-line summary of every running container
cd /opt/forms && docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.CPUPerc}}\t{{.MemUsage}}"
```

## Troubleshooting

**"Permission denied" in `docker compose`**
The `forms` user needs to be in the `docker` group:
```bash
sudo usermod -aG docker forms
sudo -u forms docker ps    # should work without sudo
```

**Caddy returns 502 Bad Gateway**
The app container isn't running, or Caddy is pointing to the wrong port.
```bash
sudo systemctl status caddy
docker compose ps app
docker compose logs app --tail=50
```

**Caddy can't get a Let's Encrypt cert**
DNS isn't pointing to the VPS yet, or port 80 is blocked. Verify:
```bash
dig forms.example.com @1.1.1.1   # should return your VPS IP
sudo ufw status                   # 80/tcp and 443/tcp must be ALLOW
```

**App boots but `forms.example.com` is blank**
Check the Caddyfile:
```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo caddy reload --config /etc/caddy/Caddyfile
sudo journalctl -u caddy --no-pager -n 50
```

**Out of disk space**
```bash
docker system prune -a   # removes stopped containers, dangling images, build cache
journalctl --vacuum-time=7d   # keep only 7 days of journald logs
```
