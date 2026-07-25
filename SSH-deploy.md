# Production Deployment Guide (SSH + git)

This guide covers deploying to a server where you have **SSH access** and the
server has **git access** (i.e. it can `git clone`/`git pull` from your repo).
This is the simplest, fastest, most auditable deploy path. Use this whenever
it's available; the FTP guide ([deploy.md](deploy.md)) is the fallback for
hosts that can't do SSH.

## TL;DR

```sh
# One-time setup, on your laptop
cp scripts/.env.deploy.example scripts/.env.deploy
chmod 600 scripts/.env.deploy
$EDITOR scripts/.env.deploy           # fill in SSH_*, PROD_*

# One-time setup, on the server (over SSH)
ssh deploy@server.yourdomain.com
sudo mkdir -p /var/www/comics && sudo chown $USER:$USER /var/www/comics
git clone git@github.com:youruser/cbz-reader.git /var/www/comics
cd /var/www/comics
APP_DIR=/var/www/comics ./scripts/server/server-install.sh
$EDITOR backend/.env.prod.local       # fill in DB, mailer, secrets…
APP_DIR=/var/www/comics ./scripts/server/server-install.sh

# Every release, from your laptop
./scripts/deploy-ssh.sh
```

That's it. Read on for the details, the first-time prep, web-server config,
and troubleshooting.

---

## Table of contents

1. [How this differs from the FTP flow](#1-how-this-differs-from-the-ftp-flow)
2. [Prerequisites on the server](#2-prerequisites-on-the-server)
3. [One-time setup (laptop)](#3-one-time-setup-laptop)
4. [One-time setup (server)](#4-one-time-setup-server)
5. [The standard deploy workflow](#5-the-standard-deploy-workflow)
6. [Web server configuration](#6-web-server-configuration)
7. [Background jobs (cron / systemd timers)](#7-background-jobs-cron--systemd-timers)
8. [Rollback](#8-rollback)
9. [GitHub Actions automation](#9-github-actions-automation)
10. [Troubleshooting](#10-troubleshooting)
11. [Security notes](#11-security-notes)
12. [Reference: file inventory](#12-reference-file-inventory)

---

## 1. How this differs from the FTP flow

| Concern              | FTP ([deploy.md](deploy.md))                                     | SSH (this guide)                                                |
| -------------------- | ----------------------------------------------------------------- | --------------------------------------------------------------- |
| Build location       | Locally in Docker, mirror result over FTP                         | On the server (`git pull` + `composer install` + `npm run build`) |
| Migration runner     | Token-protected `_post-deploy.php` over HTTPS                     | `php bin/console doctrine:migrations:migrate` over SSH directly  |
| Secrets storage      | Baked into release/.env.local.php on laptop, mirrored             | Lives only on the server (`backend/.env.prod.local`)             |
| Server requirements  | Only PHP runtime + MySQL                                          | PHP CLI + Composer + git + (Node OR pre-built dist)              |
| Speed                | Limited by FTP throughput, full vendor/ uploaded each time         | Just the diff via git, no asset uploads needed                  |
| Atomicity            | Files updated one-by-one; brief inconsistent state                | Same problem here unless you use the symlink trick (see §8)     |

If your server has SSH **and** can `git pull` from your repo, **always use
this method**. Less stuff in flight, fewer secrets on your laptop, smaller
attack surface.

---

## 2. Prerequisites on the server

The deploy server must have:

| Tool          | Minimum version | Why                                          |
| ------------- | --------------- | -------------------------------------------- |
| OpenSSH       | any             | obviously                                    |
| git           | 2.x             | server-side `git pull`                       |
| PHP CLI       | **8.2** (must match the local Docker `PHP_VERSION`) | runs `bin/console`, `composer install`, prod runtime |
| PHP-FPM (or mod_php) | matching CLI | serves `index.php` to the web server         |
| Composer 2    | 2.5+            | dependency installation                      |
| Node.js       | 18 or 20        | builds the React frontend (skip if you build locally and use `--rsync`) |
| MySQL/MariaDB | 8.0 / 10.6+     | the database                                 |
| Required PHP extensions: `pdo_mysql`, `intl`, `mbstring`, `zip`, `xsl`, `gd`, `opcache` | | enforced by `composer.json` and verified by `server-install.sh` |

### Outbound git access

Either:

- **Recommended**: copy a deploy key into the server's `~/.ssh/` and add it to
  your repo's "Deploy Keys" with read-only access:
  ```sh
  # On the server, as the deploy user
  ssh-keygen -t ed25519 -f ~/.ssh/id_deploy -N ""
  cat ~/.ssh/id_deploy.pub
  # Paste the pubkey into GitHub → Settings → Deploy keys
  cat >> ~/.ssh/config <<'EOF'
  Host github.com
      IdentityFile ~/.ssh/id_deploy
      IdentitiesOnly yes
  EOF
  ssh -T git@github.com
  ```
- Or use HTTPS with a personal-access-token-protected URL (less ideal — token
  ends up in `.git/config`).

### Inbound SSH access

From your laptop, `ssh user@server.yourdomain.com` must work without a
password (use a key). If you don't have an SSH key yet:

```sh
ssh-keygen -t ed25519 -C "you@laptop"
ssh-copy-id -p 22 deploy@server.yourdomain.com
```

### Optional: dedicated `deploy` user

For least-privilege, create a non-root `deploy` user that owns
`/var/www/comics`, is in the `www-data` group (so it can write `var/cache`
that's read by the web server), and has `sudo` permission **only** for
reloading PHP-FPM. Sample sudoers entry:

```
# /etc/sudoers.d/deploy-reload
deploy ALL=(root) NOPASSWD: /bin/systemctl reload php8.2-fpm
deploy ALL=(root) NOPASSWD: /bin/systemctl reload nginx
```

This keeps the deploy account safe while letting `SSH_POST_DEPLOY_HOOK` reload
services automatically.

---

## 3. One-time setup (laptop)

```sh
cp scripts/.env.deploy.example scripts/.env.deploy
chmod 600 scripts/.env.deploy
$EDITOR scripts/.env.deploy
```

The relevant block to fill out for SSH deploys:

```dotenv
# SSH target
SSH_HOST=server.yourdomain.com
SSH_USER=deploy
SSH_PORT=22
SSH_KEY=                              # optional — leave empty to use ssh-agent
SSH_REMOTE_PATH=/var/www/comics       # absolute path on the server
SSH_GIT_BRANCH=main                   # branch the server pulls from
SSH_WEB_USER=www-data
SSH_WEB_GROUP=www-data
SSH_POST_DEPLOY_HOOK="sudo systemctl reload php8.2-fpm"
SSH_BACKUP_COMMAND=/usr/local/bin/backup-comics # must back up DB + uploads and fail on error
```

The `PROD_*` block in the same file is **only used by the FTP flow**. For SSH
deploys, the secrets live on the server in `backend/.env.prod.local` (see
§4.4). You can leave the `PROD_*` block empty if you only deploy via SSH.

Verify SSH connectivity:

```sh
ssh -p $SSH_PORT $SSH_USER@$SSH_HOST 'echo hello && php -v && composer --version && git --version'
```

If those all print versions you're good.

---

## 4. One-time setup (server)

Do this once per server, then never again unless you reinstall.

### 4.1 Create the project directory

```sh
ssh deploy@server.yourdomain.com
sudo mkdir -p /var/www/comics
sudo chown $USER:$USER /var/www/comics
```

### 4.2 Clone the repo

```sh
git clone git@github.com:youruser/cbz-reader.git /var/www/comics
cd /var/www/comics
```

(Or use HTTPS with a deploy token if SSH-to-GitHub isn't available.)

### 4.3 Run the installer (round 1: writes a template env)

```sh
APP_DIR=/var/www/comics ./scripts/server/server-install.sh
```

The first run notices `backend/.env.prod.local` doesn't exist and writes a
template. It stops right there and prints the path to edit.

### 4.4 Fill in `backend/.env.prod.local`

```sh
$EDITOR /var/www/comics/backend/.env.prod.local
```

Critical values:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)                     # 64 hex chars
APP_DATA_KEY=$(openssl rand -base64 32)                # generate once; preserve across every deploy
DATABASE_URL=mysql://comics_user:STRONG_PASS@127.0.0.1:3306/cbz_reader?serverVersion=8.0&charset=utf8mb4
CORS_ALLOW_ORIGIN=^https://comics\.yourdomain\.com$
FRONTEND_URL=https://comics.yourdomain.com
FRONTEND_HOST=comics.yourdomain.com
FRONTEND_SCHEME=https
FRONTEND_PORT=443
MAILER_DSN=smtp://smtp_user:smtp_pass@smtp.yourdomain.com:587
MAILER_TRANSPORT=smtp
MAILER_FROM_ADDRESS=noreply@yourdomain.com
MAILER_FROM_NAME="Comic Reader"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAX_CONCURRENT_UPLOADS=3
DROPBOX_APP_KEY=...
DROPBOX_APP_SECRET=...
DROPBOX_REDIRECT_URI=https://comics.yourdomain.com/api/dropbox/callback
DROPBOX_APP_FOLDER=/
DROPBOX_SYNC_LIMIT=10
DROPBOX_RATE_LIMIT=60
DEPLOY_TOKEN=$(openssl rand -hex 32)                   # only if you also use _post-deploy.php
```

```sh
chmod 600 backend/.env.prod.local
```

### 4.5 Create the database

```sh
sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS cbz_reader CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'comics_user'@'localhost' IDENTIFIED BY 'STRONG_PASS';
  GRANT ALL PRIVILEGES ON cbz_reader.* TO 'comics_user'@'localhost';
  FLUSH PRIVILEGES;
"
```

(Adjust according to whether MySQL is local, on a separate host, or a managed
service.)

### 4.6 Run the installer (round 2: actually builds)

```sh
APP_DIR=/var/www/comics ./scripts/server/server-install.sh
```

This time it runs `server-deploy.sh` which:

1. Runs the configured `SSH_BACKUP_COMMAND` and stops unless the database and uploads backup succeeds.
2. Runs `composer install --no-dev --optimize-autoloader`.
3. Consolidates `.env.prod.local` into `.env.local.php`.
4. Builds and installs the frontend while retaining the previous asset bundle for rollback.
5. Runs Doctrine migrations, Dropbox token encryption, and the file-size backfill.
6. Clears and warms the production cache.
7. Fixes ownership on `backend/var/` and `backend/public/uploads/`.

When it finishes, the installer prints the next-step checklist (web server
config, certbot, first admin).

### 4.7 Configure the web server

See [§6 Web server configuration](#6-web-server-configuration).

### 4.8 Get an SSL certificate

```sh
sudo certbot --nginx -d comics.yourdomain.com           # if nginx
sudo certbot --apache -d comics.yourdomain.com          # if apache
```

### 4.9 Create the first admin user

The project ships an `app:create-admin-user` command (see `upgrade.md` Task
3.1 — verify it exists in your branch first). If it does:

```sh
cd /var/www/comics/backend
php bin/console app:create-admin-user --env=prod
```

If not yet, create one manually:

```sh
sudo mysql cbz_reader -e "
  INSERT INTO user (id, email, roles, password, is_email_verified, created_at)
  VALUES (
    UUID(),
    'admin@yourdomain.com',
    '[\"ROLE_ADMIN\"]',
    'TEMP_HASH',
    1,
    NOW()
  );
"
# Then use the forgot-password flow to set a real password.
```

---

## 5. The standard deploy workflow

After all the above, every release from your laptop is a single command:

```sh
./scripts/deploy-ssh.sh
```

### What that does

1. Reads `scripts/.env.deploy` for `SSH_*` values.
2. SSHes into `$SSH_USER@$SSH_HOST` once (single connection, one transcript).
3. On the server, in one shell:
   - `cd $SSH_REMOTE_PATH`
   - `git fetch --all --prune`
   - `git checkout $SSH_GIT_BRANCH`
   - `git pull --ff-only origin $SSH_GIT_BRANCH`
   - Runs `scripts/server/server-deploy.sh` with the right env vars.
4. `server-deploy.sh` does the actual build (composer + npm + migrate + cache + chown + post-deploy hook).

### Common variations

```sh
# Backend-only redeploy (no React change → skip npm build)
./scripts/deploy-ssh.sh --skip-frontend

# Frontend-only (template/styles only, no PHP change)
./scripts/deploy-ssh.sh --skip-composer

# Deploy a feature branch ad-hoc (won't change the server's tracked branch)
./scripts/deploy-ssh.sh --branch=feature/dropbox-redux

# You already SSH'd in and ran git pull manually; now apply the build remotely
./scripts/deploy-ssh.sh --no-git

# Fast rsync mode: build locally with Docker, push the release/ tree over SSH,
# run only migrate + cache:clear remotely. Useful if the server doesn't have Node.
./scripts/deploy-ssh.sh --rsync

# Run an arbitrary remote command in the project dir (debugging escape hatch)
./scripts/deploy-ssh.sh --command="php bin/console about --env=prod"
./scripts/deploy-ssh.sh --command="tail -n 100 backend/var/log/prod-$(date +%F).log"
```

### Manually invoking the server side

Sometimes you SSH in for a fix, run `git pull` by hand, and just want to
finish the deploy without going back to your laptop. The server-side script
handles that:

```sh
ssh deploy@server.yourdomain.com
cd /var/www/comics
git pull
APP_DIR=/var/www/comics ./scripts/server/server-deploy.sh
```

You can pass the same env vars `deploy-ssh.sh` would:

```sh
APP_DIR=/var/www/comics \
WEB_USER=www-data \
SKIP_FRONTEND=1 \
POST_DEPLOY_HOOK="sudo systemctl reload php8.2-fpm" \
./scripts/server/server-deploy.sh
```

---

## 6. Web server configuration

You want the web root to be `$SSH_REMOTE_PATH/backend/public/` and you want
`/api/...` plus all SPA routes to fall through to `index.php`.

### 6.1 Nginx (recommended)

`/etc/nginx/sites-available/comics.yourdomain.com`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name comics.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name comics.yourdomain.com;

    root /var/www/comics/backend/public;
    index index.php index.html;

    # Comic uploads can be big.
    client_max_body_size 100M;

    ssl_certificate     /etc/letsencrypt/live/comics.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/comics.yourdomain.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    # Security headers
    add_header X-Content-Type-Options "nosniff"           always;
    add_header X-Frame-Options        "DENY"              always;
    add_header Referrer-Policy        "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    # SPA: serve index.html for any non-file, non-API path.
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API: hand to Symfony.
    location /api {
        try_files $uri /index.php$is_args$args;
    }

    # Symfony front controller — only via internal rewrite.
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        internal;
    }

    # Block any other .php in the public dir.
    location ~ \.php$ {
        return 404;
    }

    # Block direct access to user uploads — they go through Symfony.
    location /uploads/ {
        return 404;
    }

    # Cache hashed Vite assets aggressively.
    location ~* ^/assets/.+\.(js|css|woff2?|ttf|svg|png|jpg|jpeg|webp)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    error_log  /var/log/nginx/comics_error.log;
    access_log /var/log/nginx/comics_access.log;
}
```

```sh
sudo ln -s /etc/nginx/sites-available/comics.yourdomain.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 6.2 Apache (alternative)

The `scripts/deploy/htaccess.dist` shipped for the FTP flow works just as well
here. Symlink it (or copy it) into `backend/public/.htaccess`:

```sh
ln -s /var/www/comics/scripts/deploy/htaccess.dist /var/www/comics/backend/public/.htaccess
```

You also need:

```apache
<VirtualHost *:443>
    ServerName comics.yourdomain.com
    DocumentRoot /var/www/comics/backend/public
    <Directory /var/www/comics/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/comics.yourdomain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/comics.yourdomain.com/privkey.pem
</VirtualHost>
```

### 6.3 Docker on the server (optional)

If the server has Docker and you'd rather run the prod stack as containers
(matches dev exactly), commit a `docker-compose.prod.yml` based on the dev
file and replace `server-deploy.sh`'s build with:

```sh
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec -T php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

This is cleaner but out of scope for this guide. See `upgrade.md` Task 6.7
("Containerise prod") for the proposed approach.

---

## 7. Background jobs (cron / systemd timers)

### 7.1 Dropbox sync (every 2 hours)

```cron
# crontab -e for the deploy user
0 */2 * * * cd /var/www/comics/backend && php bin/console app:sync-dropbox-comics --env=prod >> /var/log/comics-dropbox.log 2>&1
```

### 7.2 Symfony Messenger consumer (if you switch from sync to async)

Systemd unit `/etc/systemd/system/comics-messenger.service`:

```ini
[Unit]
Description=Comics Messenger Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/comics/backend
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M --env=prod
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now comics-messenger
```

### 7.3 Daily log rotation

`/etc/logrotate.d/comics`:

```
/var/www/comics/backend/var/log/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
    su www-data www-data
}
```

---

## 8. Rollback

There are two rollback strategies depending on how careful you are.

### 8.1 Quick: `git revert` then redeploy

```sh
git revert HEAD~1                        # locally
git push origin main
./scripts/deploy-ssh.sh
```

If a migration was applied and you need to roll the DB back too:

```sh
./scripts/deploy-ssh.sh --command="cd backend && php bin/console doctrine:migrations:execute 'DoctrineMigrations\\\\VersionXXXXX' --down --no-interaction --env=prod"
```

### 8.2 Atomic deploys with the symlink trick (advanced, optional)

If downtime during the build matters (it usually doesn't for this app), use a
release-symlink layout:

```
/var/www/comics/
├── current → releases/2026-05-07_21-30-00/    # symlink served by nginx
├── releases/
│   ├── 2026-05-06_18-00-00/
│   ├── 2026-05-07_21-30-00/
│   └── ...
└── shared/
    ├── .env.prod.local                        # symlinked into each release
    ├── uploads/                               # symlinked into each release/public/
    └── var/log/                               # symlinked into each release/var/log/
```

Roll back by repointing the symlink:

```sh
ln -sfn /var/www/comics/releases/2026-05-06_18-00-00 /var/www/comics/current
sudo systemctl reload php8.2-fpm
```

This is a meaningful refactor of the deploy scripts. Add it as a task in
`upgrade.md` if you actually need it.

---

## 9. GitHub Actions automation

You can wire `deploy-ssh.sh` into CI so every push to `main` deploys
automatically. Replace `.github/workflows/build-frontend.yml` with:

```yaml
name: Deploy via SSH
on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up SSH
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.SSH_PRIVATE_KEY }}

      - name: Add server to known_hosts
        run: ssh-keyscan -p ${{ secrets.SSH_PORT }} ${{ secrets.SSH_HOST }} >> ~/.ssh/known_hosts

      - name: Write .env.deploy
        run: |
          cat > scripts/.env.deploy <<EOF
          SSH_HOST=${{ secrets.SSH_HOST }}
          SSH_USER=${{ secrets.SSH_USER }}
          SSH_PORT=${{ secrets.SSH_PORT }}
          SSH_REMOTE_PATH=${{ secrets.SSH_REMOTE_PATH }}
          SSH_GIT_BRANCH=main
          SSH_WEB_USER=www-data
          SSH_POST_DEPLOY_HOOK=${{ secrets.SSH_POST_DEPLOY_HOOK }}
          SSH_BACKUP_COMMAND=${{ secrets.SSH_BACKUP_COMMAND }}
          EOF
          chmod 600 scripts/.env.deploy

      - name: Deploy
        run: ./scripts/deploy-ssh.sh
```

Required secrets: `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_USER`, `SSH_PORT`,
`SSH_REMOTE_PATH`, `SSH_POST_DEPLOY_HOOK`, `SSH_BACKUP_COMMAND`.

---

## 10. Troubleshooting

### `Permission denied (publickey)`

Your SSH key isn't authorized on the server. Verify with:

```sh
ssh -v -p $SSH_PORT $SSH_USER@$SSH_HOST
```

If using a custom key, set `SSH_KEY=` in `.env.deploy` to its path.

### `git pull` on the server says "permission denied (publickey)" for github.com

The server can't pull. Add a deploy key (see [§2 Outbound git access](#2-prerequisites-on-the-server)) and confirm with:

```sh
./scripts/deploy-ssh.sh --command="ssh -T git@github.com"
```

### `composer install` fails with "your php version (X) does not satisfy …"

The server's PHP CLI is older than 8.2. Install a matching version:

```sh
sudo apt install php8.2-cli php8.2-fpm php8.2-{mysql,intl,mbstring,zip,xsl,gd,opcache,curl}
sudo update-alternatives --set php /usr/bin/php8.2
```

Per project rule, the server's PHP version MUST match the Docker version
(`PHP_VERSION` in the root `.env`).

### `cache:clear` fails with "permission denied" on `var/cache/prod/`

The previous prod cache was created by a different user. Fix once with:

```sh
./scripts/deploy-ssh.sh --command="sudo rm -rf backend/var/cache/* && sudo chown -R deploy:www-data backend/var"
```

`server-deploy.sh` re-applies the right ownership at the end of every deploy.

### `npm run build` runs out of memory

Common on small VPSes (< 1 GB RAM). Either:
- Add a swapfile: `sudo fallocate -l 1G /swap && sudo chmod 600 /swap && sudo mkswap /swap && sudo swapon /swap`
- Or build locally and use `--rsync` mode:
  `./scripts/deploy-ssh.sh --rsync` (skips the npm build on the server).

### Migrations leave the schema in an inconsistent state

```sh
./scripts/deploy-ssh.sh --command="cd backend && php bin/console doctrine:migrations:status --env=prod"
./scripts/deploy-ssh.sh --command="cd backend && php bin/console doctrine:migrations:list --env=prod"
```

If a version is "executed but not registered":
```sh
./scripts/deploy-ssh.sh --command="cd backend && php bin/console doctrine:migrations:version 'DoctrineMigrations\\\\VersionXXXX' --add --no-interaction --env=prod"
```

### `502 Bad Gateway` after a deploy

PHP-FPM is using a stale opcache. Make sure your `SSH_POST_DEPLOY_HOOK`
includes a reload:

```dotenv
SSH_POST_DEPLOY_HOOK="sudo systemctl reload php8.2-fpm"
```

Or, if you can't sudo from the deploy user, set `opcache.validate_timestamps=1`
in `php.ini` so PHP picks up changes automatically (slightly slower runtime).

### "Mixed content" warnings in the browser after enabling HTTPS

Production uses HTTPS but `FRONTEND_URL` in `backend/.env.prod.local` still
says `http://`. Update it and redeploy.

### React app shows a blank page

Check the browser console:
- `Failed to load /assets/index-XXXX.js` → frontend was built but `assets/`
  didn't make it to `backend/public/`. Re-run with
  `./scripts/deploy-ssh.sh --skip-composer`.
- White page, no errors → `index.html` is missing. Same fix.

### "uploads/ disappeared after deploy"

Both `server-deploy.sh` and `deploy-ssh.sh --rsync` explicitly preserve
`backend/public/uploads/`. If they really vanished, you most likely ran
something like `rm -rf backend/public/*` manually. Restore from your DB
backup + the server's daily snapshot.

---

## 11. Security notes

1. **`backend/.env.prod.local` lives only on the server.** Never commit it,
   never copy it back to your laptop. If you need to read a value, SSH in
   and `cat` it.
2. **`scripts/.env.deploy` (laptop side) only stores the SSH credentials**
   under this flow — no DB password, no `APP_SECRET`. Smaller blast radius
   than the FTP flow.
3. **Use a deploy-only OS user** (`deploy`) with no shell login for non-key
   auth (`PasswordAuthentication no` in `/etc/ssh/sshd_config`).
4. **Restrict the deploy key** with `from="ip,ip"` in
   `~/.ssh/authorized_keys` to your office IPs and your CI runners.
5. **Disable the `_post-deploy.php` endpoint** when using SSH — it's
   redundant and another attack surface. `server-deploy.sh` does NOT install
   it; only the FTP build does.
6. **Watch `~/.bash_history`** if your deploy commands include secrets — they
   shouldn't, but if you ever paste a token, scrub it:
   ```sh
   ./scripts/deploy-ssh.sh --command="history -c && rm -f ~/.bash_history"
   ```
7. **Audit `git log` on the server** periodically:
   ```sh
   ./scripts/deploy-ssh.sh --command="git log --oneline -20 && git status"
   ```
   If `git status` shows uncommitted changes, someone edited files directly on
   the server. That's a forensic event — investigate.
8. **Backups**: this guide deploys code; it does NOT back up the database or
   `backend/public/uploads/`. Set up daily DB dumps and an off-site sync of
   the uploads dir. Suggestion:
   ```sh
   # /etc/cron.daily/comics-backup
   #!/bin/sh
   DAY=$(date +%F)
   mysqldump --single-transaction cbz_reader | gzip > /var/backups/comics/$DAY.sql.gz
   rsync -a /var/www/comics/backend/public/uploads/ /var/backups/comics/uploads/
   find /var/backups/comics -name '*.sql.gz' -mtime +30 -delete
   ```

---

## 12. Reference: file inventory

| File                                       | Role                                                           |
| ------------------------------------------ | -------------------------------------------------------------- |
| `scripts/.env.deploy.example`              | Template for both FTP and SSH credentials.                     |
| `scripts/.env.deploy`                      | Your real credentials (gitignored). For SSH you only need the `SSH_*` block. |
| `scripts/deploy-ssh.sh`                    | Laptop-side driver. SSHes in, runs `git pull`, calls `server-deploy.sh`. |
| `scripts/server/server-install.sh`         | One-time installer run on the server. Bootstraps the env file then runs `server-deploy.sh`. |
| `scripts/server/server-deploy.sh`          | Server-side build: composer + npm + migrate + cache + chown + hook. |
| `scripts/post-deploy.sh`                   | Optional: same actions over HTTP/SSH for the FTP flow. SSH mode reuses the same `SSH_*` vars. |
| `backend/.env.prod.local` *(server-only)*  | Holds prod secrets. NEVER committed, NEVER on the laptop.       |
| `backend/.env.local.php` *(server-only)*   | Generated by `composer dump-env prod` from `.env.prod.local`.   |

---

## Quick reference card

```
# Initial setup (laptop)
cp scripts/.env.deploy.example scripts/.env.deploy && chmod 600 scripts/.env.deploy
$EDITOR scripts/.env.deploy            # fill in SSH_* block

# Initial setup (server)
ssh deploy@server.yourdomain.com
sudo mkdir -p /var/www/comics && sudo chown $USER:$USER /var/www/comics
git clone git@github.com:youruser/cbz-reader.git /var/www/comics
cd /var/www/comics
APP_DIR=/var/www/comics ./scripts/server/server-install.sh   # writes env template
$EDITOR backend/.env.prod.local
APP_DIR=/var/www/comics ./scripts/server/server-install.sh   # actually builds

# Standard release (laptop)
git push origin main
./scripts/deploy-ssh.sh

# Hotfixes
./scripts/deploy-ssh.sh --skip-frontend     # backend/PHP only
./scripts/deploy-ssh.sh --skip-composer     # frontend only

# Server has no Node
./scripts/deploy-ssh.sh --rsync             # build locally, ship dist over SSH

# Debug helpers
./scripts/deploy-ssh.sh --command="php bin/console about --env=prod"
./scripts/deploy-ssh.sh --command="tail -n 200 backend/var/log/prod-$(date +%F).log"
./scripts/deploy-ssh.sh --no-git            # apply build without pulling new commits

# Roll back
git revert HEAD && git push && ./scripts/deploy-ssh.sh
```

Pair this with `deploy.md` (FTP flow) — the two paths are complementary, and
the same `scripts/.env.deploy` powers both.
