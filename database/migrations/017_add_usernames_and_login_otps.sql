-- Passwordless sign-in: users sign in with a username (set by an administrator) and a
-- one-time code emailed to them. Existing users get a username from their email address
-- (the part before "@"); duplicates get ".<id>" appended. Admins can change it later.
ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER staff_id;

UPDATE users SET username = LEFT(REGEXP_REPLACE(LOWER(SUBSTRING_INDEX(email, '@', 1)), '[^a-z0-9._-]', ''), 40);

UPDATE users SET username = LOWER(REGEXP_REPLACE(staff_id, '[^A-Za-z0-9._-]', '')) WHERE username IS NULL OR CHAR_LENGTH(username) < 3;

UPDATE users u
  JOIN (SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1) dup ON dup.username = u.username
   SET u.username = CONCAT(u.username, '.', u.id);

ALTER TABLE users MODIFY username VARCHAR(50) NOT NULL, ADD UNIQUE INDEX uq_users_username (username);

-- One-time sign-in codes. Only a SHA-256 hash of the code is stored.
CREATE TABLE login_otps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_login_otps_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_login_otps_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
