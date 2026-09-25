ALTER TABLE cms_webhooks
    ADD COLUMN preset VARCHAR(32) NULL AFTER status,
    ADD COLUMN payload_mode VARCHAR(32) NOT NULL DEFAULT 'hcms' AFTER preset,
    ADD COLUMN headers_json JSON NULL AFTER payload_mode;

ALTER TABLE cms_user_resource_grants
    ADD COLUMN field_acl_json JSON NULL AFTER tabs_json,
    ADD COLUMN own_entries_only TINYINT(1) NOT NULL DEFAULT 0 AFTER field_acl_json;
