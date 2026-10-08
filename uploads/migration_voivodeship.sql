-- Migration: voivodeship support
-- Run once, e.g.: mysql -u USER -p DB_NAME < migration_voivodeship.sql
-- Plain MySQL has no IF NOT EXISTS for columns: if my_voivodeship_ref
-- already exists, drop the ALTER/CREATE INDEX lines before re-running.

-- Lookup table for Polish voivodeships (user fills in the values):
-- indicator = single capital letter, e.g. 'M' = Mazowieckie.
CREATE TABLE IF NOT EXISTS sp_voivodeships (
    indicator CHAR(1) NOT NULL COMMENT 'Single capital letter',
    name      VARCHAR(100) NOT NULL COMMENT 'Voivodeship name',
    PRIMARY KEY (indicator)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reference on each QSO (NULL = not set for this upload)
ALTER TABLE qso_log ADD COLUMN my_voivodeship_ref CHAR(1) NULL;
CREATE INDEX idx_qso_my_voivodeship_ref ON qso_log (my_voivodeship_ref);
