CREATE TABLE IF NOT EXISTS cms_translations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    translation_key VARCHAR(191) NOT NULL,
    description VARCHAR(255) NULL,
    values_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cms_translations_key (translation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
