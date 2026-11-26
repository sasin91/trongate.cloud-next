-- Administrators (login user level 1)

CREATE TABLE IF NOT EXISTS `trongate_administrators` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(65) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `trongate_user_id` INT(11) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` INT(11) NOT NULL DEFAULT 0,
  `last_failed_attempt` INT(11) NOT NULL DEFAULT 0,
  `login_blocked_until` INT(11) NOT NULL DEFAULT 0,
  `failed_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `num_logins` INT(11) NOT NULL DEFAULT 0,
  `last_login` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_trongate_user_id` (`trongate_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
