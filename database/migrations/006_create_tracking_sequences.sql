-- Per-department, per-year counter for tracking numbers (locked with SELECT ... FOR UPDATE).
CREATE TABLE tracking_sequences (
  department_id INT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (department_id, year),
  CONSTRAINT fk_tracking_sequences_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
