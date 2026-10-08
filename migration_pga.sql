-- Migration: add my_pga_ref to qso_log (PGA award ref extracted from QSLMSG)
-- Run once, e.g.: mysql -u USER -p DB_NAME < migration_pga.sql
-- Plain MySQL has no IF NOT EXISTS for columns: if the column already exists,
-- skip this migration.

ALTER TABLE qso_log ADD COLUMN my_pga_ref VARCHAR(10) NULL;
CREATE INDEX idx_qso_my_pga_ref ON qso_log (my_pga_ref);
