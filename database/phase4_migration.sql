-- Phase 4: Customer Billing Engine
-- Run AFTER phase3_migration.sql
-- All statements use IF NOT EXISTS / IF EXISTS guards — safe to re-run.

-- ── Invoices ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`             VARCHAR(36)   NOT NULL,
  `invoice_number` VARCHAR(30)   NOT NULL,
  `customer_id`    VARCHAR(36)   DEFAULT NULL,
  `customer_name`  VARCHAR(150)  DEFAULT NULL,  -- snapshot at creation
  `customer_email` VARCHAR(200)  DEFAULT NULL,
  `customer_phone` VARCHAR(60)   DEFAULT NULL,
  `customer_addr`  TEXT          DEFAULT NULL,
  `status`         ENUM('draft','sent','paid','partial','overdue','void','cancelled')
                   NOT NULL DEFAULT 'draft',
  `issue_date`     DATE          DEFAULT NULL,
  `due_date`       DATE          DEFAULT NULL,
  `paid_at`        DATETIME      DEFAULT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,  -- percentage, e.g. 7.5
  `tax_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_due`    DECIMAL(12,2) GENERATED ALWAYS AS (`total` - `amount_paid`) VIRTUAL,
  `notes`          TEXT          DEFAULT NULL,
  `terms`          TEXT          DEFAULT NULL,
  `created_by`     VARCHAR(36)   DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_status`   (`status`),
  KEY `idx_invoices_due`      (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Invoice line items ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT UNSIGNED   AUTO_INCREMENT NOT NULL,
  `invoice_id`  VARCHAR(36)    NOT NULL,
  `description` VARCHAR(255)   NOT NULL,
  `qty`         DECIMAL(10,3)  NOT NULL DEFAULT 1.000,
  `unit_price`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `line_total`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `sort_order`  TINYINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_ii_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Invoice payments ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoice_payments` (
  `id`          INT UNSIGNED   AUTO_INCREMENT NOT NULL,
  `invoice_id`  VARCHAR(36)    NOT NULL,
  `amount`      DECIMAL(12,2)  NOT NULL,
  `method`      ENUM('cash','bank_transfer','mobile_money','cheque','card','other')
                NOT NULL DEFAULT 'cash',
  `reference`   VARCHAR(100)   DEFAULT NULL,
  `paid_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes`       TEXT           DEFAULT NULL,
  `recorded_by` VARCHAR(36)    DEFAULT NULL,
  `created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ip_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mark overdue invoices automatically (called by cron or on page load) ──────
-- A trigger alternative: the poller/page will update status in PHP.
-- No trigger needed — PHP handles overdue detection on list load.
