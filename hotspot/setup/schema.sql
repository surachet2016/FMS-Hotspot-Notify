CREATE TABLE IF NOT EXISTS members (
  id              CHAR(36)     NOT NULL,
  full_name       VARCHAR(255) NOT NULL,
  email           VARCHAR(255) NOT NULL,
  citizen_id      VARCHAR(50)  NOT NULL,
  username        VARCHAR(100) NULL,
  password_hash   VARCHAR(255) NULL,
  status          ENUM('PENDING','ACTIVE','SUSPENDED') NOT NULL DEFAULT 'PENDING',
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dob             DATE         NULL,
  profile         ENUM('student','teacher','employee','default') NOT NULL DEFAULT 'student',
  id_card_image   MEDIUMBLOB   NULL,
  id_card_mime    VARCHAR(20)  NULL,
  admin_note      VARCHAR(512) NULL,
  mikrotik_synced TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email      (email(191)),
  UNIQUE KEY uq_citizen_id (citizen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id            CHAR(36)     NOT NULL,
  username      VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  ip           VARCHAR(45)  NOT NULL,
  endpoint     VARCHAR(50)  NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
