-- Media ownership + usage index for ACL-scoped media library.
ALTER TABLE cms_media
    ADD COLUMN uploaded_by BIGINT UNSIGNED NULL AFTER created_at,
    ADD KEY idx_cms_media_uploaded_by (uploaded_by);

CREATE TABLE IF NOT EXISTS cms_media_refs (
    media_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    field_name VARCHAR(64) NOT NULL,
    PRIMARY KEY (media_id, resource_id, entry_id, field_name),
    KEY idx_cms_media_refs_resource (resource_id),
    KEY idx_cms_media_refs_entry (resource_id, entry_id),
    CONSTRAINT fk_cms_media_refs_media FOREIGN KEY (media_id) REFERENCES cms_media (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_media_refs_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
