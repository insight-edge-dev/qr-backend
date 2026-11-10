-- NOT EXECUTED. Fresh isolated staging server only. Never use --force.
-- Intentionally no IF NOT EXISTS: stop on an existing database; no DROP/DELETE/TRUNCATE.
-- No production data, users, passwords, grants, or authentication credentials.
CREATE DATABASE qr_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qr_staging;
SET time_zone = '+00:00';

CREATE TABLE employees (
  id varchar(20) NOT NULL,
  name varchar(100) NOT NULL,
  scanned_at datetime DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lunch_scans (
  id int NOT NULL AUTO_INCREMENT,
  employee_id varchar(20) DEFAULT NULL,
  timestamp datetime DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY employee_id (employee_id),
  CONSTRAINT lunch_scans_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE random_scans (
  id int NOT NULL AUTO_INCREMENT,
  qr_data text NOT NULL,
  scanned_at datetime DEFAULT current_timestamp(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  request_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id varchar(64) NOT NULL,
  action varchar(64) NOT NULL,
  record_id int DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY audit_request (request_id),
  KEY audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT INTO employees (id, name, scanned_at) VALUES
('STAGE-EMP-001', 'Synthetic Staging Employee One', NULL),
('STAGE-EMP-002', 'Synthetic Staging Employee Two', NULL);
INSERT INTO random_scans (qr_data, scanned_at) VALUES
('SYNTHETIC-QR-001', '2000-01-01 00:00:00'),
('SYNTHETIC-QR-002', '2000-01-01 00:00:01'),
('SYNTHETIC-QR-003', '2000-01-01 00:00:02');
COMMIT;
-- lunch_scans and audit_events start empty. Attendance endpoints remain disabled.
