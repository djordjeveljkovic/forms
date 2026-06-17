#!/usr/bin/env bash
# =============================================================================
# setup-vps.sh — Bootstrap a fresh VPS for running the Forms stack.
#
# Idempotent. Safe to re-run. Targets Ubuntu 24.04 LTS (works on 22.04 too).
#
# What it does, in order:
#   1. Sanity check (root, OS).
#   2. apt update + upgrade.
#   3. Install baseline packages (curl, git, ufw, fail2ban, rsync, etc.).
#   4. Create a non-root 'forms' user with passwordless sudo for deploys.
#   5. Install Docker Engine + the Compose plugin from docker.com.
#   6. Install Caddy web server.
#   7. Configure UFW (only 22, 80, 443 open).
#   8. Configure fail2ban (default jails, longer bans).
#   9. Configure unattended-upgrades (security patches auto-installed).
#  10. Set up log rotation for /var/log/caddy and /var/lib/docker/containers.
#  11. Print next steps.
#
# After this script finishes, you:
#   - Add DNS A/AAAA records for forms.example.com -> <VPS_IP>
#   - Drop the project files in /opt/forms (or wherever you cloned to)
#   - Copy .env.docker.example to .env, set APP_KEY, APP_URL, secrets
#   - Run `make -f Makefile.docker up` (or use the deploy script)
# =============================================================================
set -euo pipefail

# ---- Configurable ----
APP_USER="forms"
APP_PORT="8080"
PROJECT_DIR="/opt/forms"
CADDY_DOMAIN="forms.example.com"  # placeholder, changed after DNS is configured

# ---- Pretty output helpers ----
info() { printf "\033[1;34m[setup]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[setup]\033[0m %s\n" "$*"; }
err()  { printf "\033[1;31m[setup]\033[0m %s\n" "$*" >&2; }
ok()   { printf "\033[1;32m[setup]\033[0m %s\n" "$*"; }

# ---- 1. Sanity check ----
if [ "$(id -u)" -ne 0 ]; then
    err "This script must be run as root. Use: sudo bash $0"
    exit 1
fi

if [ ! -f /etc/lsb-release ] && ! grep -qi "ubuntu\|debian" /etc/os-release 2>/dev/null; then
    err "This script targets Ubuntu/Debian. Detected:"
    cat /etc/os-release
    exit 1
fi

ok "Running as root on $(. /etc/os-release && echo "$PRETTY_NAME")"

# ---- 2. apt update + upgrade ----
info "Updating package index and applying pending upgrades"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y --no-install-recommends

# ---- 3. Baseline packages ----
info "Installing baseline packages"
apt-get install -y --no-install-recommends \
    apt-transport-https \
    ca-certificates \
    curl \
    git \
    gnupg \
    rsync \
    ufw \
    fail2ban \
    unattended-upgrades \
    apt-listchanges \
    jq \
    htop \
    ncdu \
    vim-tiny

ok "Baseline packages installed"

# ---- 4. Non-root 'forms' user ----
if ! id "$APP_USER" >/dev/null 2>&1; then
    info "Creating user '$APP_USER'"
    adduser --disabled-password --gecos "Forms deploy" "$APP_USER"
    mkdir -p /home/$APP_USER/.ssh
    chmod 700 /home/$APP_USER/.ssh
    chown -R $APP_USER:$APP_USER /home/$APP_USER

    # Allow passwordless sudo so deploys work over SSH key auth.
    echo "$APP_USER ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/$APP_USER
    chmod 440 /etc/sudoers.d/$APP_USER
    ok "User '$APP_USER' created with passwordless sudo"
else
    ok "User '$APP_USER' already exists"
fi

# ---- 5. Docker Engine + Compose plugin ----
if ! command -v docker >/dev/null 2>&1; then
    info "Installing Docker Engine + Compose plugin"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update -y
    apt-get install -y --no-install-recommends \
        docker-ce \
        docker-ce-cli \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin

    systemctl enable --now docker
    usermod -aG docker "$APP_USER"
    ok "Docker Engine + Compose plugin installed"
else
    ok "Docker is already installed ($(docker --version | head -c 40))"
fi

# ---- 6. Caddy web server ----
if ! command -v caddy >/dev/null 2>&1; then
    info "Installing Caddy"
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        | tee /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -y
    apt-get install -y --no-install-recommends caddy
    systemctl enable --now caddy
    ok "Caddy installed"
else
    ok "Caddy is already installed ($(caddy version))"
fi

# ---- 7. UFW firewall ----
info "Configuring UFW firewall"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP (Caddy -> Let's Encrypt http-01 challenge + redirect)"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable
systemctl restart ufw
ok "UFW active: 22, 80, 443 open; everything else dropped"

# ---- 8. fail2ban ----
info "Tuning fail2ban defaults"
cat > /etc/fail2ban/jail.d/forms.local <<'EOF'
[DEFAULT]
bantime  = 1w
findtime = 10m
maxretry = 5
banaction = ufw

[sshd]
enabled = true

[nginx-http-auth]
enabled = true
EOF
systemctl restart fail2ban
ok "fail2ban: 5 strikes → 1 week ban"

# ---- 9. Unattended upgrades ----
info "Configuring automatic security updates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF
ok "Security patches will auto-install (no auto-reboot)"

# ---- 10. Log rotation for Docker + Caddy ----
info "Configuring log rotation"
cat > /etc/logrotate.d/forms <<'EOF'
/var/log/caddy/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 0640 caddy caddy
    sharedscripts
    postrotate
        systemctl reload caddy >/dev/null 2>&1 || true
    endscript
}

/var/lib/docker/containers/*/*-json.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF
ok "Log rotation configured (Caddy 14d, Docker 7d)"

# ---- 11. Project directory + clone ----
if [ ! -d "$PROJECT_DIR" ]; then
    info "Creating $PROJECT_DIR"
    mkdir -p "$PROJECT_DIR"
    chown -R $APP_USER:$APP_USER "$PROJECT_DIR"
    ok "Created $PROJECT_DIR (owned by $APP_USER)"
    warn "Clone the project into $PROJECT_DIR and continue with:"
    warn "  cd $PROJECT_DIR && git clone <repo-url> ."
    warn "  cp .env.docker.example .env"
    warn "  sudo -u $APP_USER make -f Makefile.docker key"
    warn "  # edit .env (set APP_URL, DB_PASSWORD, etc.)"
    warn "  sudo -u $APP_USER make -f Makefile.docker up"
else
    ok "$PROJECT_DIR already exists"
fi

# ---- 12. Print summary ----
cat <<EOF

$(ok "Setup complete!")

Next steps:
  1. Point DNS A/AAAA records for your domain to this VPS's IP.
     Wait for the change to propagate.
  2. cd $PROJECT_DIR && git clone <repo-url> .
  3. cp .env.docker.example .env
  4. Edit .env — set APP_URL, APP_KEY, DB_PASSWORD, MAIL_*, etc.
  5. Drop the Caddyfile from docker/caddy/Caddyfile into /etc/caddy/Caddyfile
     (edit the domain + email first).
  6. sudo -u $APP_USER make -f Makefile.docker key
  7. sudo -u $APP_USER make -f Makefile.docker up
  8. Verify: curl -I https://your-domain.com

Recommended (one-time, after first successful deploy):
  - Enable Docker log limits:
      mkdir -p /etc/docker
      cat > /etc/docker/daemon.json <<JSON
      {
        "log-driver": "json-file",
        "log-opts": { "max-size": "50m", "max-file": "5" }
      }
      JSON
      systemctl restart docker
  - Set up off-host backups: use the 'backup' profile
      docker compose --profile backup up -d
  - Wire a monitoring/alerting provider (UptimeRobot, Healthchecks.io, etc.)
      against https://your-domain.com/up

EOF
