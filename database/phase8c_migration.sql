-- Phase 8c: GPS check-in for field technicians
CREATE TABLE IF NOT EXISTS `ticket_checkins` (
  `id`             varchar(36)    NOT NULL,
  `ticket_id`      varchar(36)    NOT NULL,
  `user_id`        varchar(36)    NOT NULL,
  `user_name`      varchar(255)   NOT NULL DEFAULT '',
  `latitude`       decimal(10,7)  NOT NULL,
  `longitude`      decimal(10,7)  NOT NULL,
  `accuracy`       float          DEFAULT NULL,
  `checked_in_at`  datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checked_out_at` datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_checkins_ticket`  (`ticket_id`),
  KEY `idx_ticket_checkins_user`    (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
