<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Core\Config;
use Cms\Core\Version;
use Cms\Http\Request;
use Cms\Http\Response;

final class DocsController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function openapi(Request $request): Response
    {
        unset($request);
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'HCMS API',
                'version' => Version::current(),
                'description' => 'Generated public API. Resources appear here after publish.',
            ],
            'servers' => [
                ['url' => $this->config->appUrl . '/api'],
            ],
            'paths' => new \stdClass(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];

        return new Response(
            200,
            json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function swagger(Request $request): Response
    {
        unset($request);
        $specUrl = $this->config->appUrl . '/api/openapi.json';
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>HCMS API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css"/>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: {$this->js($specUrl)},
      dom_id: '#swagger-ui',
      persistAuthorization: true
    });
  </script>
</body>
</html>
HTML;

        return Response::html($html);
    }

    private function js(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '""';
    }
}
