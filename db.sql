CREATE DATABASE IF NOT EXISTS `mcats` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `mcats`;

SET FOREIGN_KEY_CHECKS = 0, SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO', time_zone = '+00:00';



-- =========================================================
-- CUSTOMERS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`customers` (
    `nic_number` varchar(20) NOT NULL,
    `phone_number` varchar(15) NOT NULL,
    `full_name` varchar(100) NOT NULL,
    `address` text DEFAULT NULL,
    PRIMARY KEY (`nic_number`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- USERS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`users` (
    `user_id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `password` varchar(255) NOT NULL,
    `role` enum('super_admin','admin') NOT NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- CASH SESSIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`cash_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `opening_balance` decimal(10,2) NOT NULL,
    `closing_balance` decimal(10,2) DEFAULT NULL,
    `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `closed_at` timestamp NULL DEFAULT NULL,
    `status` enum('open','closed') DEFAULT 'open',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- INVENTORY
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`inventory` (
    `item_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_name` varchar(100) NOT NULL,
    `category` enum(
        'Power Tools',
        'Hardware Goods',
        'Rental Items'
    ) NOT NULL,
    `price_per_unit` decimal(10,2) NOT NULL,
    `stock_quantity` int(11) DEFAULT 0,
    `item_image` varchar(255) DEFAULT 'default.png',
    `status` varchar(50) DEFAULT 'available',
    `bought_price` varchar(50) DEFAULT NULL,
    PRIMARY KEY (`item_id`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- TRANSACTIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`transactions` (
    `bill_number` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` int(11) DEFAULT NULL,
    `customer_nic` varchar(20) DEFAULT NULL,
    `type` varchar(50) NOT NULL,
    `subtotal_lkr` decimal(10,2) DEFAULT 0.00,
    `discount_type` varchar(20) DEFAULT 'none',
    `discount_value` decimal(10,2) DEFAULT 0.00,
    `discount_amount` decimal(10,2) DEFAULT 0.00,
    `total_lkr` decimal(10,2) NOT NULL,
    `received_amount` decimal(10,2) DEFAULT 0.00,
    `balance_amount` decimal(10,2) DEFAULT 0.00,
    `advance_paid` decimal(10,2) DEFAULT 0.00,
    `free_equipment` text DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `status` varchar(50) DEFAULT 'ongoing',
    `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`bill_number`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- TRANSACTION ITEMS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`transaction_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `bill_number` int(11) DEFAULT NULL,
    `item_id` int(11) DEFAULT NULL,
    `quantity` int(11) DEFAULT NULL,
    `unit_price` decimal(10,2) DEFAULT NULL,
    `billed_days` int(11) DEFAULT NULL,
    `is_free` tinyint(1) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- CHANGE REQUESTS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`change_requests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `type` enum(
        'quantity',
        'daily_rate',
        'cost_price'
    ) NOT NULL,
    `item_id` int(11) NOT NULL,
    `item_name` varchar(100) NOT NULL,
    `current_value` varchar(50) DEFAULT NULL,
    `requested_value` varchar(50) NOT NULL,
    `reason` text DEFAULT NULL,
    `requested_by` varchar(50) NOT NULL,
    `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `status` enum(
        'pending',
        'approved',
        'completed',
        'cancelled',
        'rejected'
    ) DEFAULT 'pending',
    `approved_by` varchar(50) DEFAULT NULL,
    `approved_at` timestamp NULL DEFAULT NULL,
    `sa_action_by` varchar(50) DEFAULT NULL,
    `sa_action_at` timestamp NULL DEFAULT NULL,
    `sa_notes` text DEFAULT NULL,
    `completed_at` timestamp NULL DEFAULT NULL,
    `completed_by` varchar(50) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `item_id` (`item_id`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- PAY LATER PAYMENTS
-- =========================================================

CREATE TABLE IF NOT EXISTS `mcats`.`pay_later_payments` (
    `payment_id` int(11) NOT NULL AUTO_INCREMENT,
    `bill_number` int(11) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `payment_method` varchar(50) DEFAULT 'Cash',
    `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
    `notes` text DEFAULT NULL,
    PRIMARY KEY (`payment_id`),
    KEY `bill_number` (`bill_number`)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


-- =========================================================
-- CUSTOMERS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`customers`
(
    `nic_number`,
    `phone_number`,
    `full_name`,
    `address`
)
VALUES
(
    '199012345678',
    '0771112222',
    'John Doe Construction',
    '123 Builder Lane, Colombo'
),
(
    '198598765432',
    '0714445555',
    'Jane Smith Renovations',
    '456 Fixit Street, Kandy'
),
(
    '200024681357',
    '0758889999',
    'Michael Silva Mechanics',
    '789 Garage Road, Galle'
),
(
    '199512345678',
    '0779998888',
    'Test Renter',
    ''
);


-- =========================================================
-- USERS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`users`
(
    `user_id`,
    `username`,
    `password`,
    `role`
)
VALUES
(
    1,
    'sa',
    'sa@2026',
    'super_admin'
),
(
    2,
    'admin',
    'admin@2026',
    'admin'
);


-- =========================================================
-- INVENTORY DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`inventory`
(
    `item_id`,
    `item_name`,
    `category`,
    `price_per_unit`,
    `stock_quantity`,
    `item_image`,
    `status`,
    `bought_price`
)
VALUES
(1, 'Cordless Rotary Hammer Drill 24V', 'Power Tools', 3250, 24, 'default.png', 'available', 'HORSE'),

(2, 'Bosch Angle Grinder Pro', 'Power Tools', 14500, 17, 'default.png', 'available', '10200'),

(3, 'DeWalt Circular Saw', 'Power Tools', 28000, 2, 'default.png', 'available', NULL),

(4, 'Heavy Duty Steel Hammer', 'Hardware Goods', 1200, 18, 'default.png', 'available', NULL),

(5, 'PVC Pipe 1 inch', 'Hardware Goods', 450, 100, 'default.png', 'available', 'AOO'),

(6, 'Assorted Screws Box', 'Hardware Goods', 850, 49, 'default.png', 'available', NULL),

(7, 'Portable Concrete Mixer', 'Rental Items', 4000, 2, 'default.png', 'rented', '75000'),

(8, 'Steel Scaffolding Set', 'Rental Items', 1500, 15, 'default.png', 'rented', '30000'),

(9, 'Industrial Wet Vacuum', 'Rental Items', 2000, 4, 'default.png', 'rented', '40000'),

(10, 'Hitachi Rotary Hammer', 'Power Tools', 0, 0, 'default.png', 'available', '26000'),

(11, 'Test New Tool', 'Power Tools', 5500, 8, 'default.png', 'available', '4000'),

(12, 'Demolition Jackhammer 16kg', 'Rental Items', 4500, 3, 'default.png', 'available', '95000'),

(13, 'High Pressure Water Jet Cleaner', 'Rental Items', 2500, 5, 'default.png', 'available', '52000'),

(14, 'Heavy Duty Plate Compactor 90kg', 'Rental Items', 3800, 2, 'default.png', 'available', '82000');


-- =========================================================
-- CASH SESSION DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`cash_sessions`
(
    `id`,
    `user_id`,
    `opening_balance`,
    `closing_balance`,
    `opened_at`,
    `closed_at`,
    `status`
)
VALUES
(
    1,
    2,
    5000,
    NULL,
    '2026-09-14 18:04:32',
    NULL,
    'open'
);


-- =========================================================
-- TRANSACTIONS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`transactions`
(
    `bill_number`,
    `session_id`,
    `customer_nic`,
    `type`,
    `subtotal_lkr`,
    `discount_type`,
    `discount_value`,
    `discount_amount`,
    `total_lkr`,
    `received_amount`,
    `balance_amount`,
    `advance_paid`,
    `free_equipment`,
    `notes`,
    `status`,
    `transaction_date`
)
VALUES

(
    1001,
    1,
    '199512345678',
    'renting',
    1500,
    'none',
    0,
    0,
    1500,
    3000,
    1500,
    2000,
    'Safety Helmet & Gloves',
    NULL,
    'returned',
    '2026-09-14 18:34:54'
),

(
    1002,
    1,
    NULL,
    'selling',
    15000,
    'none',
    0,
    0,
    15000,
    15000,
    0,
    0,
    NULL,
    NULL,
    'completed',
    '2026-09-15 18:03:40'
),

(
    1003,
    1,
    '199512345678',
    'renting',
    3500,
    'none',
    0,
    0,
    3500,
    8500,
    5000,
    5000,
    NULL,
    NULL,
    'returned',
    '2026-09-15 18:03:40'
),

(
    1004,
    1,
    NULL,
    'selling',
    2050,
    'none',
    0,
    0,
    2050,
    5000,
    2950,
    0,
    NULL,
    NULL,
    'completed',
    '2026-09-15 19:00:02'
),

(
    1005,
    1,
    NULL,
    'selling',
    42500,
    'none',
    0,
    0,
    42500,
    50000,
    7500,
    0,
    NULL,
    NULL,
    'completed',
    '2026-09-15 19:00:58'
),

(
    1006,
    1,
    NULL,
    'selling',
    20000,
    'percentage',
    10,
    2000,
    18000,
    20000,
    2000,
    0,
    NULL,
    NULL,
    'completed',
    '2026-09-15 19:13:13'
),

(
    1007,
    1,
    '199012345678',
    'renting',
    4000,
    'none',
    0,
    0,
    4000,
    10000,
    0,
    10000,
    'Heavy Extension Cable (30m) & Safety Goggles',
    'Commercial site foundation pour',
    'ongoing',
    '2026-09-16 09:30:00'
),

(
    1008,
    1,
    '198598765432',
    'renting',
    3000,
    'none',
    0,
    0,
    3000,
    6000,
    0,
    6000,
    'Safety Harness & Locking Clamp Pins',
    'Exterior facade renovation',
    'ongoing',
    '2026-09-16 14:15:00'
),

(
    1009,
    1,
    '200024681357',
    'renting',
    2000,
    'none',
    0,
    0,
    2000,
    5000,
    0,
    5000,
    'Wide Suction Brush & Cartridge Filter',
    'Workshop deep cleaning',
    'ongoing',
    '2026-09-17 08:45:00'
),

(
    1010,
    1,
    '199512345678',
    'renting',
    8000,
    'none',
    0,
    0,
    8000,
    5000,
    -3000,
    3000,
    'Heavy Duty Extension Reel',
    'Pay Later Agreement: Due Rs. 3,000.00 | Customer had insufficient cash at return, promised balance payment on Friday',
    'pay_later',
    '2026-09-15 11:20:00'
),

(
    1011,
    1,
    '199012345678',
    'renting',
    9000,
    'none',
    0,
    0,
    9000,
    4000,
    -5000,
    4000,
    'Chisel bits & Ear Muffs',
    'Pay Later Agreement: Due Rs. 5,000.00 | Site manager away, promised settlement within 3 days',
    'pay_later',
    '2026-09-16 08:15:00'
);


-- =========================================================
-- TRANSACTION ITEMS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`transaction_items`
(
    `id`,
    `bill_number`,
    `item_id`,
    `quantity`,
    `unit_price`,
    `billed_days`,
    `is_free`
)
VALUES

(1, 1001, 1, 1, 1500, 1, 0),

(2, 1002, 1, 1, 15000, NULL, 0),

(3, 1003, 7, 1, 3500, 1, 0),

(4, 1004, 4, 1, 1200, NULL, 0),

(5, 1004, 6, 1, 850, NULL, 0),

(6, 1005, 2, 1, 14500, NULL, 0),

(7, 1005, 3, 1, 28000, NULL, 0),

(8, 1006, 1, 1, 15000, NULL, 0),

(9, 1006, 4, 1, 5000, NULL, 0),

(10, 1007, 7, 1, 4000, 1, 0),

(11, 1008, 8, 2, 1500, 1, 0),

(12, 1009, 9, 1, 2000, 1, 0),

(13, 1010, 7, 1, 4000, 2, 0),

(14, 1011, 12, 1, 4500, 2, 0);


-- =========================================================
-- CHANGE REQUESTS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`change_requests`
(
    `id`,
    `type`,
    `item_id`,
    `item_name`,
    `current_value`,
    `requested_value`,
    `reason`,
    `requested_by`,
    `requested_at`,
    `status`,
    `approved_by`,
    `approved_at`,
    `sa_action_by`,
    `sa_action_at`,
    `sa_notes`,
    `completed_at`,
    `completed_by`
)
VALUES

(
    13,
    'quantity',
    1,
    'Cordless Rotary Hammer Drill 24V',
    '25',
    '9999',
    'Test count',
    'cashier',
    '2026-09-15 18:52:36',
    'cancelled',
    'superadmin',
    '2026-09-15 18:52:36',
    'superadmin',
    '2026-09-15 18:52:36',
    'Cancelled by SA test',
    NULL,
    NULL
),

(
    12,
    'cost_price',
    5,
    'PVC Pipe 1 inch',
    'AOOO',
    'AOO',
    'just',
    'admin',
    '2026-09-15 18:37:05',
    'completed',
    'sa',
    '2026-09-15 18:38:15',
    'sa',
    '2026-09-15 18:38:15',
    'Approved by Super Admin',
    '2026-09-15 18:38:59',
    'admin'
),

(
    11,
    'daily_rate',
    5,
    'PVC Pipe 1 inch',
    '4500',
    '450.00',
    'just',
    'admin',
    '2026-09-15 18:37:05',
    'completed',
    'sa',
    '2026-09-15 18:38:35',
    'sa',
    '2026-09-15 18:38:35',
    'Approved by Super Admin',
    '2026-09-15 18:39:22',
    'admin'
),

(
    10,
    'quantity',
    5,
    'PVC Pipe 1 inch',
    '10',
    '100',
    'just',
    'admin',
    '2026-09-15 18:37:05',
    'completed',
    'sa',
    '2026-09-15 18:38:01',
    'sa',
    '2026-09-15 18:38:01',
    'Approved by Super Admin',
    '2026-09-15 18:39:31',
    'admin'
),

(
    9,
    'cost_price',
    1,
    'Cordless Rotary Hammer Drill 24V',
    'BLACK',
    'HORSE',
    'Supplier price revision and new stock arrival batch',
    'admin',
    '2026-09-15 18:34:34',
    'approved',
    'sa',
    '2026-09-15 18:38:09',
    'sa',
    '2026-09-15 18:38:09',
    'Approved by Super Admin',
    NULL,
    NULL
),

(
    8,
    'daily_rate',
    1,
    'Cordless Rotary Hammer Drill 24V',
    '2850',
    '3250.00',
    'Supplier price revision and new stock arrival batch',
    'admin',
    '2026-09-15 18:34:34',
    'approved',
    'sa',
    '2026-09-15 18:38:23',
    'sa',
    '2026-09-15 18:38:23',
    'Approved by Super Admin',
    NULL,
    NULL
),

(
    7,
    'quantity',
    1,
    'Cordless Rotary Hammer Drill 24V',
    '20',
    '25',
    'Supplier price revision and new stock arrival batch',
    'admin',
    '2026-09-15 18:34:34',
    'completed',
    'sa',
    '2026-09-15 18:34:47',
    'sa',
    '2026-09-15 18:34:47',
    'Approved restock to 25 units',
    '2026-09-15 18:34:47',
    'admin'
),

(
    6,
    'quantity',
    1,
    'Cordless Rotary Hammer Drill 24V',
    '8',
    '25',
    'Restocked from warehouse',
    'admin',
    '2026-09-15 18:05:23',
    'completed',
    'sa',
    '2026-09-15 18:14:58',
    'sa',
    '2026-09-15 18:14:58',
    'Approved by Super Admin',
    '2026-09-15 18:15:29',
    'admin'
),

(
    5,
    'quantity',
    1,
    'Makita Cordless Drill',
    '10',
    '20',
    'Seasonal stocking',
    'cashier1',
    '2026-09-15 17:14:12',
    'completed',
    'admin_super',
    '2026-09-15 17:14:12',
    'admin_super',
    '2026-09-15 17:14:12',
    'Approved by executive',
    '2026-09-15 17:14:12',
    'cashier1'
),

(
    4,
    'quantity',
    1,
    'Makita Cordless Drill',
    '10',
    '15',
    'Arrival of new shipment batch',
    'cashier1',
    '2026-09-15 17:13:49',
    'completed',
    'admin_super',
    '2026-09-15 17:13:49',
    'admin_super',
    '2026-09-15 17:13:49',
    'Approved for batch restock',
    '2026-09-15 18:13:38',
    'admin'
),

(
    1,
    'quantity',
    1,
    'Makita Cordless Drill',
    '10',
    '15',
    'Supplier delivered 5 additional units under PO-4481',
    'admin',
    '2026-09-15 14:06:53',
    'completed',
    'sa',
    '2026-09-15 18:05:23',
    'sa',
    '2026-09-15 18:05:23',
    'Approved by SA',
    '2026-09-15 18:14:21',
    'admin'
),

(
    2,
    'daily_rate',
    7,
    'Portable Concrete Mixer',
    '3500.00',
    '4000.00',
    'Reflects revised daily rental market standard for high-capacity mixer',
    'admin',
    '2026-09-15 12:06:53',
    'completed',
    'sa',
    '2026-09-15 16:06:53',
    'sa',
    '2026-09-15 16:06:53',
    'Approved as requested. Authorized cashier can apply rate update.',
    '2026-09-15 18:16:01',
    'admin'
),

(
    3,
    'cost_price',
    2,
    'Bosch Angle Grinder',
    '9500',
    '10200',
    'Supplier cost cipher revision after inflation surcharge',
    'admin',
    '2026-09-15 11:06:53',
    'completed',
    'sa',
    '2026-09-15 18:15:03',
    'sa',
    '2026-09-15 18:15:03',
    'Approved by Super Admin',
    '2026-09-15 18:16:39',
    'admin'
);


-- =========================================================
-- PAY LATER PAYMENTS DATA
-- =========================================================

INSERT IGNORE INTO `mcats`.`pay_later_payments`
(
    `payment_id`,
    `bill_number`,
    `amount`,
    `payment_method`,
    `payment_date`,
    `notes`
)
VALUES
(
    1,
    1010,
    2000,
    'Cash',
    '2026-09-17 10:30:00',
    'Partial payment tendered at return desk'
);


-- =========================================================
-- COMPLETE IMPORT
-- =========================================================

SET FOREIGN_KEY_CHECKS = 1;