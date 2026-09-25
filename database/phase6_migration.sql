-- Phase 6: Serial Number Tracking
-- Run in phpMyAdmin after phase5_migration.sql

-- ── Serial numbers for inventory items ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inv_serial_numbers` (
    `id`              CHAR(36)     NOT NULL PRIMARY KEY,
    `item_id`         INT          NOT NULL,
    `serial`          VARCHAR(100) NOT NULL,
    `batch_number`    VARCHAR(80)  DEFAULT NULL,
    `status`          ENUM('in_stock','deployed','retired') NOT NULL DEFAULT 'in_stock',
    `onu_unit_id`     CHAR(36)     DEFAULT NULL COMMENT 'Links to onu_units.id when deployed as CPE',
    `installation_id` VARCHAR(36)  DEFAULT NULL COMMENT 'Links to installation_profiles.id',
    `customer_id`     VARCHAR(36)  DEFAULT NULL COMMENT 'Links to customers.id when dispatched',
    `dispatched_by`   VARCHAR(36)  DEFAULT NULL COMMENT 'users.id who dispatched this unit',
    `dispatched_at`   DATETIME     DEFAULT NULL,
    `notes`           TEXT         DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_serial` (`serial`),
    INDEX `idx_sn_item`     (`item_id`),
    INDEX `idx_sn_status`   (`status`),
    INDEX `idx_sn_onu`      (`onu_unit_id`),
    INDEX `idx_sn_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
