CREATE TABLE IF NOT EXISTS cms_user_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(16) NOT NULL,
    provider_user_id VARCHAR(191) NOT NULL,
    email VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_user_identities_provider_uid (provider, provider_user_id),
    UNIQUE KEY uq_cms_user_identities_user_provider (user_id, provider),
    KEY idx_cms_user_identities_user (user_id),
    CONSTRAINT fk_cms_user_identities_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
