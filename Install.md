# Cloud Portal — Installation & Deployment Guide

This document outlines the system requirements, manual setup steps, and web-installer instructions for deploying **Cloud Portal**.

---

## 1. System Requirements

- **PHP:** Version 7.4.0 or higher (PHP 8.0, 8.1, 8.2, 8.3, and 8.4 fully tested).
- **PHP Extensions:**
  - `pdo_mysql` (Database connectivity)
  - `openssl` (HMAC-SHA256 signatures and secure tokens)
  - `curl` (Stripe and Registrar API requests)
  - `json` (REST API and transient data)
  - `mbstring` / `fileinfo` (File uploads and string encoding)
- **Database:** MySQL 5.7+ or MariaDB 10.3+.
- **Web Server:** Apache (with `mod_rewrite` enabled for `.htaccess` routing) or Nginx.

---

## 2. Installation Options

### Option A: 1-Click Browser Installer (Recommended)

1. Upload the repository files to your web server document root (e.g. `public_html`).
2. Ensure the `config/` directory and `assets/uploads/` directory are writable by the web server (permissions `755` or `775`).
3. Open your browser and navigate to:
   ```text
   https://your-domain.com/install.php
   ```
4. Enter your MySQL database connection details:
   - **Database Host:** (usually `localhost`)
   - **Database Port:** (usually `3306`)
   - **Database Name, Username & Password**
5. Configure your portal branding and primary administrator credentials.
6. Click **Install Database Tables & Launch Portal**.

---

### Option B: Manual CLI / phpMyAdmin Setup

1. **Import the SQL Baseline Schema:**
   Import `schema.sql` (or `database/schema.sql`) into your MySQL/MariaDB database:
   ```bash
   mysql -u your_user -p your_database < schema.sql
   ```

2. **Configure Database Settings:**
   Copy `config/database.sample.php` to `config/database.php` and fill in your connection details:
   ```php
   define('SCP_DB_HOST', 'localhost');
   define('SCP_DB_PORT', '3306');
   define('SCP_DB_NAME', 'your_database');
   define('SCP_DB_USER', 'your_user');
   define('SCP_DB_PASS', 'your_password');
   define('SCP_DB_CHARSET', 'utf8mb4');
   define('SCP_SITE_URL', 'https://your-domain.com');
   ```

3. **Configure API Credentials:**
   Copy `config/credentials.sample.php` to `config/credentials.php` and configure your Stripe, OpenSRS, and Namecheap keys.

4. **Configure Email Settings:**
   Copy `config/mail.sample.php` to `config/mail.php` with your SMTP and IMAP host settings.

---

## 3. Post-Installation Checklist

1. **Stripe Webhook:**
   - In your Stripe Dashboard, add endpoint `https://your-domain.com/api/stripe-webhook`.
   - Add the signing secret `whsec_...` into `config/credentials.php` or Admin &rarr; **API & Portal Settings**.
2. **File Permissions:**
   - Lock down `config/` permissions (`chmod 750 config/` or `chmod 640 config/*.php`).
   - `config/.htaccess` automatically blocks direct HTTP access to configuration files.
3. **Remove Installer:**
   - For maximum security in production, delete or rename `install.php`.
