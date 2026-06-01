-- Create Database
CREATE DATABASE IF NOT EXISTS `u649217041_rjm_architect`;
USE `u649217041_rjm_architect`;

-- Create Contact Submissions Table
CREATE TABLE IF NOT EXISTS `contact_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `message` LONGTEXT NOT NULL,
  `status` ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(50),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add status to existing contact_submissions tables
ALTER TABLE `contact_submissions`
  ADD COLUMN IF NOT EXISTS `status` ENUM('unread', 'read') NOT NULL DEFAULT 'unread' AFTER `message`;

-- Create Admin Login Table
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) DEFAULT 'Administrator',
  `role` ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_admins_email` (`email`),
  KEY `idx_admins_role` (`role`),
  KEY `idx_admins_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add role to existing admins tables
ALTER TABLE `admins`
  ADD COLUMN IF NOT EXISTS `role` ENUM('admin', 'employee') NOT NULL DEFAULT 'employee' AFTER `full_name`;

-- Default admin account:
-- Email: admin@rjmarchibuild.com
-- Password: admin123
INSERT INTO `admins` (`email`, `password_hash`, `full_name`, `role`, `status`)
SELECT 'admin@rjmarchibuild.com', '$2y$10$sAgkzvnwXTXJRN0ajGb66OJmvi1WyfRYc38bJQ7b3PHvmYOC3tqtm', 'Administrator', 'admin', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `admins` WHERE `email` = 'admin@rjmarchibuild.com'
);

UPDATE `admins` SET `role` = 'admin' WHERE `email` = 'admin@rjmarchibuild.com';

-- Default employee accounts:
-- Email: employee@rjmarchibuild.com
-- Password: employee123
INSERT INTO `admins` (`email`, `password_hash`, `full_name`, `role`, `status`)
SELECT 'employee@rjmarchibuild.com', '$2y$10$rZlI7zhH8scizGtzRLIlXu5vpzSr1Q./FbWHKRO6LNNXVXFy0gfPK', 'Employee 1', 'employee', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `admins` WHERE `email` = 'employee@rjmarchibuild.com'
);

UPDATE `admins` SET `full_name` = 'Employee 1', `role` = 'employee', `status` = 'active'
WHERE `email` = 'employee@rjmarchibuild.com';

-- Email: employee2@rjmarchibuild.com
-- Password: employee223
INSERT INTO `admins` (`email`, `password_hash`, `full_name`, `role`, `status`)
SELECT 'employee2@rjmarchibuild.com', '$2y$10$I4PxRXW9GhwoEH0ir2mHnuvZpR.93HvKcEbCd.2g1y67mrJ4LRMXW', 'Employee 2', 'employee', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `admins` WHERE `email` = 'employee2@rjmarchibuild.com'
);

-- Email: employee3@rjmarchibuild.com
-- Password: employee323
INSERT INTO `admins` (`email`, `password_hash`, `full_name`, `role`, `status`)
SELECT 'employee3@rjmarchibuild.com', '$2y$10$.TSUCW8w6QZu0JE3hHF.c.JMl8jcWwpZMJmuJ2u8fS0e/C0.JMs66', 'Employee 3', 'employee', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `admins` WHERE `email` = 'employee3@rjmarchibuild.com'
);

-- Create Quote Submissions Table
CREATE TABLE IF NOT EXISTS `quote_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `project_type` VARCHAR(100) NOT NULL,
  `lot_area` VARCHAR(100) NOT NULL,
  `project_location` VARCHAR(200) NOT NULL,
  `description` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(50),
  KEY `idx_email` (`email`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Project Drawing Files Table
CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(180) NOT NULL UNIQUE,
  `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_projects_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NULL,
  `project_name` VARCHAR(180) NOT NULL,
  `drawing_detail` VARCHAR(180) NOT NULL,
  `file_type` ENUM('cad', 'pdf') NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` INT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_project_files_project_id` (`project_id`),
  KEY `idx_project_files_project` (`project_name`),
  KEY `idx_project_files_type` (`file_type`),
  KEY `idx_project_files_uploaded_at` (`uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `project_files`
  ADD COLUMN IF NOT EXISTS `project_id` INT NULL AFTER `id`;

-- Create Finance Records Table
CREATE TABLE IF NOT EXISTS `finance_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `expense_type` VARCHAR(80) NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` VARCHAR(180) NOT NULL,
  `project_name` VARCHAR(180) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `received_by` VARCHAR(160) NOT NULL,
  `remark` ENUM('Released', 'Unreleased') NOT NULL DEFAULT 'Unreleased',
  `receipt_path` VARCHAR(500) NULL,
  `receipt_name` VARCHAR(180) NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_finance_records_date` (`expense_date`),
  KEY `idx_finance_records_project` (`project_name`),
  KEY `idx_finance_records_remark` (`remark`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
