-- Circular reference departments <-> users, so the HOD FK is added after users exists.
ALTER TABLE departments
  ADD CONSTRAINT fk_departments_head_user FOREIGN KEY (head_user_id) REFERENCES users(id);
