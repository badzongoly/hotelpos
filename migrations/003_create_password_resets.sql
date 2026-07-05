-- Password reset token table.
--
-- Tokens are never stored in plain text. The emailed URL contains the raw token,
-- while the database stores only a SHA-256 hash of that token.

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_password_resets_user (user_id),
  INDEX idx_password_resets_expires (expires_at),
  INDEX idx_password_resets_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
