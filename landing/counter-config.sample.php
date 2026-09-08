<?php

declare(strict_types=1);

/**
 * Copy to counter-config.php on the server (never commit it) or set the same
 * values as HCMS_API_BASE / HCMS_DOWNLOADS_TOKEN env vars.
 *
 * The token is an API token with read + create on the `downloads` resource
 * only — scripts/setup-downloads.php prints one. It stays on this host: no
 * browser ever sees it.
 */

return [
    'api' => 'http://api.2js.ru',
    'token' => 'paste-the-downloads-token-here',
    // Per-IP clicks that get counted each minute. Extra downloads still work,
    // they just do not move the number.
    'clicksPerIpPerMinute' => 10,
];
