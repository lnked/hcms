CREATE TABLE IF NOT EXISTS cms_resource_hooks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    phase ENUM('before_create', 'after_create') NOT NULL,
    url VARCHAR(2048) NOT NULL,
    secret VARCHAR(128) NOT NULL,
    timeout_ms INT UNSIGNED NOT NULL DEFAULT 3000,
    on_failure ENUM('reject', 'continue') NOT NULL DEFAULT 'reject',
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_resource_hooks_resource_phase (resource_id, phase, status),
    CONSTRAINT fk_cms_resource_hooks_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_inbound_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    target_url VARCHAR(2048) NOT NULL,
    secret VARCHAR(128) NOT NULL,
    persist_resource_id BIGINT UNSIGNED NULL,
    field_map JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    timeout_ms INT UNSIGNED NOT NULL DEFAULT 5000,
    on_failure ENUM('reject', 'continue') NOT NULL DEFAULT 'reject',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_inbound_endpoints_slug (slug),
    KEY idx_cms_inbound_endpoints_persist (persist_resource_id),
    CONSTRAINT fk_cms_inbound_persist_resource FOREIGN KEY (persist_resource_id) REFERENCES cms_resources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_hook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('resource', 'inbound') NOT NULL,
    hook_id BIGINT UNSIGNED NOT NULL,
    phase VARCHAR(32) NOT NULL,
    payload JSON NOT NULL,
    response_code INT NULL,
    response_body TEXT NULL,
    duration_ms INT NULL,
    status ENUM('pending', 'success', 'failed', 'rejected') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_hook_deliveries_hook (kind, hook_id),
    KEY idx_cms_hook_deliveries_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
