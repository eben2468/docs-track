-- Departments / offices. The head_user_id FK is added in 003 (users does not exist yet).
CREATE TABLE departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(6) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
  head_user_id INT UNSIGNED NULL,
  location VARCHAR(150) NULL,
  email VARCHAR(150) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
