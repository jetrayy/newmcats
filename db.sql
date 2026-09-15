-- MCATS Database Dump
-- Synchronized: 2026-09-15T17:34:14.965Z

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `mcats`;
USE `mcats`;

DROP TABLE IF EXISTS `transaction_items`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `cash_sessions`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `customers` (
  `nic_number` varchar(20) NOT NULL,
  `phone_number` varchar(15) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  PRIMARY KEY (`nic_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin') NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cash_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `opening_balance` decimal(10,2) NOT NULL,
  `closing_balance` decimal(10,2) DEFAULT NULL,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_at` timestamp NULL DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inventory` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `category` enum('Power Tools','Hardware Goods','Rental Items') NOT NULL,
  `price_per_unit` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) DEFAULT 0,
  `item_image` varchar(255) DEFAULT 'default.png',
  `status` varchar(50) DEFAULT 'available',
  `bought_price` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transactions` (
  `bill_number` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) DEFAULT NULL,
  `customer_nic` varchar(20) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `total_lkr` decimal(10,2) NOT NULL,
  `received_amount` decimal(10,2) DEFAULT 0.00,
  `balance_amount` decimal(10,2) DEFAULT 0.00,
  `advance_paid` decimal(10,2) DEFAULT 0.00,
  `free_equipment` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'ongoing',
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`bill_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_number` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `billed_days` int(11) DEFAULT NULL,
  `is_free` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping customers
INSERT INTO `customers` (`nic_number`, `phone_number`, `full_name`, `address`) VALUES
  ('199012345678', '0771112222', 'John Doe Construction', '123 Builder Lane, Colombo'),
  ('198598765432', '0714445555', 'Jane Smith Renovations', '456 Fixit Street, Kandy'),
  ('200024681357', '0758889999', 'Michael Silva Mechanics', '789 Garage Road, Galle');

-- Dumping users
INSERT INTO `users` (`user_id`, `username`, `password`, `role`) VALUES
  (1, 'sa', 'sa@2026', 'super_admin'),
  (2, 'admin', 'admin@2026', 'admin');

-- Dumping inventory
INSERT INTO `inventory` (`item_id`, `item_name`, `category`, `price_per_unit`, `stock_quantity`, `item_image`, `status`, `bought_price`) VALUES
  (1, 'Cordless Rotary Hammer Drill 24V', 'Power Tools', 2850, 9, 'default.png', 'available', 'BLACK'),
  (2, 'Bosch Angle Grinder Pro', 'Power Tools', 14500, 18, 'default.png', 'available', 'BLACKHORSE'),
  (3, 'DeWalt Circular Saw', 'Power Tools', 28000, 3, 'default.png', 'available', NULL),
  (4, 'Heavy Duty Steel Hammer', 'Hardware Goods', 1200, 20, 'default.png', 'available', NULL),
  (5, 'PVC Pipe 1 inch', 'Hardware Goods', 450, 100, 'default.png', 'available', NULL),
  (6, 'Assorted Screws Box', 'Hardware Goods', 850, 50, 'default.png', 'available', NULL),
  (7, 'Portable Concrete Mixer', 'Rental Items', 3500, 2, 'default.png', 'available', NULL),
  (8, 'Steel Scaffolding Set', 'Rental Items', 1500, 15, 'default.png', 'available', NULL),
  (9, 'Industrial Wet Vacuum', 'Rental Items', 2000, 4, 'default.png', 'available', NULL),
  (10, 'Hitachi Rotary Hammer', 'Power Tools', 0, 0, 'default.png', 'available', '26000');

-- Dumping cash_sessions
INSERT INTO `cash_sessions` (`id`, `user_id`, `opening_balance`, `closing_balance`, `opened_at`, `closed_at`, `status`) VALUES
  (1, 2, 5000, NULL, '2026-09-14T18:04:32.337Z', NULL, 'open');

-- Dumping transactions
INSERT INTO `transactions` (`bill_number`, `session_id`, `customer_nic`, `type`, `total_lkr`, `received_amount`, `balance_amount`, `advance_paid`, `free_equipment`, `notes`, `status`, `transaction_date`) VALUES
  (1001, 1, '199512345678', 'renting', 1500, 3000, 1500, 2000, 'Safety Helmet & Gloves', NULL, 'returned', '2026-09-14T18:34:54.015Z');

-- Dumping transaction_items
INSERT INTO `transaction_items` (`id`, `bill_number`, `item_id`, `quantity`, `unit_price`, `billed_days`, `is_free`) VALUES
  (1, 1001, 1, 1, 1500, 1, 0);

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
