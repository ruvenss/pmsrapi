-- DEV/TEST fixture data. ⚠ NEVER SHIP TO PRODUCTION.
USE pmsrapi_test;

CREATE TABLE IF NOT EXISTS clients (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120)  NOT NULL,
  email      VARCHAR(190)  NOT NULL UNIQUE,           -- NOT NULL, no default:
  status     VARCHAR(20)   NOT NULL DEFAULT 'active',   -- inserting without it forces a 5xx
  balance    DECIMAL(10,2) NOT NULL DEFAULT 0,          -- numeric column for SUM/AVG/MIN/MAX
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clients (name, email, status, balance) VALUES
  ('Ann Lee', 'ann@example.com',  'active',   120.50),
  ('Bob Kim', 'bob@example.com',  'active',    80.00),
  ('Cara Ng', 'cara@example.com', 'inactive',  45.25);
