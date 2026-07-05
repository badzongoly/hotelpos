-- hotelpos baseline schema.
--
-- Design goals from the SRS:
-- - Preserve historical booking rates and extra prices.
-- - Void financial records instead of deleting them.
-- - Keep audit logs for sensitive changes.
-- - Support role-guarded JSON APIs and AJAX workflows.
-- - Index common reporting/filtering columns.
-- Application users. Roles are enforced server-side by API controllers.
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role ENUM('administrator','manager','reception','auditor') NOT NULL DEFAULT 'reception',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Room master data. rate is the current price; bookings copy it for history.
CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  type VARCHAR(80) NOT NULL,
  rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('vacant','occupied','dirty','maintenance') NOT NULL DEFAULT 'vacant',
  active TINYINT(1) NOT NULL DEFAULT 1,
  occupancy_counted TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_rooms_status (status),
  INDEX idx_rooms_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guest stays. rate_per_night is immutable historical pricing for the bill.
CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  rate_per_night DECIMAL(12,2) NOT NULL,
  guest_name VARCHAR(140) NOT NULL,
  gender VARCHAR(30) NULL,
  nationality VARCHAR(100) NULL,
  contact VARCHAR(80) NULL,
  checkin_at DATETIME NOT NULL,
  checkout_at DATETIME NULL,
  status ENUM('active','checked_out','cancelled') NOT NULL DEFAULT 'active',
  cancellation_reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_bookings_room_status (room_id, status),
  INDEX idx_bookings_dates (checkin_at, checkout_at),
  INDEX idx_bookings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  stock_tracked TINYINT(1) NOT NULL DEFAULT 1,
  stock_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_extras_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extras sold to a booking. unit_price is copied at sale time for history.
CREATE TABLE IF NOT EXISTS booking_extras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  extra_id INT NULL,
  description VARCHAR(180) NULL,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(255) NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (extra_id) REFERENCES extras(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_booking_extras_booking (booking_id),
  INDEX idx_booking_extras_void (voided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment ledger. Voided rows remain for audit but are excluded from totals.
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  method ENUM('cash','momo','card','bank','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(255) NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_payments_booking (booking_id),
  INDEX idx_payments_created (created_at),
  INDEX idx_payments_void (voided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only stock movement history for inventory reconciliation.
CREATE TABLE IF NOT EXISTS stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  extra_id INT NOT NULL,
  movement_type ENUM('in','out','adjustment','return','waste') NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NULL,
  note VARCHAR(255) NULL,
  ref_type VARCHAR(50) NULL,
  ref_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (extra_id) REFERENCES extras(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_stock_extra (extra_id),
  INDEX idx_stock_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(90) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expense ledger. Voided rows remain for audit but are excluded from reports.
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  category_id INT NOT NULL,
  method ENUM('cash','momo','card','bank','other') NOT NULL DEFAULT 'cash',
  amount DECIMAL(12,2) NOT NULL,
  vendor VARCHAR(140) NULL,
  reference_no VARCHAR(100) NULL,
  description VARCHAR(255) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(255) NULL,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_expenses_date (expense_date),
  INDEX idx_expenses_void (voided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sensitive action history. Stores old/new JSON where practical.
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(100) NOT NULL,
  entity_id INT NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_audit_entity (entity, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small runtime settings such as stock policy and currency display.
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by INT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(190) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

