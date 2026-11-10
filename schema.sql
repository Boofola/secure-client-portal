-- =====================================================================
-- Cloud Portal Baseline Database Schema
-- Compatible with MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------
-- 1. Users Table (Clients & Administrators)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('client', 'admin') NOT NULL DEFAULT 'client',
  `first_name` VARCHAR(100) DEFAULT '',
  `last_name` VARCHAR(100) DEFAULT '',
  `company` VARCHAR(191) DEFAULT '',
  `phone` VARCHAR(50) DEFAULT '',
  `address_1` VARCHAR(255) DEFAULT '',
  `address_2` VARCHAR(255) DEFAULT '',
  `city` VARCHAR(100) DEFAULT '',
  `state` VARCHAR(100) DEFAULT '',
  `postcode` VARCHAR(30) DEFAULT '',
  `country` VARCHAR(10) DEFAULT 'US',
  `stripe_customer_id` VARCHAR(120) DEFAULT '',
  `stripe_last_synced` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_stripe_customer` (`stripe_customer_id`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Key-Value Portal Settings Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_settings` (
  `setting_key` VARCHAR(120) PRIMARY KEY,
  `setting_value` LONGTEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Client Websites, Hosted Platforms & SSO Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_client_websites` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `platform_type` VARCHAR(64) NOT NULL DEFAULT 'wordpress',
  `site_name` VARCHAR(191) NOT NULL,
  `site_url` VARCHAR(255) NOT NULL,
  `login_url` VARCHAR(255) DEFAULT '',
  `enable_auto_login` TINYINT(1) NOT NULL DEFAULT 0,
  `sso_secret` VARCHAR(128) NOT NULL,
  `wp_admin_email` VARCHAR(191) DEFAULT '',
  `hosting_plan` VARCHAR(100) DEFAULT 'Managed Cloud WordPress',
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `notes` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_platform_type` (`platform_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Client Registered Domains Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_client_domains` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `domain_name` VARCHAR(191) NOT NULL,
  `registrar` VARCHAR(50) NOT NULL DEFAULT 'namecheap',
  `order_id` VARCHAR(100) DEFAULT '',
  `registration_date` DATE DEFAULT NULL,
  `expiration_date` DATE DEFAULT NULL,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `nameservers` TEXT,
  `status` VARCHAR(40) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_user_domain` (`user_id`, `domain_name`),
  INDEX `idx_domain_name` (`domain_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Public Contact Inquiries Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_contact_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(50) DEFAULT '',
  `department` VARCHAR(80) DEFAULT 'General Inquiry',
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('unread', 'read', 'replied') NOT NULL DEFAULT 'unread',
  `ip_address` VARCHAR(45) DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Security & Activity Audit Log Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_activity_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_event` (`user_id`, `event_type`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Rate Limits & Idempotency Transients Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_rate_limits` (
  `rate_key` VARCHAR(128) PRIMARY KEY,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` INT UNSIGNED NOT NULL,
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Imported Stripe Products & Prices Mirror Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_stripe_products` (
  `price_id` VARCHAR(120) PRIMARY KEY,
  `product_id` VARCHAR(120) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `unit_amount` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `interval_unit` VARCHAR(30) NOT NULL DEFAULT 'one_time',
  `interval_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `portal_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Product Categories Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_product_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `description` TEXT,
  `sort_order` INT NOT NULL DEFAULT 0,
  `bottom_note_text` TEXT DEFAULT NULL,
  `bottom_note_title` VARCHAR(255) DEFAULT NULL,
  `bottom_note_icon` VARCHAR(50) NOT NULL DEFAULT 'green-exclamation',
  `bottom_notes_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Product-to-Category Many-to-Many Mapping Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_product_category_map` (
  `price_id` VARCHAR(120) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`price_id`, `category_id`),
  INDEX `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. Custom Multi-Term Catalog Products Table (v3.0.0+)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_catalog_products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stripe_product_id` VARCHAR(120) DEFAULT '',
  `name` VARCHAR(255) NOT NULL,
  `super_title` VARCHAR(191) DEFAULT '',
  `description` TEXT,
  `promo_callout` VARCHAR(255) DEFAULT '',
  `cta_label` VARCHAR(100) NOT NULL DEFAULT 'Choose Plan',
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `has_terms` TINYINT(1) NOT NULL DEFAULT 0,
  `billing_mode` VARCHAR(30) NOT NULL DEFAULT 'recurring',
  `price_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_unit` VARCHAR(50) NOT NULL DEFAULT '/mo',
  `term_length_label` VARCHAR(150) DEFAULT '',
  `renews_at_label` VARCHAR(150) DEFAULT '',
  `save_badge_text` VARCHAR(80) DEFAULT '',
  `stripe_hook` VARCHAR(500) DEFAULT '',
  `terms_json` LONGTEXT,
  `use_feature_headings` TINYINT(1) NOT NULL DEFAULT 1,
  `features_json` LONGTEXT,
  `portal_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 10,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_visible_sort` (`portal_visible`, `sort_order`),
  INDEX `idx_stripe_product_id` (`stripe_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. Catalog Product-to-Category Mapping Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_catalog_product_categories` (
  `product_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`product_id`, `category_id`),
  INDEX `idx_cat_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. Imported Stripe Subscriptions Mirror Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_stripe_subscriptions` (
  `subscription_id` VARCHAR(120) PRIMARY KEY,
  `customer_id` VARCHAR(120) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `plan_name` VARCHAR(255) NOT NULL,
  `price_id` VARCHAR(120) DEFAULT '',
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `interval_unit` VARCHAR(30) NOT NULL DEFAULT 'month',
  `status` VARCHAR(40) NOT NULL DEFAULT 'active',
  `current_period_end` INT UNSIGNED DEFAULT NULL,
  `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0,
  `created_ts` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. Imported Stripe Invoices Mirror Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_stripe_invoices` (
  `invoice_id` VARCHAR(120) PRIMARY KEY,
  `invoice_number` VARCHAR(120) DEFAULT '',
  `customer_id` VARCHAR(120) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `customer_email` VARCHAR(191) DEFAULT '',
  `amount_due` BIGINT NOT NULL DEFAULT 0,
  `amount_paid` BIGINT NOT NULL DEFAULT 0,
  `total_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` VARCHAR(40) NOT NULL DEFAULT 'draft',
  `hosted_invoice_url` TEXT,
  `invoice_pdf` TEXT,
  `created_ts` INT UNSIGNED DEFAULT NULL,
  `due_date` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. Imported Stripe Payments / Charges Mirror Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_stripe_payments` (
  `charge_id` VARCHAR(120) PRIMARY KEY,
  `customer_id` VARCHAR(120) DEFAULT '',
  `user_id` INT UNSIGNED DEFAULT NULL,
  `customer_email` VARCHAR(191) DEFAULT '',
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` VARCHAR(40) NOT NULL DEFAULT 'succeeded',
  `paid` TINYINT(1) NOT NULL DEFAULT 1,
  `refunded` TINYINT(1) NOT NULL DEFAULT 0,
  `description` TEXT,
  `receipt_url` TEXT,
  `card_brand` VARCHAR(40) DEFAULT '',
  `card_last4` VARCHAR(10) DEFAULT '',
  `created_ts` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. Imported Stripe Quotes Mirror Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_stripe_quotes` (
  `quote_id` VARCHAR(120) PRIMARY KEY,
  `quote_number` VARCHAR(120) DEFAULT '',
  `customer_id` VARCHAR(120) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `description` TEXT,
  `amount_total` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` VARCHAR(40) NOT NULL DEFAULT 'open',
  `hosted_quote_url` TEXT,
  `invoice_id` VARCHAR(120) DEFAULT '',
  `created_ts` INT UNSIGNED DEFAULT NULL,
  `expires_at` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. Client & Admin Email Messages Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_email_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `inquiry_id` INT UNSIGNED DEFAULT NULL,
  `folder` VARCHAR(32) NOT NULL DEFAULT 'inbox',
  `direction` ENUM('inbound', 'outbound') NOT NULL DEFAULT 'inbound',
  `imap_uid` INT UNSIGNED DEFAULT NULL,
  `imap_folder` VARCHAR(64) DEFAULT 'INBOX',
  `message_id` VARCHAR(255) DEFAULT '',
  `in_reply_to` VARCHAR(255) DEFAULT '',
  `from_email` VARCHAR(191) NOT NULL,
  `from_name` VARCHAR(191) DEFAULT '',
  `to_email` VARCHAR(191) NOT NULL,
  `to_name` VARCHAR(191) DEFAULT '',
  `attention_name` VARCHAR(191) DEFAULT '',
  `subject` VARCHAR(500) NOT NULL DEFAULT '',
  `snippet` VARCHAR(500) DEFAULT '',
  `body_text` LONGTEXT,
  `body_html` LONGTEXT,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `is_starred` TINYINT(1) NOT NULL DEFAULT 0,
  `replied_at` DATETIME DEFAULT NULL,
  `email_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_folder_date` (`folder`, `email_date`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_from_email` (`from_email`),
  INDEX `idx_to_email` (`to_email`),
  INDEX `idx_imap_uid` (`imap_folder`, `imap_uid`),
  INDEX `idx_message_id` (`message_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. Deleted IMAP UIDs Tombstone Table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scp_email_deleted_uids` (
  `imap_folder` VARCHAR(64) NOT NULL,
  `imap_uid` INT UNSIGNED NOT NULL,
  `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`imap_folder`, `imap_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed Default Portal Settings
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `scp_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Cloud Portal'),
('site_title', 'Enterprise Cloud Hosting, Domains & Client Portal'),
('site_logo_url', ''),
('support_email', 'support@example.com'),
('support_phone', '+1 (800) 555-0199'),
('stripe_mode', 'test'),
('stripe_currency', 'usd'),
('stripe_webhook_secret', ''),
('active_registrar', 'namecheap'),
('nc_enabled', 'yes'),
('os_fallback_enabled', 'yes'),
('price_markup_percent', '20'),
('default_nameservers', ''),
('nc_sandbox', 'yes'),
('nc_client_ip', '127.0.0.1'),
('os_environment', 'test'),
('mail_email', ''),
('mail_from_name', 'Cloud Portal'),
('mail_imap_host', ''),
('mail_imap_port', '993'),
('mail_pop_host', ''),
('mail_pop_port', '995'),
('mail_smtp_host', ''),
('mail_smtp_port', '465'),
('mail_encryption', 'ssl');

SET FOREIGN_KEY_CHECKS = 1;
