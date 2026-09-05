<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Http\Request;
use Cms\Http\Response;
use Cms\OpenApi\OpenApiGenerator;

final class DocsController
{
    public function __construct(private readonly OpenApiGenerator $generator)
    {
    }

    public function openapi(Request $request): Response
    {
        unset($request);
        $spec = $this->generator->generate();

        return new Response(
            200,
            json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function swagger(Request $request): Response
    {
        unset($request);
        $html = <<<'HTML'
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
      url: '/api/openapi.json',
      dom_id: '#swagger-ui',
      persistAuthorization: true,
      deepLinking: true
    });
  </script>
</body>
</html>
HTML;

        return Response::html($html);
    }
}
