-- Trongate auth core: user levels, users and tokens

CREATE TABLE IF NOT EXISTS `trongate_user_levels` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `level_title` VARCHAR(125) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `trongate_user_levels` (`id`, `level_title`) VALUES
  (1, 'admin');

CREATE TABLE IF NOT EXISTS `trongate_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL,
  `user_level_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_user_level_id` (`user_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trongate_tokens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(125) NOT NULL,
  `user_id` INT(11) NOT NULL DEFAULT 0,
  `expiry_date` INT(11) NOT NULL,
  `code` VARCHAR(3) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expiry_date` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
