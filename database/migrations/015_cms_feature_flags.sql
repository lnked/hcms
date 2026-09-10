CREATE TABLE IF NOT EXISTS cms_feature_flags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    flag_key VARCHAR(64) NOT NULL,
    type VARCHAR(16) NOT NULL,
    value_json JSON NOT NULL,
    description TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_feature_flags_key (flag_key),
    KEY idx_cms_feature_flags_enabled (enabled),
    KEY idx_cms_feature_flags_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
