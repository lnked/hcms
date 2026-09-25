<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Auth\AuthContext;
use Cms\Core\Settings;
use Cms\GraphQL\GraphQLSchemaFactory;
use Cms\GraphQL\GraphqlSettings;
use Cms\Http\Request;
use Cms\Http\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use RuntimeException;
use Throwable;

final class GraphqlController
{
    private readonly GraphqlSettings $graphqlSettings;

    public function __construct(
        private readonly GraphQLSchemaFactory $schemaFactory,
        Settings $settings,
        ?GraphqlSettings $graphqlSettings = null,
    ) {
        $this->graphqlSettings = $graphqlSettings ?? new GraphqlSettings($settings);
    }

    public function playground(Request $request): Response
    {
        unset($request);
        if (!$this->graphqlSettings->playground()) {
            return Response::error('NOT_FOUND', 'GraphQL playground is disabled', 404);
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>HCMS GraphQL</title>
  <style>
    body { margin: 0; overflow: hidden; }
    #graphiql { height: 100vh; }
  </style>
  <link rel="stylesheet" href="https://unpkg.com/graphiql@3/graphiql.min.css"/>
</head>
<body>
  <div id="graphiql">Loading…</div>
  <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
  <script src="https://unpkg.com/graphiql@3/graphiql.min.js"></script>
  <script>
    const fetcher = GraphiQL.createFetcher({ url: '/api/graphql' });
    const root = ReactDOM.createRoot(document.getElementById('graphiql'));
    root.render(React.createElement(GraphiQL, { fetcher }));
  </script>
</body>
</html>
HTML;

        return Response::html($html);
    }

    public function execute(Request $request, ?AuthContext $auth): Response
    {
        if (!$this->graphqlSettings->enabled()) {
            return Response::error('NOT_FOUND', 'GraphQL is disabled', 404);
        }

        try {
            $payload = $request->json();
            if ($payload === [] && $request->rawBody !== '') {
                $decoded = json_decode($request->rawBody, true);
                $payload = \is_array($decoded) ? $decoded : [];
            }

            $query = $payload['query'] ?? null;
            if (!\is_string($query) || $query === '') {
                return Response::error('VALIDATION_ERROR', 'Missing GraphQL query', 422);
            }

            $variableValues = $payload['variables'] ?? null;
            if ($variableValues !== null && !\is_array($variableValues)) {
                return Response::error('VALIDATION_ERROR', 'variables must be an object', 422);
            }
            $operationName = $payload['operationName'] ?? null;
            if ($operationName !== null && !\is_string($operationName)) {
                $operationName = null;
            }

            $context = [
                'auth' => $auth,
                'relationDepth' => 0,
                'request' => $request,
            ];

            $result = GraphQL::executeQuery(
                $this->schemaFactory->schema(),
                $query,
                null,
                $context,
                $variableValues,
                $operationName,
            );

            $output = $result->toArray(DebugFlag::NONE);

            $status = 200;
            if (isset($output['errors']) && \is_array($output['errors']) && $output['errors'] !== []) {
                $status = $this->statusFromErrors($output['errors']);
            }

            return new Response(
                $status,
                json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                ['Content-Type' => 'application/json; charset=utf-8'],
            );
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code === 401) {
                return Response::error('UNAUTHORIZED', $e->getMessage(), 401);
            }
            if ($code === 403) {
                return Response::error('FORBIDDEN', $e->getMessage(), 403);
            }
            if ($code === 404) {
                return Response::error('NOT_FOUND', $e->getMessage(), 404);
            }

            return Response::error('GRAPHQL_ERROR', $e->getMessage(), 400);
        } catch (Throwable $e) {
            return Response::error('GRAPHQL_ERROR', $e->getMessage(), 400);
        }
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function statusFromErrors(array $errors): int
    {
        foreach ($errors as $error) {
            $message = (string) ($error['message'] ?? '');
            if (str_contains($message, 'Unauthorized')) {
                return 401;
            }
            if (str_contains($message, 'Forbidden')) {
                return 403;
            }
        }

        return 200;
    }
}
