-- ─────────────────────────────────────────
-- NOC Phase 1 migration
-- Run once after deploying the NOC module.
-- Safe to re-run (all statements use IF NOT EXISTS).
-- ─────────────────────────────────────────

-- Network devices: OLTs and Mikrotik routers, one row per physical device
CREATE TABLE IF NOT EXISTS `network_devices` (
  `id`              VARCHAR(36)  NOT NULL,
  `hub_id`          VARCHAR(36)  NOT NULL,
  `device_type`     ENUM('olt','mikrotik','switch') NOT NULL DEFAULT 'olt',
  `name`            VARCHAR(100) NOT NULL,
  `ip_address`      VARCHAR(45)  NOT NULL,
  `protocol`        ENUM('snmp','routeros_api','ssh') NOT NULL DEFAULT 'snmp',
  `snmp_community`  TEXT         DEFAULT NULL COMMENT 'AES-256 encrypted',
  `snmp_version`    ENUM('1','2c','3') NOT NULL DEFAULT '2c',
  `api_credentials` TEXT         DEFAULT NULL COMMENT 'JSON {user,pass} AES-256 encrypted',
  `api_port`        SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `last_polled_at`  DATETIME     DEFAULT NULL,
  `last_seen_at`    DATETIME     DEFAULT NULL,
  `poll_error`      TEXT         DEFAULT NULL COMMENT 'Last polling error message',
  `created_by`      VARCHAR(36)  DEFAULT NULL,
  `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub` (`hub_id`),
  KEY `idx_type` (`device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ONU units: one row per subscriber CPE, linked to the OLT that serves it
CREATE TABLE IF NOT EXISTS `onu_units` (
  `id`              VARCHAR(36)  NOT NULL,
  `olt_device_id`   VARCHAR(36)  NOT NULL,
  `customer_id`     VARCHAR(36)  DEFAULT NULL COMMENT 'Linked customer (nullable — unregistered ONUs allowed)',
  `serial_number`   VARCHAR(100) NOT NULL,
  `mac_address`     VARCHAR(17)  DEFAULT NULL,
  `olt_port`        VARCHAR(20)  DEFAULT NULL COMMENT 'e.g. 0/1/3',
  `onu_index`       INT          DEFAULT NULL COMMENT 'Huawei slot/port/onu-id composite',
  `description`     VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('working','offline','los','dying_gasp','lof','unknown') NOT NULL DEFAULT 'unknown',
  `rx_power_dbm`    DECIMAL(6,2) DEFAULT NULL COMMENT 'Optical receive power level',
  `tx_power_dbm`    DECIMAL(6,2) DEFAULT NULL,
  `last_online_at`  DATETIME     DEFAULT NULL,
  `last_polled_at`  DATETIME     DEFAULT NULL,
  `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_serial` (`serial_number`),
  KEY `idx_olt` (`olt_device_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ONU status events: state-change log; auto-ticket links recorded here
CREATE TABLE IF NOT EXISTS `onu_status_events` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `onu_id`          VARCHAR(36)  NOT NULL,
  `from_status`     VARCHAR(20)  DEFAULT NULL,
  `to_status`       VARCHAR(20)  NOT NULL,
  `detected_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `auto_ticket_id`  VARCHAR(36)  DEFAULT NULL COMMENT 'Ticket created for this fault event',
  PRIMARY KEY (`id`),
  KEY `idx_onu_time` (`onu_id`, `detected_at`),
  KEY `idx_time` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
