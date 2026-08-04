CREATE TABLE IF NOT EXISTS tbl_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  accID VARCHAR(255) NOT NULL,
  selector CHAR(16) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_selector (selector),
  INDEX idx_accid (accID),
  INDEX idx_expires (expires_at),
  CONSTRAINT fk_password_resets_accid
    FOREIGN KEY (accID) REFERENCES tbl_useracc(accID)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
