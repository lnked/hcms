ALTER TABLE cms_rate_limits
    ADD KEY idx_cms_rate_limits_window (window_start);
