-- =============================================================
--  Geocaching Landkreis-Tracker – Datenbankschema
--  Kompatibel mit MySQL 8+ und MariaDB 10.4+
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
--  Tabelle: users
--  Alle registrierten Nutzer inkl. Admin-Flag
-- -------------------------------------------------------------
CREATE TABLE users (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username     VARCHAR(60)     NOT NULL,           -- wird Teil der öffentlichen URL
    email        VARCHAR(255)    NOT NULL,
    is_admin     TINYINT(1)      NOT NULL DEFAULT 0,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
--  Tabelle: magic_links
--  Einmal-Token für passwortlose Anmeldung per E-Mail
-- -------------------------------------------------------------
CREATE TABLE magic_links (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    token        CHAR(64)        NOT NULL,           -- SHA-256-Hex oder random_bytes(32) als Hex
    expires_at   DATETIME        NOT NULL,           -- z.B. NOW() + INTERVAL 15 MINUTE
    used_at      DATETIME            NULL DEFAULT NULL,
    ip_address   VARCHAR(45)         NULL DEFAULT NULL,  -- optional: zur Absicherung
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_magic_links_token (token),
    KEY         idx_magic_links_user  (user_id),

    CONSTRAINT fk_magic_links_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
--  Tabelle: visits
--  Besuchte Regionen (Landkreise/Bezirke) pro User und Land
-- -------------------------------------------------------------
CREATE TABLE visits (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    country_code   CHAR(2)         NOT NULL,         -- ISO 3166-1 Alpha-2, z.B. 'DE', 'AT'
    region_code    VARCHAR(20)     NOT NULL,         -- AGS für DE, ISO für AT, etc.
    region_name    VARCHAR(255)        NULL DEFAULT NULL,  -- denormalisierter Anzeigename
    visited_at     DATE                NULL DEFAULT NULL,  -- optionales Besuchsdatum
    notes          TEXT                NULL DEFAULT NULL,  -- Freitext/Notizen
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Ein User kann einen Landkreis nur einmal besucht haben
    UNIQUE KEY uq_visits_user_region (user_id, country_code, region_code),
    KEY         idx_visits_user        (user_id),
    KEY         idx_visits_country     (country_code),

    CONSTRAINT fk_visits_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
--  Tabelle: sessions
--  Serverseitige Sessions nach erfolgreichem Magic-Link-Login
-- -------------------------------------------------------------
CREATE TABLE sessions (
    id            CHAR(64)        NOT NULL,           -- zufälliges Session-Token
    user_id       INT UNSIGNED    NOT NULL,
    expires_at    DATETIME        NOT NULL,
    ip_address    VARCHAR(45)         NULL DEFAULT NULL,
    user_agent    VARCHAR(512)        NULL DEFAULT NULL,
    last_seen_at  DATETIME            NULL DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY         idx_sessions_user    (user_id),
    KEY         idx_sessions_expires (expires_at),

    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
--  Initialdaten
-- =============================================================

-- Admin-User (E-Mail beim ersten Deployment anpassen!)
INSERT INTO users (username, email, is_admin, is_active)
VALUES ('admin', 'admin@example.com', 1, 1);


-- =============================================================
--  Hilfreich für Wartung: automatisches Aufräumen abgelaufener
--  Magic Links und Sessions (als MySQL Event, optional)
-- =============================================================

-- Event-Scheduler muss aktiviert sein: SET GLOBAL event_scheduler = ON;

-- CREATE EVENT IF NOT EXISTS cleanup_expired_tokens
--     ON SCHEDULE EVERY 1 HOUR
--     DO BEGIN
--         DELETE FROM magic_links WHERE expires_at < NOW();
--         DELETE FROM sessions     WHERE expires_at < NOW();
--     END;


-- =============================================================
--  Übersicht der Indizes und Constraints
-- =============================================================
--
--  users
--    PK:  id
--    UQ:  username, email
--
--  magic_links
--    PK:  id
--    UQ:  token
--    FK:  user_id → users.id  (CASCADE DELETE)
--    IDX: user_id
--
--  visits
--    PK:  id
--    UQ:  (user_id, country_code, region_code)
--    FK:  user_id → users.id  (CASCADE DELETE)
--    IDX: user_id, country_code
--
--  sessions
--    PK:  id (Token selbst)
--    FK:  user_id → users.id  (CASCADE DELETE)
--    IDX: user_id, expires_at
--

-- =============================================================
--  Migration: last_seen_at (auf bestehende Instanzen anwenden)
-- =============================================================
-- ALTER TABLE sessions ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER user_agent;
-- =============================================================