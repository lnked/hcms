CREATE TABLE IF NOT EXISTS cms_integration_apis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_key VARCHAR(64) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(191) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    defaults_json JSON NOT NULL,
    settings_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_integration_apis_key_slug (integration_key, slug),
    KEY idx_cms_integration_apis_key (integration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_token_integration_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_id BIGINT UNSIGNED NOT NULL,
    integration_key VARCHAR(64) NOT NULL,
    can_use TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_token_integration_grants (token_id, integration_key),
    KEY idx_cms_token_integration_grants_token (token_id),
    CONSTRAINT fk_cms_token_integration_grants_token FOREIGN KEY (token_id) REFERENCES cms_tokens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
