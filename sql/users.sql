-- Run this in phpMyAdmin (Import) or MySQL before testing login.
-- Creates database + users table + default admin account.

CREATE DATABASE IF NOT EXISTS rice_business
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE rice_business;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default login: username = admin  |  password = admin123
INSERT INTO users (name, username, password, role)
VALUES (
  'Administrator',
  'admin',
  '$2y$10$1oWyHA8BVbELOObiKkWT.OCCkdrxQva8CalY.VFQXY.PPll/SX1x2',
  'admin'
)
ON DUPLICATE KEY UPDATE username = username;
