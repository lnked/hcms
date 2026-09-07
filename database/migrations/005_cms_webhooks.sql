CREATE TABLE IF NOT EXISTS cms_webhooks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    secret VARCHAR(128) NOT NULL,
    events JSON NOT NULL,
    resource_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_webhooks_status (status),
    KEY idx_cms_webhooks_resource (resource_id),
    CONSTRAINT fk_cms_webhooks_resource FOREIGN KEY (resource_id) REFERENCES cms_resources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    response_code INT NULL,
    duration_ms INT NULL,
    attempt TINYINT NOT NULL,
    status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_webhook_deliveries_webhook (webhook_id),
    KEY idx_cms_webhook_deliveries_created (created_at),
    CONSTRAINT fk_cms_webhook_deliveries_webhook FOREIGN KEY (webhook_id) REFERENCES cms_webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
