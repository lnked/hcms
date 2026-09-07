ALTER TABLE cms_media
    ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN variant_key VARCHAR(32) NULL AFTER parent_id,
    ADD KEY idx_cms_media_parent (parent_id),
    ADD UNIQUE KEY uq_cms_media_parent_variant (parent_id, variant_key);
