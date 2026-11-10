# Cloud Portal — Open Source Client & Billing Platform

[![Version](https://img.shields.io/badge/version-3.5.0-blue.svg)](bootstrap.php)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D7.4%20%7C%208.x-purple.svg)](https://php.net)
[![Database](https://img.shields.io/badge/database-MySQL%20%7C%20MariaDB-orange.svg)](schema.sql)

**Cloud Portal** is an open-source, lightweight, high-performance stand-alone hosting, domain management, client portal, and Stripe billing platform written in clean modern PHP and JavaScript. It provides unified customer management, multi-term product catalogs, registrar automation, and remote WordPress single sign-on (SSO) with zero WordPress core dependency or heavy framework overhead.

---

## Key Features

### 1. Dynamic Branding & Customization
- **Admin Logo Management:** Upload and preview custom SVG, PNG, WebP, or JPG logos directly from the Admin Control Center, with 1-click reset to default.
- **Dynamic Site Title & Company Identity:** Configure your brand name, page titles, and dynamic taglines across public website views, email notifications, and portal headers.
- **Modern Responsive Design:** Clean UI built with semantic HTML5, CSS custom properties, and accessible components.

### 2. Multi-Term Catalog & Stripe Billing Automation
- **Stripe Integration:** Full support for Stripe Checkout, Customer Portal, Subscriptions, One-Time Invoices, and Quotes.
- **Multi-Term Product Catalog:** Custom billing cycles (monthly, 1-year, 2-year, 3-year), promotion callouts, feature highlights, and 'New' badges.
- **Automated Webhooks:** Secure, idempotent HMAC-SHA256 signature verification for instant payment processing and invoice reconciliation.

### 3. Real-Time Domain Registration & Registrar Failover
- **Dual-Registrar Architecture:** Primary registration and renewals with **Namecheap** and automatic failover to **OpenSRS (Tucows)**.
- **Live WHOIS / Availability Search:** Real-time lookup across popular TLDs (`.com`, `.net`, `.org`, `.cloud`, `.io`, `.co`).
- **Nameserver Management:** Update custom DNS nameservers directly from the client portal.

### 4. Managed WordPress & One-Click SSO
- **Single Sign-On (SSO):** Launch secure, single-use 60-second HMAC-SHA256 authenticated sessions into client WordPress admin dashboards without sharing passwords.
- **Companion MU-Plugin:** Automatically generated drop-in companion plugin (`client-companion/secure-cloud-portal-client-sso.php`) for client WordPress websites.

### 5. Built-In Customer Communication & Email Center
- **IMAP / SMTP Integration:** Send branded client notifications, reply to incoming inquiries, and manage folder synchronization.
- **Contact Inquiry Inbox:** Public contact form with rate-limiting and Google reCAPTCHA Enterprise protection.

---

## Directory Structure

```text
├── api.php                     # Stand-alone AJAX & Stripe Webhook endpoint
├── bootstrap.php               # Core application initializer & constant definitions
├── index.php                   # Front controller & page router
├── install.php                 # Web-based 1-click database & admin installer
├── schema.sql                  # Baseline MySQL/MariaDB database schema
├── app/                        # Core backend PHP service classes
│   ├── Auth.php                # Authentication, sessions & user management
│   ├── Database.php            # PDO singleton, schema installer & settings manager
│   ├── DomainManager.php       # Domain lookup, pricing & provisioning orchestrator
│   ├── EmailService.php        # IMAP, POP3, SMTP socket engine & template renderer
│   ├── HttpClient.php          # Resilient cURL HTTP client
│   ├── NamecheapService.php    # Namecheap XML API integration
│   ├── OpenSRSService.php      # OpenSRS (Tucows) XML API integration
│   ├── Security.php            # CSRF, rate-limiting, headers & sanitization
│   ├── StripeService.php       # Stripe API, Checkout, Invoices & Webhooks
│   └── WebsiteManager.php      # Client website management & SSO tokens
├── assets/                     # Frontend stylesheets, JavaScript & vector assets
│   ├── css/app.css             # Main application stylesheet
│   ├── js/app.js               # Interactive frontend & admin JavaScript
│   ├── logo.svg                # Default vector brand logo
│   └── uploads/                # Secure custom uploaded logos (.htaccess protected)
├── client-companion/           # WordPress companion single sign-on drop-in plugin
├── config/                     # Configuration directory (.htaccess protected)
│   ├── credentials.sample.php  # Sample API credentials (Stripe, Registrars, reCAPTCHA)
│   ├── database.sample.php     # Sample MySQL/MariaDB connection settings
│   └── mail.sample.php         # Sample IMAP/SMTP server connection settings
├── database/
│   └── schema.sql              # Database baseline SQL schema copy
└── views/                      # Application view templates
    ├── admin/dashboard.php     # Admin Control Center dashboard
    ├── emails/                 # Branded HTML email templates
    ├── partials/               # Global header & footer components
    ├── portal/dashboard.php    # Client portal interface
    └── public/                 # Public website (home, about, contact, login, policies)
```

---

## Quick Installation

### Step 1: Clone or Extract Repository
Upload the repository files to the document root (e.g., `public_html`) of your web server.

### Step 2: Run the Web Installer
Visit `/install.php` in your web browser. The installer will:
1. Run pre-flight checks (PHP >= 7.4, PDO MySQL, OpenSSL, cURL, writable directory permissions).
2. Connect to your MySQL/MariaDB database and write `config/database.php`.
3. Create all 18 database tables from `schema.sql`.
4. Configure your portal branding and create your primary administrator account.

*(Alternatively, you can manually import `schema.sql` into your database and copy `config/*.sample.php` to `config/*.php`)*.

### Step 3: Configure Stripe & API Credentials
Open `config/credentials.php` or sign in to `/admin` &rarr; **API & Portal Settings** to configure:
- **Stripe:** Publishable & Secret Keys, Webhook Signing Secret (`whsec_...`).
- **Registrars (Optional):** Namecheap and/or OpenSRS API credentials.
- **Email Server:** SMTP and IMAP host, port, and credentials.

---

## Stripe Webhook Configuration

Set your Webhook Endpoint in the **Stripe Dashboard**:
- **Endpoint URL:** `https://your-domain.com/api.php?action=stripe_webhook` (or `https://your-domain.com/api/stripe-webhook` via `.htaccess`)
- **Events to Listen For:**
  - `checkout.session.completed`
  - `invoice.paid`, `invoice.payment_failed`
  - `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`
  - `quote.accepted`
- Copy the **Signing Secret** (`whsec_...`) into Admin &rarr; **API & Portal Settings**.

---

## License

This project is licensed under the [MIT License](LICENSE).
