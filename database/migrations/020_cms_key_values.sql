CREATE TABLE IF NOT EXISTS cms_key_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_key VARCHAR(64) NOT NULL,
    value_json JSON NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_key_values_key (entry_key),
    KEY idx_cms_key_values_updated (updated_at),
    KEY idx_cms_key_values_created_by (created_by),
    KEY idx_cms_key_values_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
