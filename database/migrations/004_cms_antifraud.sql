CREATE TABLE IF NOT EXISTS cms_ip_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    expires_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_ip_blocks_ip (ip),
    KEY idx_cms_ip_blocks_expires (expires_at),
    CONSTRAINT fk_cms_ip_blocks_user FOREIGN KEY (created_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_users
    ADD COLUMN totp_secret VARCHAR(64) NULL AFTER status,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
