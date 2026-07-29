-- ============================================================
-- FieldPulse — Incremental Migration (apply to existing DB)
-- Run this if you already have the base schema installed.
-- Safe to run multiple times (uses IF NOT EXISTS / column checks).
-- ============================================================

SET NAMES utf8mb4;

-- 1. ticket_scope column on tickets
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `ticket_scope` VARCHAR(20) NOT NULL DEFAULT 'customer';

-- If your MySQL version doesn't support ADD COLUMN IF NOT EXISTS, use:
-- ALTER TABLE `tickets` ADD COLUMN `ticket_scope` VARCHAR(20) NOT NULL DEFAULT 'customer';
-- (comment out the line above and uncomment this one if you get an error)

-- 2. hub_city_mappings table (maps city names to hubs for auto-routing)
CREATE TABLE IF NOT EXISTS `hub_city_mappings` (
  `id`         VARCHAR(36)  NOT NULL,
  `hub_id`     VARCHAR(36)  NOT NULL,
  `city_name`  VARCHAR(255) NOT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hub_city` (`hub_id`, `city_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. team_id on hubs (links each hub to a fiber team for auto-assignment)
ALTER TABLE `hubs`
  ADD COLUMN IF NOT EXISTS `team_id` VARCHAR(36) DEFAULT NULL;

-- 4. Index on ticket_scope for fast list filtering
ALTER TABLE `tickets`
  ADD INDEX IF NOT EXISTS `idx_tickets_scope` (`ticket_scope`);

-- 5. escalated_at column on tickets (drives the resolution-time report — time
--    from first escalation to resolution, falls back to created_at if never
--    explicitly escalated)
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME DEFAULT NULL;

-- 6. reports.view permission for management/supervisor roles
INSERT IGNORE INTO `role_permissions` (`id`, `role`, `permission`)
SELECT UUID(), r.name, 'reports.view'
FROM (SELECT 'project_admin' AS name UNION SELECT 'supervisor-fiber' UNION SELECT 'supervisor-noc' UNION SELECT 'cx_supervisor') r;

-- 7. Installation SLA tracking: payment_confirmed_at (SLA clock start),
--    sla_due_at (payment_confirmed_at + 7 working days), completed_at
ALTER TABLE `installation_profiles`
  ADD COLUMN IF NOT EXISTS `payment_confirmed_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_due_at`           DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `completed_at`         DATETIME DEFAULT NULL;

-- 8. installation_vendor_history table (audit trail for vendor reassignment)
CREATE TABLE IF NOT EXISTS `installation_vendor_history` (
  `id`              VARCHAR(36) NOT NULL,
  `profile_id`      VARCHAR(36) NOT NULL,
  `old_vendor_id`   VARCHAR(36) DEFAULT NULL,
  `new_vendor_id`   VARCHAR(36) DEFAULT NULL,
  `reason`          TEXT,
  `changed_by`      VARCHAR(36) DEFAULT NULL,
  `changed_by_name` TEXT,
  `created_at`      DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ivh_profile` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Expanded installation profile fields (billing/ops detail)
ALTER TABLE `installation_profiles`
  ADD COLUMN IF NOT EXISTS `amount_paid`       DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `network_user_id`   VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `router_type`       VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `estate`            VARCHAR(255)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `pop`               VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `connection_status` VARCHAR(30)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `connection_date`   DATE          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `installer`         VARCHAR(150)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `installation_cost` DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `field_marketer`    VARCHAR(150)  DEFAULT NULL;

-- 10. Split tickets.close into tickets.resolve + tickets.close (each assignable
--     separately). Any role that already had tickets.close also gets
--     tickets.resolve so nobody loses capability — separate them afterward
--     from Admin -> Permissions.
INSERT IGNORE INTO `role_permissions` (`id`, `role`, `permission`)
SELECT UUID(), rp.role, 'tickets.resolve'
FROM (SELECT DISTINCT role FROM `role_permissions` WHERE permission = 'tickets.close') rp;
