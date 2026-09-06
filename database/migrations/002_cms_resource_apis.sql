CREATE TABLE IF NOT EXISTS cms_resource_apis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(191) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    methods_json JSON NOT NULL,
    fields_json JSON NULL,
    joins_json JSON NOT NULL,
    settings_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_resource_apis_resource_slug (resource_id, slug),
    KEY idx_cms_resource_apis_resource (resource_id),
    CONSTRAINT fk_cms_resource_apis_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
