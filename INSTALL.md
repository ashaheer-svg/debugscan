# Installation & Deployment Guide - AI DebugScan v3

This guide details the steps to deploy the AI DebugScan v3 platform on a **Debian 12** VPS.

## 1. System Requirements
- **OS**: Debian 12 (Bookworm)
- **Web Server**: Nginx (Recommended)
- **PHP**: version 8.2 or higher
- **Database**: PostgreSQL 15 or higher
- **AI Access**: Groq API Key

### Install Essential Packages
Run the following as root/sudo:
```bash
apt update && apt upgrade -y
apt install -y nginx postgresql postgresql-contrib php8.2-fpm php8.2-pgsql php8.2-xml php8.2-mbstring php8.2-zip php8.2-curl composer
```

---

## 2. File Deployment

### 2.1 Copy Files
Deploy the following directory structure to `/var/www/ai-debugscan3`:
- `public/` (Web Root)
- `src/` (Source Code)
- `templates/` (Twig Views)
- `config/` (Settings)
- `migrations/` (Database Scripts)
- `guide/` (Reference Docs)
- `storage/` (Uploads/Extracted Data)
- `workers/` (Background Jobs)
- `composer.json`
- `.env.example`
- `setup_system.php`

### 2.2 Permissions
Ensure the web user (`www-data`) has ownership and write access to the storage directory:
```bash
chown -R www-data:www-data /var/www/ai-debugscan3
chmod -R 775 /var/www/ai-debugscan3/storage
```

---

## 3. Database & Application Setup

### 3.1 Initialize Database
Create the database and user:
```bash
sudo -u postgres psql
CREATE DATABASE aidebugscan;
CREATE USER scanuser WITH PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE aidebugscan TO scanuser;
\q
```

Execute migrations (in order):
```bash
psql -h localhost -U scanuser -d aidebugscan -f migrations/001_initial_schema.sql
psql -h localhost -U scanuser -d aidebugscan -f migrations/002_rls_policies.sql
```

### 3.2 Configure Environment
Copy the example environment file and update your credentials:
```bash
cp .env.example .env
nano .env
```
*Required updates*: `DB_PASS`, `GROQ_API_KEY`, `APP_SECRET`.

### 3.3 Install Dependencies
```bash
composer install --no-dev --optimize-autoloader
```

### 3.4 Initial Provisioning
Create the initial system administrator and demo tenant:
```bash
php setup_system.php
```

---

## 4. Web Server Configuration (Nginx)

Create a new site configuration at `/etc/nginx/sites-available/aidebugscan`:
```nginx
server {
    listen 80;
    server_name dev.activelk.com;
    root /var/www/ai-debugscan3/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
}
```
Enable the site: `ln -s /etc/nginx/sites-available/aidebugscan /etc/nginx/sites-enabled/ && systemctl restart nginx`

---

## 5. Background Scan Worker

To process diagnostic scans in the background, you must keep `workers/scan_worker.php` running. Use **Systemd** for reliability.

Create `/etc/systemd/system/scan-worker.service`:
```ini
[Unit]
Description=AI DebugScan Background Worker
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/ai-debugscan3
ExecStart=/usr/bin/php /var/www/ai-debugscan3/workers/scan_worker.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Enable the worker: `systemctl enable --now scan-worker`

---

## 6. Verification
- Navigate to `http://dev.activelk.com/login`
- Login with `admin@debugscan.ia` / `admin123`
- Ensure you can reach the Admin Settings and see available Groq models.
