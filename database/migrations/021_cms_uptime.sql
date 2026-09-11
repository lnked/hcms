CREATE TABLE IF NOT EXISTS cms_uptime_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'external',
    method VARCHAR(16) NOT NULL DEFAULT 'GET',
    expected_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    timeout_ms INT UNSIGNED NOT NULL DEFAULT 5000,
    interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_check_at DATETIME NULL,
    last_ok TINYINT(1) NULL,
    last_status_code SMALLINT UNSIGNED NULL,
    last_latency_ms INT UNSIGNED NULL,
    last_error TEXT NULL,
    last_heartbeat_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cms_uptime_targets_kind (kind),
    KEY idx_cms_uptime_targets_enabled (enabled),
    KEY idx_cms_uptime_targets_last_check (last_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_uptime_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_id BIGINT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    ok TINYINT(1) NOT NULL,
    status_code SMALLINT UNSIGNED NULL,
    latency_ms INT UNSIGNED NULL,
    error TEXT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'probe',
    PRIMARY KEY (id),
    KEY idx_cms_uptime_checks_target_checked (target_id, checked_at),
    CONSTRAINT fk_cms_uptime_checks_target
        FOREIGN KEY (target_id) REFERENCES cms_uptime_targets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_uptime_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_seconds INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_cms_uptime_incidents_target_started (target_id, started_at),
    KEY idx_cms_uptime_incidents_open (target_id, ended_at),
    CONSTRAINT fk_cms_uptime_incidents_target
        FOREIGN KEY (target_id) REFERENCES cms_uptime_targets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
