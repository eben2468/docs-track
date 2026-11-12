-- Phase 8 performance (spec §10.2: pages under 500 ms with 50k documents).
-- documents.current_movement_id points at the movement that brought the document to its
-- current holder (the last received one), set by MovementService::receive(). It replaces a
-- per-row "latest received movement" subquery in every list, dashboard and alert query.
ALTER TABLE documents
  ADD COLUMN current_movement_id INT UNSIGNED NULL AFTER current_holder_id,
  ADD CONSTRAINT fk_documents_current_movement FOREIGN KEY (current_movement_id) REFERENCES document_movements(id);

UPDATE documents d
   SET d.current_movement_id = (
       SELECT x.id FROM document_movements x
       WHERE x.document_id = d.id AND x.status = 'received'
       ORDER BY x.received_at DESC, x.id DESC LIMIT 1
   );

-- Open-movement join (document_id + status) and the unreceived-alert scan (status + sent_at).
CREATE INDEX idx_movements_document_status ON document_movements (document_id, status);
CREATE INDEX idx_movements_status_sent ON document_movements (status, sent_at);

-- "Registered by us" sorted by date, "completed this month", and the notification bell.
CREATE INDEX idx_documents_origin_created ON documents (origin_department_id, created_at);
CREATE INDEX idx_documents_status_completed ON documents (status, completed_at);
CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at);
