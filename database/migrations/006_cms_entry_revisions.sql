CREATE TABLE IF NOT EXISTS cms_entry_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    data_json JSON NOT NULL,
    diff_json JSON NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_entry_revisions_entry (resource_id, entry_id, id),
    CONSTRAINT fk_cms_entry_revisions_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_entry_revisions_actor FOREIGN KEY (actor_user_id) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
