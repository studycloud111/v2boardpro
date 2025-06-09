CREATE TABLE IF NOT EXISTS `v2_giftcard_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `giftcard_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `giftcard_id` (`giftcard_id`),
  INDEX `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; 