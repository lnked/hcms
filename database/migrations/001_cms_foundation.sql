CREATE TABLE IF NOT EXISTS cms_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(128) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    changelog_seen_version VARCHAR(32) NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(16) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    token_prefix CHAR(8) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_tokens_prefix (token_prefix),
    KEY idx_cms_tokens_user (user_id),
    CONSTRAINT fk_cms_tokens_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(191) NOT NULL,
    description TEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_content_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    spec_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_fields_type_name (content_type_id, name),
    KEY idx_cms_fields_sort (content_type_id, sort_order),
    CONSTRAINT fk_cms_fields_content_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL,
    endpoint VARCHAR(191) NOT NULL,
    api_version VARCHAR(16) NOT NULL DEFAULT 'v1',
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    schema_version INT NOT NULL DEFAULT 0,
    settings_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_resources_slug (slug),
    KEY idx_cms_resources_type (content_type_id),
    CONSTRAINT fk_cms_resources_content_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_token_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NULL,
    can_read TINYINT(1) NOT NULL DEFAULT 0,
    can_create TINYINT(1) NOT NULL DEFAULT 0,
    can_update TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_cms_token_grants_token (token_id),
    CONSTRAINT fk_cms_token_grants_token FOREIGN KEY (token_id) REFERENCES cms_tokens (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_token_grants_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_resource_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    field_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    target_content_type_id BIGINT UNSIGNED NOT NULL,
    inverse_field_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_cms_relations_field (field_id),
    CONSTRAINT fk_cms_relations_field FOREIGN KEY (field_id) REFERENCES cms_fields (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_relations_target FOREIGN KEY (target_content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_schema_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_type_id BIGINT UNSIGNED NOT NULL,
    version INT NOT NULL,
    schema_json JSON NOT NULL,
    diff_json JSON NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_schema_rev (content_type_id, version),
    CONSTRAINT fk_cms_schema_rev_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_path VARCHAR(512) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(128) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    width INT NULL,
    height INT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id VARCHAR(64) NULL,
    metadata_json JSON NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_api_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    method VARCHAR(16) NOT NULL,
    path VARCHAR(255) NOT NULL,
    status SMALLINT NOT NULL,
    duration_ms INT NOT NULL DEFAULT 0,
    api_key_id BIGINT UNSIGNED NULL,
    ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_api_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(128) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_rate_bucket (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
