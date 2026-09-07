ALTER TABLE cms_media
    ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER parent_id,
    ADD KEY idx_cms_media_source (source_id);
