CREATE TABLE IF NOT EXISTS cms_locales (
    code VARCHAR(16) NOT NULL,
    label VARCHAR(191) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (code),
    KEY idx_cms_locales_enabled (enabled),
    KEY idx_cms_locales_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_locales (code, label, enabled, is_default, sort_order)
VALUES
    ('en', 'English', 1, 1, 0),
    ('ru', 'Русский', 1, 0, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);
