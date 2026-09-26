-- ============================================================
-- FieldPulse (PulseFix) — Full MySQL Schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- cPanel / shared hosting ready
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ─────────────────────────────────────────
-- roles
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `name`       VARCHAR(50)  NOT NULL,
  `label`      VARCHAR(100) NOT NULL,
  `department` VARCHAR(30)  NOT NULL DEFAULT '',
  `is_system`  TINYINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`name`, `label`, `department`, `is_system`) VALUES
  ('admin',            'Administrator',         'management', 1),
  ('project_admin',    'Project Administrator', 'management', 1),
  ('supervisor-fiber', 'Fiber Supervisor',      'fiber',      1),
  ('supervisor-noc',   'NOC Supervisor',        'noc',        1),
  ('cx_supervisor',    'CX Supervisor',         'cx',         1),
  ('cx',               'CX Agent',              'cx',         1),
  ('engineer',         'Field Engineer',        'fiber',      1),
  ('vendor',           'Vendor',                'vendor',     1);

-- ─────────────────────────────────────────
-- users
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`        VARCHAR(36)  NOT NULL,
  `username`  TEXT         NOT NULL,
  `password`  TEXT         NOT NULL,
  `name`      TEXT         NOT NULL,
  `email`     TEXT,
  `phone`     TEXT,
  `role`      VARCHAR(50)  NOT NULL DEFAULT 'cx',
  `status`    VARCHAR(20)  NOT NULL DEFAULT 'active',
  `hub_id`    VARCHAR(36)  DEFAULT NULL,
  `team_id`   VARCHAR(36)  DEFAULT NULL,
  `vendor_id` VARCHAR(36)  DEFAULT NULL,
  `hub_ids`   TEXT,
  `failed_login_attempts` INT NOT NULL DEFAULT 0,
  `locked_until`           DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user — password: admin123 (change it immediately after first login)
INSERT IGNORE INTO `users` (`id`, `username`, `password`, `name`, `role`, `status`) VALUES
  ('00000000-0000-0000-0000-000000000001', 'admin',
   '$2y$10$JOnXTSKEa4xqwLpp4wUpHeKVR3egyKK.LCBr/KNzOXUw17EX2OpzG',
   'Administrator', 'admin', 'active');

-- ─────────────────────────────────────────
-- role_permissions
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`         VARCHAR(36)  NOT NULL,
  `role`       VARCHAR(50)  NOT NULL,
  `permission` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- vendors
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vendors` (
  `id`              VARCHAR(36)  NOT NULL,
  `name`            TEXT         NOT NULL,
  `type`            TEXT         NOT NULL,
  `email`           TEXT,
  `phone`           TEXT,
  `status`          VARCHAR(20)  NOT NULL DEFAULT 'active',
  `supervisor_name` TEXT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- teams
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `teams` (
  `id`            VARCHAR(36) NOT NULL,
  `name`          TEXT        NOT NULL,
  `type`          TEXT,
  `supervisor_id` VARCHAR(36) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- hubs
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hubs` (
  `id`      VARCHAR(36) NOT NULL,
  `name`    TEXT        NOT NULL,
  `location` TEXT,
  `lat`     DOUBLE,
  `lng`     DOUBLE,
  `team_id` VARCHAR(36) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- hub_city_mappings
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hub_city_mappings` (
  `id`         VARCHAR(36)  NOT NULL,
  `hub_id`     VARCHAR(36)  NOT NULL,
  `city_name`  VARCHAR(255) NOT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hub_city` (`hub_id`, `city_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- customers
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customers` (
  `id`             VARCHAR(36) NOT NULL,
  `name`           TEXT        NOT NULL,
  `email`          TEXT,
  `phone`          TEXT,
  `address`        TEXT,
  `account_number` TEXT        NOT NULL,
  `plan`           TEXT,
  `status`         VARCHAR(20) NOT NULL DEFAULT 'active',
  `hub_id`         VARCHAR(36) DEFAULT NULL,
  `first_name`     TEXT,
  `last_name`      TEXT,
  `mailing_street` TEXT,
  `mailing_city`   TEXT,
  `mailing_state`  TEXT,
  `expiration`     TEXT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- sla_configs
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sla_configs` (
  `id`                        VARCHAR(36) NOT NULL,
  `priority`                  VARCHAR(20) NOT NULL,
  `response_time_hours`       INT         NOT NULL DEFAULT 4,
  `resolution_time_hours`     INT         NOT NULL DEFAULT 24,
  `pause_on_pending_customer` TINYINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sla_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sla_configs` (`id`, `priority`, `response_time_hours`, `resolution_time_hours`) VALUES
  (UUID(), 'p1', 1,  4),
  (UUID(), 'p2', 2,  8),
  (UUID(), 'p3', 4,  24),
  (UUID(), 'p4', 8,  72);

-- ─────────────────────────────────────────
-- fault_types
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fault_types` (
  `id`       VARCHAR(36) NOT NULL,
  `name`     TEXT        NOT NULL,
  `category` TEXT,
  `route_to` VARCHAR(50) DEFAULT NULL,
  `enabled`  TINYINT     NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- tickets
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`                    VARCHAR(36)  NOT NULL,
  `ticket_number`         VARCHAR(50)  DEFAULT NULL,
  `customer_id`           VARCHAR(36)  DEFAULT NULL,
  `customer_name`         TEXT,
  `address`               TEXT,
  `type`                  VARCHAR(50)  DEFAULT NULL,
  `fault_type_id`         VARCHAR(36)  DEFAULT NULL,
  `priority`              VARCHAR(20)  NOT NULL DEFAULT 'medium',
  `status`                VARCHAR(30)  NOT NULL DEFAULT 'open',
  `ticket_scope`          VARCHAR(20)  NOT NULL DEFAULT 'customer',
  `description`           TEXT,
  `assigned_team`         VARCHAR(36)  DEFAULT NULL,
  `assigned_to`           VARCHAR(36)  DEFAULT NULL,
  `vendor_id`             VARCHAR(36)  DEFAULT NULL,
  `hub_id`                VARCHAR(36)  DEFAULT NULL,
  `olt`                   TEXT,
  `created_by`            VARCHAR(36)  DEFAULT NULL,
  `created_at`            DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sla_breach_at`         DATETIME     DEFAULT NULL,
  `resolved_at`           DATETIME     DEFAULT NULL,
  `closed_at`             DATETIME     DEFAULT NULL,
  `sla_timer_paused_at`   DATETIME     DEFAULT NULL,
  `sla_paused_duration_ms` BIGINT      DEFAULT 0,
  `roca_root_cause`       TEXT,
  `roca_observation`      TEXT,
  `roca_corrective_action` TEXT,
  `roca_analysis`         TEXT,
  `photo_urls`            TEXT,
  `resolution_notes`      TEXT,
  `resolved_by`           VARCHAR(36)  DEFAULT NULL,
  `resolved_by_name`      TEXT,
  `resolution_geo_lat`    VARCHAR(50)  DEFAULT NULL,
  `resolution_geo_lng`    VARCHAR(50)  DEFAULT NULL,
  `sla_warned_at`         DATETIME     DEFAULT NULL,
  `escalated_at`          DATETIME     DEFAULT NULL,
  `lock_version`          INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tickets_status`    (`status`),
  KEY `idx_tickets_assigned`  (`assigned_to`),
  KEY `idx_tickets_customer`  (`customer_id`),
  KEY `idx_tickets_scope`     (`ticket_scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- ticket_comments
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ticket_comments` (
  `id`         VARCHAR(36) NOT NULL,
  `ticket_id`  VARCHAR(36) NOT NULL,
  `user_id`    VARCHAR(36) DEFAULT NULL,
  `user_name`  TEXT,
  `content`    TEXT        NOT NULL,
  `type`       VARCHAR(30) NOT NULL DEFAULT 'comment',
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tc_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- installation_profiles
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `installation_profiles` (
  `id`            VARCHAR(36) NOT NULL,
  `name`          TEXT,
  `phone`         TEXT,
  `address`       TEXT,
  `email`         TEXT,
  `plan`          TEXT,
  `wifi_username` TEXT,
  `wifi_password` TEXT,
  `ticket_id`     VARCHAR(36) DEFAULT NULL,
  `status`        VARCHAR(30) NOT NULL DEFAULT 'pending',
  `notes`         TEXT,
  `created_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `vendor_id`     VARCHAR(36) DEFAULT NULL,
  `payment_confirmed_at` DATETIME DEFAULT NULL,
  `sla_due_at`           DATETIME DEFAULT NULL,
  `completed_at`         DATETIME DEFAULT NULL,
  `amount_paid`          DECIMAL(12,2) DEFAULT NULL,
  `network_user_id`      VARCHAR(100)  DEFAULT NULL,
  `router_type`          VARCHAR(100)  DEFAULT NULL,
  `estate`               VARCHAR(255)  DEFAULT NULL,
  `pop`                  VARCHAR(100)  DEFAULT NULL,
  `connection_status`    VARCHAR(30)   DEFAULT NULL,
  `connection_date`      DATE          DEFAULT NULL,
  `installer`            VARCHAR(150)  DEFAULT NULL,
  `installation_cost`    DECIMAL(12,2) DEFAULT NULL,
  `field_marketer`       VARCHAR(150)  DEFAULT NULL,
  `signup_submission_id` VARCHAR(36)   DEFAULT NULL,
  `sla_warned_at`            DATETIME  DEFAULT NULL,
  `sla_breached_notified_at` DATETIME  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_install_signup_submission` (`signup_submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- installation_vendor_history
-- ─────────────────────────────────────────
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

-- ─────────────────────────────────────────
-- installation_comments
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `installation_comments` (
  `id`         VARCHAR(36) NOT NULL,
  `profile_id` VARCHAR(36) NOT NULL,
  `user_id`    VARCHAR(36) DEFAULT NULL,
  `user_name`  TEXT,
  `user_role`  TEXT,
  `content`    TEXT        NOT NULL,
  `type`       VARCHAR(30) NOT NULL DEFAULT 'comment',
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ic_profile` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- notifications
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         VARCHAR(36) NOT NULL,
  `user_id`    VARCHAR(36) NOT NULL,
  `title`      TEXT        NOT NULL,
  `message`    TEXT,
  `link`       TEXT,
  `is_read`    TINYINT     NOT NULL DEFAULT 0,
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- audit_logs
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         VARCHAR(36) NOT NULL,
  `user_id`    VARCHAR(36) DEFAULT NULL,
  `user_name`  TEXT,
  `action`     TEXT        NOT NULL,
  `entity`     TEXT        NOT NULL,
  `entity_id`  VARCHAR(36) DEFAULT NULL,
  `details`    TEXT,
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_id`(36))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- app_config
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `app_config` (
  `id`    VARCHAR(36)  NOT NULL,
  `key`   VARCHAR(100) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_config` (`id`, `key`, `value`) VALUES
  (UUID(), 'app_name',    'FieldPulse'),
  (UUID(), 'app_tagline', 'Field Service & Operations Management'),
  (UUID(), 'smtp_host',   ''),
  (UUID(), 'smtp_port',   '587'),
  (UUID(), 'smtp_user',   ''),
  (UUID(), 'smtp_pass',   ''),
  (UUID(), 'smtp_from',   '');

-- ─────────────────────────────────────────
-- Inventory tables
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inv_categories` (
  `id`          INT AUTO_INCREMENT NOT NULL,
  `name`        VARCHAR(150)       NOT NULL,
  `description` TEXT,
  `created_at`  DATETIME           DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_items` (
  `id`          INT AUTO_INCREMENT NOT NULL,
  `unique_code` VARCHAR(20)  DEFAULT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `category_id` INT          DEFAULT NULL,
  `description` TEXT,
  `image`       VARCHAR(255) DEFAULT NULL,
  `quantity`    INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_products` (
  `id`          INT AUTO_INCREMENT NOT NULL,
  `unique_code` VARCHAR(20)  DEFAULT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `category_id` INT          DEFAULT NULL,
  `description` TEXT,
  `image`       VARCHAR(255) DEFAULT NULL,
  `qr_code`     VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_cabinets` (
  `id`          INT AUTO_INCREMENT NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `description` TEXT,
  `qr_code`     VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_cabinet_items` (
  `id`          INT AUTO_INCREMENT NOT NULL,
  `cabinet_id`  INT NOT NULL,
  `product_id`  INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_cabinet_custom_items` (
  `id`         INT AUTO_INCREMENT NOT NULL,
  `cabinet_id` INT          NOT NULL,
  `label`      VARCHAR(255) NOT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_stock_movements` (
  `id`           INT AUTO_INCREMENT NOT NULL,
  `item_id`      INT         NOT NULL,
  `type`         VARCHAR(10) NOT NULL,
  `quantity`     INT         NOT NULL,
  `source`       VARCHAR(20) NOT NULL DEFAULT 'manual_refill',
  `reference_id` INT         DEFAULT NULL,
  `notes`        TEXT,
  `performed_by` VARCHAR(36) DEFAULT NULL,
  `created_at`   DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inv_stock_requests` (
  `id`           INT AUTO_INCREMENT NOT NULL,
  `item_id`      INT         NOT NULL,
  `requested_by` VARCHAR(36) NOT NULL,
  `quantity`     INT         NOT NULL DEFAULT 1,
  `purpose`      VARCHAR(255) DEFAULT NULL,
  `notes`        TEXT,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reviewed_by`  VARCHAR(36) DEFAULT NULL,
  `reviewed_at`  DATETIME    DEFAULT NULL,
  `review_notes` TEXT,
  `created_at`   DATETIME    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
