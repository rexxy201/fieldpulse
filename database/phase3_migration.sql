-- Phase 3: Inventory Zoho Alignment
-- Safe to run multiple times (IF NOT EXISTS / column existence guards via ALTER IGNORE)
-- Run after schema.sql, noc_migration.sql, phase2_migration.sql

SET foreign_key_checks = 0;

-- ── 1. Extend inv_items with Zoho-aligned fields ──────────────────────────────
ALTER TABLE `inv_items`
  ADD COLUMN IF NOT EXISTS `sku`            VARCHAR(100)                                    DEFAULT NULL  AFTER `unique_code`,
  ADD COLUMN IF NOT EXISTS `item_type`      ENUM('inventory','service','non_inventory')     NOT NULL DEFAULT 'inventory' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `unit`           VARCHAR(30)                                     DEFAULT 'Pcs' AFTER `item_type`,
  ADD COLUMN IF NOT EXISTS `purchase_price` DECIMAL(10,2)                                   DEFAULT NULL  AFTER `unit`,
  ADD COLUMN IF NOT EXISTS `selling_price`  DECIMAL(10,2)                                   DEFAULT NULL  AFTER `purchase_price`,
  ADD COLUMN IF NOT EXISTS `vendor_id`      VARCHAR(36)                                     DEFAULT NULL  AFTER `selling_price`,
  ADD COLUMN IF NOT EXISTS `reorder_qty`    INT UNSIGNED                                    DEFAULT 1     AFTER `reorder_threshold`,
  ADD COLUMN IF NOT EXISTS `zoho_item_id`   VARCHAR(100)                                    DEFAULT NULL  AFTER `reorder_qty`,
  ADD COLUMN IF NOT EXISTS `zoho_synced_at` DATETIME                                        DEFAULT NULL  AFTER `zoho_item_id`;

-- Back-fill SKU from unique_code where sku is still NULL
UPDATE `inv_items` SET `sku` = `unique_code` WHERE `sku` IS NULL AND `unique_code` IS NOT NULL;

-- ── 2. Purchase Orders ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inv_purchase_orders` (
  `id`           VARCHAR(36)  NOT NULL,
  `po_number`    VARCHAR(30)  NOT NULL,
  `vendor_id`    VARCHAR(36)  DEFAULT NULL,
  `status`       ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  `ordered_at`   DATE         DEFAULT NULL,
  `expected_at`  DATE         DEFAULT NULL,
  `received_at`  DATETIME     DEFAULT NULL,
  `notes`        TEXT         DEFAULT NULL,
  `created_by`   VARCHAR(36)  DEFAULT NULL,
  `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_number` (`po_number`),
  INDEX `idx_po_status`    (`status`),
  INDEX `idx_po_vendor`    (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_po_items` (
  `id`           INT UNSIGNED AUTO_INCREMENT NOT NULL,
  `po_id`        VARCHAR(36)  NOT NULL,
  `item_id`      INT          NOT NULL,
  `qty_ordered`  INT UNSIGNED NOT NULL DEFAULT 1,
  `qty_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_price`   DECIMAL(10,2)          DEFAULT NULL,
  `notes`        TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_poi_po`   (`po_id`),
  INDEX `idx_poi_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
