ALTER TABLE cms_users
    ADD COLUMN acl_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

CREATE TABLE IF NOT EXISTS cms_user_section_grants (
    user_id INT UNSIGNED NOT NULL,
    section VARCHAR(32) NOT NULL,
    PRIMARY KEY (user_id, section),
    CONSTRAINT fk_cms_user_section_grants_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_user_resource_grants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    can_read TINYINT(1) NOT NULL DEFAULT 0,
    can_create TINYINT(1) NOT NULL DEFAULT 0,
    can_update TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    tabs_json JSON NOT NULL,
    UNIQUE KEY uq_cms_user_resource_grants (user_id, resource_id),
    KEY idx_cms_user_resource_grants_user (user_id),
    CONSTRAINT fk_cms_user_resource_grants_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_user_resource_grants_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
