CREATE DATABASE IF NOT EXISTS `evenementen_planner` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `evenementen_planner`;

CREATE TABLE IF NOT EXISTS `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `event_type` ENUM('birthday', 'secret_santa') NOT NULL,
    `event_date` DATE NOT NULL,
    `draw_date` DATE NULL,
    `budget` DECIMAL(10, 2) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`event_date`),
    INDEX (`draw_date`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `participants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `email_normalized` VARCHAR(255) AS (LOWER(`email`)) STORED,
    `matched_participant_id` INT NULL,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`matched_participant_id`) REFERENCES `participants`(`id`) ON DELETE SET NULL,
    INDEX (`token`),
    UNIQUE KEY `uniq_event_email` (`event_id`, `email_normalized`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `gift_ideas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `participant_id` INT NOT NULL,
    `description` TEXT NOT NULL,
    `shop_url` TEXT NULL,
    `bought_by_participant_id` INT NULL,
    FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`bought_by_participant_id`) REFERENCES `participants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `anonymous_questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `gift_idea_id` INT NOT NULL,
    `asker_participant_id` INT NOT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`gift_idea_id`) REFERENCES `gift_ideas`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`asker_participant_id`) REFERENCES `participants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
