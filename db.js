import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const SQL_FILE_PATH = path.join(__dirname, 'db.sql');

// Helper to escape values for SQL statements
function str(v) {
  if (v === null || v === undefined) return 'NULL';
  return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '\\r') + "'";
}

function num(v) {
  if (v === null || v === undefined || v === '') return 'NULL';
  return isNaN(Number(v)) ? 'NULL' : Number(v);
}

class Database {
  constructor() {
    this.users = [];
    this.customers = [];
    this.inventory = [];
    this.cash_sessions = [];
    this.transactions = [];
    this.transaction_items = [];
    this.change_requests = [];

    this.nextUserId = 1;
    this.nextItemId = 1;
    this.nextSessionId = 1;
    this.nextBillNumber = 1001;
    this.nextTxItemId = 1;
    this.nextRequestId = 1;

    // Load from db.sql on startup
    this.loadFromSqlFile(SQL_FILE_PATH);
    this.loadChangeRequests();
  }

  // -------------------------------------------------------------
  // SQL File Loader & Parser
  // -------------------------------------------------------------
  loadFromSqlFile(filePath = SQL_FILE_PATH) {
    if (!fs.existsSync(filePath)) {
      console.warn(`[Database] ${filePath} not found. Initializing fallback schema.`);
      this.initFallbacks();
      return;
    }

    try {
      const sqlContent = fs.readFileSync(filePath, 'utf8');
      const parsed = this.parseSqlInserts(sqlContent);

      this.users = parsed.users || [];
      this.customers = parsed.customers || [];
      this.inventory = parsed.inventory || [];
      this.cash_sessions = parsed.cash_sessions || [];
      this.transactions = (parsed.transactions || []).map(t => ({
        ...t,
        subtotal_lkr: t.subtotal_lkr !== undefined && t.subtotal_lkr !== null ? Number(t.subtotal_lkr) : Number(t.total_lkr || 0),
        discount_type: t.discount_type || 'none',
        discount_value: Number(t.discount_value || 0),
        discount_amount: Number(t.discount_amount || 0)
      }));
      this.transaction_items = parsed.transaction_items || [];

      // If users is empty, provide default admin accounts
      if (this.users.length === 0) {
        this.users = [
          { user_id: 1, username: 'sa', password: 'sa@2026', role: 'super_admin' },
          { user_id: 2, username: 'admin', password: 'admin@2026', role: 'admin' }
        ];
      }

      // If inventory is empty, provide default hardware tools
      if (this.inventory.length === 0) {
        this.inventory = [
          { item_id: 1, item_name: 'Makita Cordless Drill', category: 'Power Tools', price_per_unit: 15000.00, stock_quantity: 10, item_image: 'default.png', status: 'available', bought_price: '11000' },
          { item_id: 2, item_name: 'Bosch Angle Grinder', category: 'Power Tools', price_per_unit: 12500.00, stock_quantity: 5, item_image: 'default.png', status: 'available', bought_price: '9500' },
          { item_id: 3, item_name: 'DeWalt Circular Saw', category: 'Power Tools', price_per_unit: 28000.00, stock_quantity: 3, item_image: 'default.png', status: 'available', bought_price: '22000' },
          { item_id: 4, item_name: 'Heavy Duty Steel Hammer', category: 'Hardware Goods', price_per_unit: 1200.00, stock_quantity: 20, item_image: 'default.png', status: 'available', bought_price: '750' },
          { item_id: 5, item_name: 'PVC Pipe 1 inch', category: 'Hardware Goods', price_per_unit: 450.00, stock_quantity: 100, item_image: 'default.png', status: 'available', bought_price: '280' },
          { item_id: 6, item_name: 'Assorted Screws Box', category: 'Hardware Goods', price_per_unit: 850.00, stock_quantity: 50, item_image: 'default.png', status: 'available', bought_price: '500' },
          { item_id: 7, item_name: 'Portable Concrete Mixer', category: 'Rental Items', price_per_unit: 3500.00, stock_quantity: 2, item_image: 'default.png', status: 'available', bought_price: '75000' },
          { item_id: 8, item_name: 'Steel Scaffolding Set', category: 'Rental Items', price_per_unit: 1500.00, stock_quantity: 15, item_image: 'default.png', status: 'available', bought_price: '30000' },
          { item_id: 9, item_name: 'Industrial Wet Vacuum', category: 'Rental Items', price_per_unit: 2000.00, stock_quantity: 4, item_image: 'default.png', status: 'available', bought_price: '40000' }
        ];
      }

      // If cash_sessions is empty, provision an active day session for seamless POS operation
      if (this.cash_sessions.length === 0) {
        this.cash_sessions = [
          {
            id: 1,
            user_id: 2,
            opening_balance: 5000.00,
            closing_balance: null,
            opened_at: new Date().toISOString(),
            closed_at: null,
            status: 'open'
          }
        ];
      }

      // Calculate next IDs based on loaded data
      this.recalculateNextIds();

      console.log(`[Database] Successfully loaded from ${filePath}: ${this.users.length} users, ${this.customers.length} customers, ${this.inventory.length} items, ${this.cash_sessions.length} sessions, ${this.transactions.length} transactions.`);
    } catch (err) {
      console.error(`[Database] Error parsing ${filePath}:`, err);
      this.initFallbacks();
    }
  }

  recalculateNextIds() {
    this.nextUserId = Math.max(0, ...this.users.map(u => Number(u.user_id) || 0)) + 1;
    this.nextItemId = Math.max(0, ...this.inventory.map(i => Number(i.item_id) || 0)) + 1;
    this.nextSessionId = Math.max(0, ...this.cash_sessions.map(s => Number(s.id) || 0)) + 1;
    this.nextBillNumber = Math.max(1000, ...this.transactions.map(t => Number(t.bill_number) || 0)) + 1;
    this.nextTxItemId = Math.max(0, ...this.transaction_items.map(ti => Number(ti.id) || 0)) + 1;
  }

  initFallbacks() {
    this.users = [
      { user_id: 1, username: 'sa', password: 'sa@2026', role: 'super_admin' },
      { user_id: 2, username: 'admin', password: 'admin@2026', role: 'admin' }
    ];
    this.customers = [
      { nic_number: '199012345678', phone_number: '0771112222', full_name: 'John Doe Construction', address: '123 Builder Lane, Colombo' },
      { nic_number: '198598765432', phone_number: '0714445555', full_name: 'Jane Smith Renovations', address: '456 Fixit Street, Kandy' },
      { nic_number: '200024681357', phone_number: '0758889999', full_name: 'Michael Silva Mechanics', address: '789 Garage Road, Galle' }
    ];
    this.inventory = [
      { item_id: 1, item_name: 'Makita Cordless Drill', category: 'Power Tools', price_per_unit: 15000.00, stock_quantity: 10, item_image: 'default.png', status: 'available', bought_price: '11000' },
      { item_id: 2, item_name: 'Bosch Angle Grinder', category: 'Power Tools', price_per_unit: 12500.00, stock_quantity: 5, item_image: 'default.png', status: 'available', bought_price: '9500' },
      { item_id: 3, item_name: 'DeWalt Circular Saw', category: 'Power Tools', price_per_unit: 28000.00, stock_quantity: 3, item_image: 'default.png', status: 'available', bought_price: '22000' },
      { item_id: 4, item_name: 'Heavy Duty Steel Hammer', category: 'Hardware Goods', price_per_unit: 1200.00, stock_quantity: 20, item_image: 'default.png', status: 'available', bought_price: '750' },
      { item_id: 5, item_name: 'PVC Pipe 1 inch', category: 'Hardware Goods', price_per_unit: 450.00, stock_quantity: 100, item_image: 'default.png', status: 'available', bought_price: '280' },
      { item_id: 6, item_name: 'Assorted Screws Box', category: 'Hardware Goods', price_per_unit: 850.00, stock_quantity: 50, item_image: 'default.png', status: 'available', bought_price: '500' },
      { item_id: 7, item_name: 'Portable Concrete Mixer', category: 'Rental Items', price_per_unit: 3500.00, stock_quantity: 2, item_image: 'default.png', status: 'available', bought_price: '75000' },
      { item_id: 8, item_name: 'Steel Scaffolding Set', category: 'Rental Items', price_per_unit: 1500.00, stock_quantity: 15, item_image: 'default.png', status: 'available', bought_price: '30000' },
      { item_id: 9, item_name: 'Industrial Wet Vacuum', category: 'Rental Items', price_per_unit: 2000.00, stock_quantity: 4, item_image: 'default.png', status: 'available', bought_price: '40000' }
    ];
    this.cash_sessions = [
      { id: 1, user_id: 2, opening_balance: 5000.00, closing_balance: null, opened_at: new Date().toISOString(), closed_at: null, status: 'open' }
    ];
    this.transactions = [];
    this.transaction_items = [];
    this.recalculateNextIds();
  }

  parseSqlInserts(sqlContent) {
    const data = {
      users: [],
      customers: [],
      inventory: [],
      cash_sessions: [],
      transactions: [],
      transaction_items: []
    };

    const insertRegex = /INSERT\s+INTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?\s*\(([^)]+)\)\s*VALUES\s*([\s\S]*?);/gi;
    let match;

    while ((match = insertRegex.exec(sqlContent)) !== null) {
      const table = match[1].toLowerCase();
      if (!data[table]) continue;

      const columns = match[2].split(',').map(c => c.trim().replace(/[`"']/g, ''));
      const valuesBlock = match[3].trim();

      let inString = false;
      let stringChar = '';
      let currentVal = '';
      let currentRow = [];
      let inRow = false;

      for (let i = 0; i < valuesBlock.length; i++) {
        const char = valuesBlock[i];
        const prevChar = i > 0 ? valuesBlock[i - 1] : '';

        if (!inRow) {
          if (char === '(') {
            inRow = true;
            currentRow = [];
            currentVal = '';
            inString = false;
          }
          continue;
        }

        if (inString) {
          if (char === stringChar && prevChar !== '\\') {
            inString = false;
            currentVal += char;
          } else {
            currentVal += char;
          }
        } else {
          if (char === "'" || char === '"') {
            inString = true;
            stringChar = char;
            currentVal += char;
          } else if (char === ',') {
            currentRow.push(currentVal.trim());
            currentVal = '';
          } else if (char === ')') {
            currentRow.push(currentVal.trim());
            inRow = false;

            const obj = {};
            columns.forEach((col, idx) => {
              let v = currentRow[idx];
              if (v === undefined || v === 'NULL' || v === 'null') {
                v = null;
              } else if ((v.startsWith("'") && v.endsWith("'")) || (v.startsWith('"') && v.endsWith('"'))) {
                v = v.slice(1, -1).replace(/\\'/g, "'").replace(/\\"/g, '"');
              } else if (!isNaN(Number(v)) && v !== '') {
                v = Number(v);
              }
              obj[col] = v;
            });
            data[table].push(obj);
            currentVal = '';
          } else {
            currentVal += char;
          }
        }
      }
    }
    return data;
  }

  // -------------------------------------------------------------
  // Persist State to db.sql
  // -------------------------------------------------------------
  saveToSqlFile(filePath = SQL_FILE_PATH) {
    try {
      const lines = [
        '-- MCATS Database Dump',
        '-- Synchronized: ' + new Date().toISOString(),
        '',
        'SET FOREIGN_KEY_CHECKS = 0;',
        'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
        'START TRANSACTION;',
        'SET time_zone = "+00:00";',
        '',
        'CREATE DATABASE IF NOT EXISTS `mcats`;',
        'USE `mcats`;',
        '',
        'DROP TABLE IF EXISTS `transaction_items`;',
        'DROP TABLE IF EXISTS `transactions`;',
        'DROP TABLE IF EXISTS `cash_sessions`;',
        'DROP TABLE IF EXISTS `customers`;',
        'DROP TABLE IF EXISTS `inventory`;',
        'DROP TABLE IF EXISTS `users`;',
        '',
        'CREATE TABLE `customers` (',
        '  `nic_number` varchar(20) NOT NULL,',
        '  `phone_number` varchar(15) NOT NULL,',
        '  `full_name` varchar(100) NOT NULL,',
        '  `address` text DEFAULT NULL,',
        '  PRIMARY KEY (`nic_number`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        '',
        'CREATE TABLE `users` (',
        '  `user_id` int(11) NOT NULL AUTO_INCREMENT,',
        '  `username` varchar(50) NOT NULL,',
        '  `password` varchar(255) NOT NULL,',
        '  `role` enum(\'super_admin\',\'admin\') NOT NULL,',
        '  PRIMARY KEY (`user_id`),',
        '  UNIQUE KEY `username` (`username`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        '',
        'CREATE TABLE `cash_sessions` (',
        '  `id` int(11) NOT NULL AUTO_INCREMENT,',
        '  `user_id` int(11) NOT NULL,',
        '  `opening_balance` decimal(10,2) NOT NULL,',
        '  `closing_balance` decimal(10,2) DEFAULT NULL,',
        '  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),',
        '  `closed_at` timestamp NULL DEFAULT NULL,',
        '  `status` enum(\'open\',\'closed\') DEFAULT \'open\',',
        '  PRIMARY KEY (`id`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        '',
        'CREATE TABLE `inventory` (',
        '  `item_id` int(11) NOT NULL AUTO_INCREMENT,',
        '  `item_name` varchar(100) NOT NULL,',
        '  `category` enum(\'Power Tools\',\'Hardware Goods\',\'Rental Items\') NOT NULL,',
        '  `price_per_unit` decimal(10,2) NOT NULL,',
        '  `stock_quantity` int(11) DEFAULT 0,',
        '  `item_image` varchar(255) DEFAULT \'default.png\',',
        '  `status` varchar(50) DEFAULT \'available\',',
        '  `bought_price` varchar(50) DEFAULT NULL,',
        '  PRIMARY KEY (`item_id`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        '',
        'CREATE TABLE `transactions` (',
        '  `bill_number` int(11) NOT NULL AUTO_INCREMENT,',
        '  `session_id` int(11) DEFAULT NULL,',
        '  `customer_nic` varchar(20) DEFAULT NULL,',
        '  `type` varchar(50) NOT NULL,',
        '  `subtotal_lkr` decimal(10,2) DEFAULT 0.00,',
        '  `discount_type` varchar(20) DEFAULT \'none\',',
        '  `discount_value` decimal(10,2) DEFAULT 0.00,',
        '  `discount_amount` decimal(10,2) DEFAULT 0.00,',
        '  `total_lkr` decimal(10,2) NOT NULL,',
        '  `received_amount` decimal(10,2) DEFAULT 0.00,',
        '  `balance_amount` decimal(10,2) DEFAULT 0.00,',
        '  `advance_paid` decimal(10,2) DEFAULT 0.00,',
        '  `free_equipment` text DEFAULT NULL,',
        '  `notes` text DEFAULT NULL,',
        '  `status` varchar(50) DEFAULT \'ongoing\',',
        '  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),',
        '  PRIMARY KEY (`bill_number`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        '',
        'CREATE TABLE `transaction_items` (',
        '  `id` int(11) NOT NULL AUTO_INCREMENT,',
        '  `bill_number` int(11) DEFAULT NULL,',
        '  `item_id` int(11) DEFAULT NULL,',
        '  `quantity` int(11) DEFAULT NULL,',
        '  `unit_price` decimal(10,2) DEFAULT NULL,',
        '  `billed_days` int(11) DEFAULT NULL,',
        '  `is_free` tinyint(1) DEFAULT 0,',
        '  PRIMARY KEY (`id`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
        ''
      ];

      if (this.customers && this.customers.length) {
        lines.push('-- Dumping customers');
        lines.push('INSERT INTO `customers` (`nic_number`, `phone_number`, `full_name`, `address`) VALUES');
        const rows = this.customers.map(c => `  (${str(c.nic_number)}, ${str(c.phone_number)}, ${str(c.full_name)}, ${str(c.address)})`);
        lines.push(rows.join(',\n') + ';\n');
      }

      if (this.users && this.users.length) {
        lines.push('-- Dumping users');
        lines.push('INSERT INTO `users` (`user_id`, `username`, `password`, `role`) VALUES');
        const rows = this.users.map(u => `  (${num(u.user_id)}, ${str(u.username)}, ${str(u.password)}, ${str(u.role)})`);
        lines.push(rows.join(',\n') + ';\n');
      }

      if (this.inventory && this.inventory.length) {
        lines.push('-- Dumping inventory');
        lines.push('INSERT INTO `inventory` (`item_id`, `item_name`, `category`, `price_per_unit`, `stock_quantity`, `item_image`, `status`, `bought_price`) VALUES');
        const rows = this.inventory.map(i => `  (${num(i.item_id)}, ${str(i.item_name)}, ${str(i.category)}, ${num(i.price_per_unit)}, ${num(i.stock_quantity)}, ${str(i.item_image || 'default.png')}, ${str(i.status || 'available')}, ${str(i.bought_price)})`);
        lines.push(rows.join(',\n') + ';\n');
      }

      if (this.cash_sessions && this.cash_sessions.length) {
        lines.push('-- Dumping cash_sessions');
        lines.push('INSERT INTO `cash_sessions` (`id`, `user_id`, `opening_balance`, `closing_balance`, `opened_at`, `closed_at`, `status`) VALUES');
        const rows = this.cash_sessions.map(s => `  (${num(s.id)}, ${num(s.user_id)}, ${num(s.opening_balance)}, ${num(s.closing_balance)}, ${str(s.opened_at)}, ${str(s.closed_at)}, ${str(s.status)})`);
        lines.push(rows.join(',\n') + ';\n');
      }

      if (this.transactions && this.transactions.length) {
        lines.push('-- Dumping transactions');
        lines.push('INSERT INTO `transactions` (`bill_number`, `session_id`, `customer_nic`, `type`, `subtotal_lkr`, `discount_type`, `discount_value`, `discount_amount`, `total_lkr`, `received_amount`, `balance_amount`, `advance_paid`, `free_equipment`, `notes`, `status`, `transaction_date`) VALUES');
        const rows = this.transactions.map(t => {
          const subtotal = t.subtotal_lkr !== undefined && t.subtotal_lkr !== null ? t.subtotal_lkr : t.total_lkr;
          const discType = t.discount_type || 'none';
          const discVal = t.discount_value || 0;
          const discAmt = t.discount_amount || 0;
          return `  (${num(t.bill_number)}, ${num(t.session_id)}, ${str(t.customer_nic)}, ${str(t.type)}, ${num(subtotal)}, ${str(discType)}, ${num(discVal)}, ${num(discAmt)}, ${num(t.total_lkr)}, ${num(t.received_amount)}, ${num(t.balance_amount)}, ${num(t.advance_paid)}, ${str(t.free_equipment)}, ${str(t.notes)}, ${str(t.status)}, ${str(t.transaction_date)})`;
        });
        lines.push(rows.join(',\n') + ';\n');
      }

      if (this.transaction_items && this.transaction_items.length) {
        lines.push('-- Dumping transaction_items');
        lines.push('INSERT INTO `transaction_items` (`id`, `bill_number`, `item_id`, `quantity`, `unit_price`, `billed_days`, `is_free`) VALUES');
        const rows = this.transaction_items.map(ti => `  (${num(ti.id)}, ${num(ti.bill_number)}, ${num(ti.item_id)}, ${num(ti.quantity)}, ${num(ti.unit_price)}, ${num(ti.billed_days)}, ${num(ti.is_free)})`);
        lines.push(rows.join(',\n') + ';\n');
      }

      lines.push('COMMIT;');
      lines.push('SET FOREIGN_KEY_CHECKS = 1;');
      lines.push('');

      fs.writeFileSync(filePath, lines.join('\n'), 'utf8');
    } catch (err) {
      console.error('[Database] Failed to write db.sql:', err);
    }
  }

  // --- Users ---
  findUserByUsername(username) {
    if (!username) return null;
    const target = String(username).trim().toLowerCase();
    return this.users.find(u => String(u.username).trim().toLowerCase() === target);
  }

  findUserById(id) {
    return this.users.find(u => u.user_id === Number(id));
  }

  getAllUsers() {
    return [...this.users];
  }

  addUser(username, password, role = 'admin') {
    const user = { user_id: this.nextUserId++, username, password, role };
    this.users.push(user);
    this.saveToSqlFile();
    return user;
  }

  updateUser(userId, { role, password }) {
    const user = this.findUserById(userId);
    if (user) {
      if (role) user.role = role;
      if (password && password.trim()) user.password = password.trim();
      this.saveToSqlFile();
      return user;
    }
    return null;
  }

  updateUserPassword(userId, newPassword) {
    const user = this.findUserById(userId);
    if (user) {
      user.password = newPassword;
      this.saveToSqlFile();
      return true;
    }
    return false;
  }

  deleteUser(userId) {
    const idx = this.users.findIndex(u => u.user_id === Number(userId));
    if (idx !== -1) {
      this.users.splice(idx, 1);
      this.saveToSqlFile();
      return true;
    }
    return false;
  }

  // --- Customers ---
  getAllCustomers() {
    return [...this.customers];
  }

  getCustomerByNic(nic) {
    if (!nic) return null;
    return this.customers.find(c => String(c.nic_number) === String(nic)) || null;
  }

  findCustomer(query) {
    if (!query) return null;
    const q = String(query).toLowerCase().trim();
    return this.customers.find(c => 
      String(c.nic_number).toLowerCase() === q || 
      String(c.phone_number).toLowerCase() === q ||
      String(c.full_name).toLowerCase().includes(q)
    );
  }

  upsertCustomer(nic, phone, fullName, address) {
    const existing = this.customers.find(c => 
      String(c.nic_number) === String(nic) || 
      (phone && String(c.phone_number) === String(phone))
    );
    if (existing) {
      if (fullName) existing.full_name = fullName;
      if (phone) existing.phone_number = phone;
      if (address) existing.address = address;
      this.saveToSqlFile();
      return existing;
    }
    const newCust = {
      nic_number: String(nic || ('PH-' + (phone || Date.now()))),
      phone_number: String(phone || ''),
      full_name: String(fullName || 'Walk-in Customer'),
      address: String(address || '')
    };
    this.customers.push(newCust);
    this.saveToSqlFile();
    return newCust;
  }

  // --- Inventory ---
  getAllInventory() {
    return [...this.inventory];
  }

  getSaleableInventory() {
    return this.inventory.filter(i => i.category !== 'Rental Items');
  }

  getRentalInventory() {
    return this.inventory.filter(i => i.category === 'Rental Items');
  }

  getItemById(id) {
    return this.inventory.find(i => Number(i.item_id) === Number(id));
  }

  saveItem({ id, name, category, price, stock, status, image, boughtPrice }) {
    if (id && Number(id) > 0) {
      const item = this.getItemById(id);
      if (item) {
        item.item_name = name;
        item.category = category;
        item.price_per_unit = parseFloat(price) || 0;
        item.stock_quantity = parseInt(stock, 10) || 0;
        item.status = status || 'available';
        if (image) item.item_image = image;
        if (boughtPrice !== undefined) item.bought_price = boughtPrice || null;
        this.saveToSqlFile();
        return item;
      }
    }
    const newItem = {
      item_id: this.nextItemId++,
      item_name: name,
      category: category,
      price_per_unit: parseFloat(price) || 0,
      stock_quantity: parseInt(stock, 10) || 0,
      item_image: image || 'default.png',
      status: status || 'available',
      bought_price: boughtPrice || null
    };
    this.inventory.push(newItem);
    this.saveToSqlFile();
    return newItem;
  }

  deleteItem(id) {
    const isLinked = this.transaction_items.some(ti => Number(ti.item_id) === Number(id));
    if (isLinked) {
      throw new Error('Cannot delete this item because it is linked to existing transactions.');
    }
    const idx = this.inventory.findIndex(i => Number(i.item_id) === Number(id));
    if (idx !== -1) {
      this.inventory.splice(idx, 1);
      this.saveToSqlFile();
      return true;
    }
    return false;
  }

  // --- Cash Sessions ---
  getActiveSession() {
    return this.cash_sessions.find(s => s.status === 'open');
  }

  getAllSessions() {
    return this.cash_sessions.map(s => {
      const user = this.findUserById(s.user_id);
      return { ...s, username: user ? user.username : 'Unknown' };
    }).sort((a, b) => b.id - a.id);
  }

  openSession(userId, openingBalance) {
    const session = {
      id: this.nextSessionId++,
      user_id: Number(userId),
      opening_balance: parseFloat(openingBalance) || 0,
      closing_balance: null,
      opened_at: new Date().toISOString(),
      closed_at: null,
      status: 'open'
    };
    this.cash_sessions.push(session);
    this.saveToSqlFile();
    return session;
  }

  closeSession(sessionId, closingBalance) {
    const session = this.cash_sessions.find(s => Number(s.id) === Number(sessionId));
    if (session) {
      session.status = 'closed';
      session.closing_balance = parseFloat(closingBalance) || 0;
      session.closed_at = new Date().toISOString();
      this.saveToSqlFile();
      return session;
    }
    return null;
  }

  // --- Transactions ---
  createTransaction({
    sessionId,
    customerNic,
    type,
    subtotalLkr,
    discountType = 'none',
    discountValue = 0,
    discountAmount = 0,
    totalLkr,
    receivedAmount,
    balanceAmount,
    advancePaid = 0,
    freeEquipment = null,
    notes = null,
    status = 'completed',
    items = []
  }) {
    const billNumber = this.nextBillNumber++;
    const subtotal = subtotalLkr !== undefined ? parseFloat(subtotalLkr) : parseFloat(totalLkr) || 0;
    const discAmount = parseFloat(discountAmount) || 0;
    const total = totalLkr !== undefined ? parseFloat(totalLkr) : Math.max(0, subtotal - discAmount);

    const tx = {
      bill_number: billNumber,
      session_id: Number(sessionId) || null,
      customer_nic: customerNic ? String(customerNic) : null,
      type: type, // 'selling' or 'renting'
      subtotal_lkr: subtotal,
      discount_type: discountType || 'none',
      discount_value: parseFloat(discountValue) || 0,
      discount_amount: discAmount,
      total_lkr: total,
      received_amount: parseFloat(receivedAmount) || 0,
      balance_amount: parseFloat(balanceAmount) || 0,
      advance_paid: parseFloat(advancePaid) || 0,
      free_equipment: freeEquipment,
      notes: notes,
      status: status,
      transaction_date: new Date().toISOString()
    };
    this.transactions.push(tx);

    for (const it of items) {
      const txItem = {
        id: this.nextTxItemId++,
        bill_number: billNumber,
        item_id: Number(it.id || it.item_id),
        quantity: parseInt(it.qty || it.quantity, 10) || 1,
        unit_price: parseFloat(it.price || it.unit_price) || 0,
        billed_days: it.billed_days ? parseInt(it.billed_days, 10) : null,
        is_free: it.is_free ? 1 : 0
      };
      this.transaction_items.push(txItem);

      // Decrement stock or mark rented
      const invItem = this.getItemById(txItem.item_id);
      if (invItem) {
        if (type === 'selling') {
          invItem.stock_quantity = Math.max(0, invItem.stock_quantity - txItem.quantity);
        } else if (type === 'renting') {
          invItem.status = 'rented';
        }
      }
    }

    this.saveToSqlFile();
    return tx;
  }

  getTransaction(billNumber) {
    const tx = this.transactions.find(t => Number(t.bill_number) === Number(billNumber));
    if (!tx) return null;

    const items = this.transaction_items
      .filter(ti => Number(ti.bill_number) === Number(billNumber))
      .map(ti => {
        const item = this.getItemById(ti.item_id);
        return {
          ...ti,
          item_name: item ? item.item_name : 'Unknown Item',
          category: item ? item.category : ''
        };
      });

    const customer = tx.customer_nic ? this.customers.find(c => String(c.nic_number) === String(tx.customer_nic)) : null;

    return {
      ...tx,
      subtotal_lkr: tx.subtotal_lkr !== undefined && tx.subtotal_lkr !== null ? Number(tx.subtotal_lkr) : Number(tx.total_lkr || 0),
      discount_type: tx.discount_type || 'none',
      discount_value: Number(tx.discount_value || 0),
      discount_amount: Number(tx.discount_amount || 0),
      items,
      customer
    };
  }

  getAllTransactions() {
    return this.transactions.map(t => this.getTransaction(t.bill_number)).sort((a, b) => b.bill_number - a.bill_number);
  }

  getTodaySales() {
    const today = new Date().toISOString().split('T')[0];
    return this.transactions
      .filter(t => t.transaction_date.startsWith(today) && t.status !== 'cancelled')
      .reduce((sum, t) => sum + (Number(t.received_amount || 0) - Number(t.balance_amount || 0)), 0);
  }

  getTotalSales() {
    return this.transactions
      .filter(t => t.status !== 'cancelled')
      .reduce((sum, t) => sum + (Number(t.received_amount || 0) - Number(t.balance_amount || 0)), 0);
  }

  processRentalReturn(billNumber, daysPerItem = {}, cashReceived = 0) {
    const tx = this.transactions.find(t => Number(t.bill_number) === Number(billNumber));
    if (!tx) return null;

    const items = this.transaction_items.filter(ti => Number(ti.bill_number) === Number(billNumber));
    let finalFee = 0;

    for (const ti of items) {
      const days = parseInt(daysPerItem[ti.id] !== undefined ? daysPerItem[ti.id] : (daysPerItem[String(ti.id)] || 1), 10) || 1;
      ti.billed_days = days;
      finalFee += (ti.quantity * ti.unit_price * days);

      // Release inventory item back to available
      const invItem = this.getItemById(ti.item_id);
      if (invItem) {
        invItem.status = 'available';
      }
    }

    const newReceivedTotal = Number(tx.received_amount || 0) + parseFloat(cashReceived || 0);
    const newBalance = newReceivedTotal - finalFee;

    tx.status = 'returned';
    tx.total_lkr = finalFee;
    tx.received_amount = newReceivedTotal;
    tx.balance_amount = newBalance;

    this.saveToSqlFile();
    return tx;
  }

  returnRentalItems(billNumber, returnNote = '') {
    const tx = this.transactions.find(t => Number(t.bill_number) === Number(billNumber));
    if (!tx) return null;

    tx.status = 'completed';
    tx.notes = (tx.notes ? tx.notes + ' | ' : '') + (returnNote || 'Returned');

    const items = this.transaction_items.filter(ti => Number(ti.bill_number) === Number(billNumber));
    for (const ti of items) {
      const invItem = this.getItemById(ti.item_id);
      if (invItem && invItem.category === 'Rental Items') {
        invItem.status = 'available';
      }
    }

    this.saveToSqlFile();
    return tx;
  }

  // --- Change Requests Management (Quantity, Daily Rate, Cost Price) ---
  loadChangeRequests() {
    const reqFilePath = path.join(__dirname, 'change_requests.json');
    if (fs.existsSync(reqFilePath)) {
      try {
        const raw = fs.readFileSync(reqFilePath, 'utf8');
        this.change_requests = JSON.parse(raw) || [];
      } catch (e) {
        console.warn('[Database] Could not parse change_requests.json:', e.message);
        this.change_requests = [];
      }
    } else {
      // Provide initial sample requests for demonstration
      this.change_requests = [
        {
          id: 1,
          type: 'quantity',
          item_id: 1,
          item_name: 'Makita Cordless Drill',
          current_value: '10',
          requested_value: '15',
          reason: 'Supplier delivered 5 additional units under PO-4481',
          requested_by: 'admin',
          requested_at: new Date(Date.now() - 3600000 * 3).toISOString(),
          status: 'pending',
          sa_action_by: null,
          sa_action_at: null,
          sa_notes: null,
          completed_at: null,
          completed_by: null
        },
        {
          id: 2,
          type: 'daily_rate',
          item_id: 7,
          item_name: 'Portable Concrete Mixer',
          current_value: '3500.00',
          requested_value: '4000.00',
          reason: 'Reflects revised daily rental market standard for high-capacity mixer',
          requested_by: 'admin',
          requested_at: new Date(Date.now() - 3600000 * 5).toISOString(),
          status: 'approved',
          sa_action_by: 'sa',
          sa_action_at: new Date(Date.now() - 3600000 * 1).toISOString(),
          sa_notes: 'Approved as requested. Authorized cashier can apply rate update.',
          completed_at: null,
          completed_by: null
        },
        {
          id: 3,
          type: 'cost_price',
          item_id: 2,
          item_name: 'Bosch Angle Grinder',
          current_value: '9500',
          requested_value: '10200',
          reason: 'Supplier cost cipher revision after inflation surcharge',
          requested_by: 'admin',
          requested_at: new Date(Date.now() - 3600000 * 6).toISOString(),
          status: 'pending',
          sa_action_by: null,
          sa_action_at: null,
          sa_notes: null,
          completed_at: null,
          completed_by: null
        }
      ];
      this.saveChangeRequests();
    }
    this.nextRequestId = Math.max(0, ...this.change_requests.map(r => Number(r.id) || 0)) + 1;
  }

  saveChangeRequests() {
    const reqFilePath = path.join(__dirname, 'change_requests.json');
    try {
      fs.writeFileSync(reqFilePath, JSON.stringify(this.change_requests, null, 2), 'utf8');
    } catch (e) {
      console.error('[Database] Failed to write change_requests.json:', e);
    }
  }

  createChangeRequest({ type, itemId, requestedValue, reason, requestedBy }) {
    const validTypes = ['quantity', 'daily_rate', 'cost_price'];
    if (!validTypes.includes(type)) {
      throw new Error(`Invalid request type: ${type}. Expected: ${validTypes.join(', ')}`);
    }
    const item = this.getItemById(itemId);
    if (!item) {
      throw new Error(`Item not found with ID ${itemId}`);
    }

    let currentVal = '';
    if (type === 'quantity') currentVal = String(item.stock_quantity);
    else if (type === 'daily_rate') currentVal = String(item.price_per_unit);
    else if (type === 'cost_price') currentVal = String(item.bought_price || '');

    const newReq = {
      id: this.nextRequestId++,
      type: type,
      item_id: Number(itemId),
      item_name: item.item_name,
      current_value: currentVal,
      requested_value: String(requestedValue).trim(),
      reason: String(reason || '').trim(),
      requested_by: requestedBy || 'admin',
      requested_at: new Date().toISOString(),
      status: 'pending',
      sa_action_by: null,
      sa_action_at: null,
      sa_notes: null,
      completed_at: null,
      completed_by: null
    };

    this.change_requests.unshift(newReq);
    this.saveChangeRequests();
    return newReq;
  }

  getChangeRequests({ type, status } = {}) {
    let list = [...this.change_requests];
    if (type) list = list.filter(r => r.type === type);
    if (status) list = list.filter(r => r.status === status);
    return list.sort((a, b) => b.id - a.id);
  }

  getChangeRequestById(id) {
    return this.change_requests.find(r => Number(r.id) === Number(id)) || null;
  }

  approveChangeRequest(id, saUsername, notes = '') {
    const req = this.getChangeRequestById(id);
    if (!req) throw new Error('Request not found');
    req.status = 'approved';
    req.sa_action_by = saUsername || 'sa';
    req.sa_action_at = new Date().toISOString();
    req.sa_notes = notes || 'Approved by Super Admin';
    this.saveChangeRequests();
    return req;
  }

  cancelChangeRequest(id, saUsername, notes = '') {
    const req = this.getChangeRequestById(id);
    if (!req) throw new Error('Request not found');
    req.status = 'cancelled';
    req.sa_action_by = saUsername || 'sa';
    req.action_by = saUsername || 'sa';
    req.sa_action_at = new Date().toISOString();
    req.sa_notes = notes || 'Cancelled by Super Admin';
    
    // Explicitly confirm and preserve original values in inventory
    const item = this.getItemById(req.item_id);
    if (item) {
      console.log(`[Change Request Cancelled] Req #${req.id} for "${item.item_name}" cancelled by ${saUsername}. Item values preserved: Qty=${item.stock_quantity}, Price=${item.price_per_unit}, Cost=${item.bought_price}`);
      this.saveToSqlFile();
    }

    this.saveChangeRequests();
    return { req, item };
  }

  rejectChangeRequest(id, saUsername, notes = '') {
    return this.cancelChangeRequest(id, saUsername, notes).req;
  }

  completeChangeRequest(id, completedBy) {
    const req = this.getChangeRequestById(id);
    if (!req) return null;
    req.status = 'completed';
    req.completed_at = new Date().toISOString();
    req.completed_by = completedBy || 'admin';
    this.saveChangeRequests();
    return req;
  }

  getPendingRequestsCount(type = null) {
    if (type) return this.change_requests.filter(r => r.status === 'pending' && r.type === type).length;
    return this.change_requests.filter(r => r.status === 'pending').length;
  }

  getApprovedRequestsCount(type = null) {
    if (type) return this.change_requests.filter(r => r.status === 'approved' && r.type === type).length;
    return this.change_requests.filter(r => r.status === 'approved').length;
  }

  getApprovedRequestsForAdmin(username = null) {
    return this.change_requests.filter(r => r.status === 'approved');
  }
}

export const db = new Database();
export default db;
