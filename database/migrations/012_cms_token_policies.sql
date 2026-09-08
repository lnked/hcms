CREATE TABLE IF NOT EXISTS cms_token_policies (
    token_id BIGINT UNSIGNED NOT NULL,
    allowed_origins JSON NULL,
    require_origin TINYINT(1) NOT NULL DEFAULT 0,
    allowed_ips JSON NULL,
    PRIMARY KEY (token_id),
    CONSTRAINT fk_cms_token_policies_token FOREIGN KEY (token_id) REFERENCES cms_tokens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
