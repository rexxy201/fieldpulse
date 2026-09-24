-- Phase 2: NOC dashboard trends + KMZ/KML coverage map layers
-- Safe to re-run (IF NOT EXISTS on all tables).

-- Router/Mikrotik time-series stats (written by scripts/poll-mikrotik.php)
CREATE TABLE IF NOT EXISTS hub_device_stats (
  id              VARCHAR(36)         NOT NULL,
  device_id       VARCHAR(36)         NOT NULL,
  hub_id          VARCHAR(36)         NOT NULL,
  cpu_load        TINYINT UNSIGNED    NOT NULL DEFAULT 0,
  mem_used_bytes  BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  mem_total_bytes BIGINT UNSIGNED     NOT NULL DEFAULT 0,
  pppoe_sessions  INT UNSIGNED        NOT NULL DEFAULT 0,
  uptime_str      VARCHAR(60)                  DEFAULT NULL,
  sampled_at      DATETIME            NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_device_sampled (device_id, sampled_at),
  INDEX idx_hub_sampled    (hub_id,    sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KMZ/KML coverage overlay files uploaded by admins
CREATE TABLE IF NOT EXISTS coverage_layers (
  id          VARCHAR(36)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description TEXT                  DEFAULT NULL,
  file_path   VARCHAR(255) NOT NULL,
  hub_id      VARCHAR(36)           DEFAULT NULL,
  color       VARCHAR(7)   NOT NULL DEFAULT '#3b82f6',
  opacity     DECIMAL(3,2) NOT NULL DEFAULT 0.35,
  enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  VARCHAR(36)           DEFAULT NULL,
  created_at  DATETIME              DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
