-- Phase 5: Customer Portal, Recurring Billing, SMS/WhatsApp, Paystack
-- Run in phpMyAdmin on the live server before deploying Phase 5 code.

-- ── Billing plans ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `billing_plans` (
    `id`              CHAR(36)       NOT NULL PRIMARY KEY,
    `name`            VARCHAR(120)   NOT NULL,
    `description`     TEXT,
    `amount`          DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `billing_cycle`   ENUM('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
    `billing_day`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month to generate invoice (1–28)',
    `tax_rate`        DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    `is_active`       TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Add source tracking to invoices ─────────────────────────────────────────
ALTER TABLE `invoices`
    ADD COLUMN IF NOT EXISTS `source` ENUM('manual','recurring','paystack') NOT NULL DEFAULT 'manual'
        COMMENT 'How the invoice was created' AFTER `terms`;

-- ── Link customers to billing plans ──────────────────────────────────────────
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `billing_plan_id`    CHAR(36)  DEFAULT NULL AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `next_invoice_date`  DATE      DEFAULT NULL AFTER `billing_plan_id`,
    ADD COLUMN IF NOT EXISTS `billing_active`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `next_invoice_date`;

-- ── Portal access tokens (magic-link style, no password needed) ───────────────
CREATE TABLE IF NOT EXISTS `portal_tokens` (
    `id`          CHAR(36)     NOT NULL PRIMARY KEY,
    `customer_id` CHAR(36)     NOT NULL,
    `token_hash`  VARCHAR(128) NOT NULL UNIQUE,
    `expires_at`  DATETIME     NOT NULL,
    `used_at`     DATETIME     DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SMS log ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_log` (
    `id`           CHAR(36)     NOT NULL PRIMARY KEY,
    `recipient`    VARCHAR(30)  NOT NULL,
    `message`      TEXT         NOT NULL,
    `channel`      ENUM('sms','whatsapp') NOT NULL DEFAULT 'sms',
    `provider`     VARCHAR(40)  NOT NULL DEFAULT '',
    `status`       ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    `provider_ref` VARCHAR(120) DEFAULT NULL,
    `error`        TEXT         DEFAULT NULL,
    `sent_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
