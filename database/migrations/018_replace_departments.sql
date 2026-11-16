-- Replace the sample departments with VVU's actual departments.
-- Departments are never deleted (users, documents and movement history reference them), so:
--   1. sample departments with a direct equivalent are renamed in place (users and history follow),
--   2. the real departments are inserted (or reactivated/renamed if the code already exists),
--   3. every other department is deactivated.
-- Codes must be 2-6 letters (they form part of tracking numbers, e.g. VVU-FIN-2026-00001).

UPDATE departments SET code = 'FIN', name = 'Finance' WHERE code = 'BUR';
UPDATE departments SET code = 'HRM', name = 'HRM (Human Resource Management)' WHERE code = 'HR';
UPDATE departments SET code = 'ITS', name = 'ITS' WHERE code = 'ICT';
UPDATE departments SET code = 'DOS', name = 'Dean of Students' WHERE code = 'SAF';
UPDATE departments SET code = 'SOB', name = 'School of Business' WHERE code = 'FOB';

INSERT INTO departments (code, name) VALUES
  ('AUD', 'Audit'),
  ('CHP', 'Chaplaincy'),
  ('FIN', 'Finance'),
  ('ACC', 'Accounts'),
  ('HRM', 'HRM (Human Resource Management)'),
  ('FDS', 'Food Services'),
  ('STR', 'Stores'),
  ('RAD', 'Radio'),
  ('LIB', 'Library Services'),
  ('PPL', 'Physical Plant'),
  ('PRC', 'Procurement'),
  ('REG', 'Registrar'),
  ('VC', 'VC'),
  ('PVC', 'Pro VC'),
  ('DOS', 'Dean of Students'),
  ('SEC', 'Security'),
  ('ADM', 'Admissions & Records'),
  ('ITS', 'ITS'),
  ('HOR', 'Hall of Residence'),
  ('UREL', 'University Relations'),
  ('POST', 'Post Office'),
  ('TRN', 'Transport'),
  ('UENT', 'University Enterprises'),
  ('UFRM', 'University Farms'),
  ('VEN', 'Ventures'),
  ('GED', 'General Education'),
  ('HOS', 'Hospital'),
  ('BAK', 'Bakery'),
  ('TRS', 'Treasurer'),
  ('SOB', 'School of Business'),
  ('GRD', 'Graduate School'),
  ('THEO', 'Theology Department'),
  ('CNS', 'Counselling'),
  ('BAS', 'Basic School'),
  ('CSC', 'Computer Science Department')
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1;

UPDATE departments SET is_active = 0
 WHERE code NOT IN ('AUD', 'CHP', 'FIN', 'ACC', 'HRM', 'FDS', 'STR', 'RAD', 'LIB', 'PPL', 'PRC', 'REG',
                    'VC', 'PVC', 'DOS', 'SEC', 'ADM', 'ITS', 'HOR', 'UREL', 'POST', 'TRN', 'UENT',
                    'UFRM', 'VEN', 'GED', 'HOS', 'BAK', 'TRS', 'SOB', 'GRD', 'THEO', 'CNS', 'BAS', 'CSC');
