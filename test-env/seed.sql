-- DEV/TEST fixture data. ⚠ NEVER SHIP TO PRODUCTION.
USE pmsrapi_test;

CREATE TABLE IF NOT EXISTS clients (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(190) NOT NULL UNIQUE,          -- NOT NULL, no default:
  status     VARCHAR(20)  NOT NULL DEFAULT 'active',  -- inserting without it forces a 5xx
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clients (name, email, status) VALUES
  ('Ann Lee', 'ann@example.com',  'active'),
  ('Bob Kim', 'bob@example.com',  'active'),
  ('Cara Ng', 'cara@example.com', 'inactive');
