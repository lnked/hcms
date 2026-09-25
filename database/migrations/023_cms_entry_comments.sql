CREATE TABLE IF NOT EXISTS cms_entry_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_entry_comments_entry (resource_id, entry_id, id),
    CONSTRAINT fk_cms_entry_comments_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_entry_comments_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
