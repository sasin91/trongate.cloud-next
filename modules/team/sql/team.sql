INSERT INTO trongate_user_levels (id, level_title)
SELECT 2, 'Team Member'
WHERE NOT EXISTS (SELECT 1 FROM trongate_user_levels WHERE id = 2);

CREATE TABLE IF NOT EXISTS team_members (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    password VARCHAR(255) NOT NULL,
    trongate_user_id INTEGER NOT NULL UNIQUE,
    role VARCHAR(32) NOT NULL DEFAULT 'member',
    scope VARCHAR(32) NOT NULL DEFAULT 'scoped',
    permissions LONGTEXT NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    created_at BIGINT NOT NULL,
    last_login BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_invitations (
    id VARCHAR(32) PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    role VARCHAR(32) NOT NULL DEFAULT 'member',
    scope VARCHAR(32) NOT NULL DEFAULT 'scoped',
    permissions LONGTEXT NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    invited_by VARCHAR(190) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    accepted_at BIGINT NOT NULL DEFAULT 0,
    revoked_at BIGINT NOT NULL DEFAULT 0,
    accepted_member_id INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
