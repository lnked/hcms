<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Api\QueryEngine;
use Cms\Audit\ApiLogRepository;
use Cms\Audit\AuditLogger;
use Cms\Audit\AuditRepository;
use Cms\Auth\ApiTokenService;
use Cms\Auth\AuthContext;
use Cms\Auth\DatabaseRateLimitStore;
use Cms\Auth\LoginGuard;
use Cms\Auth\RateLimiter;
use Cms\Auth\TokenGrantRepository;
use Cms\Auth\TokenService;
use Cms\Content\ContentTypeRepository;
use Cms\Core\Config;
use Cms\Core\Env;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Database\SchemaDiff;
use Cms\Fields\FieldRepository;
use Cms\Fields\FieldService;
use Cms\Fields\FieldTypeRegistry;
use Cms\Fields\SqlTypeMapper;
use Cms\Http\Controllers\AuthController;
use Cms\Http\Controllers\DocsController;
use Cms\Http\Controllers\EntriesController;
use Cms\Http\Controllers\FieldController;
use Cms\Http\Controllers\LogsController;
use Cms\Http\Controllers\MediaController;
use Cms\Http\Controllers\MigrationController;
use Cms\Http\Controllers\PublicApiController;
use Cms\Http\Controllers\ResourceController;
use Cms\Http\Controllers\SystemController;
use Cms\Http\Controllers\TokensController;
use Cms\Install\Installer;
use Cms\Media\MediaService;
use Cms\OpenApi\OpenApiGenerator;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use Cms\System\ChangelogRepository;
use Cms\System\LatestRelease;
use Cms\System\UpdateService;
use Throwable;

final class Kernel
{
    private function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private readonly ?Connection $db,
        private readonly ?TokenService $tokens,
        private readonly bool $installed,
        private readonly ?RateLimiter $ipLimiter,
        private readonly ?RateLimiter $tokenLimiter,
        private readonly ?RateLimiter $apiTokenLimiter,
        private readonly ?LoginGuard $loginGuard,
        private readonly ?AuditLogger $audit,
        private readonly ?ApiLogRepository $apiLogs,
        private readonly int $adminTtlHours,
    ) {
    }

    public static function boot(string $root): self
    {
        $paths = new Paths($root);
        $env = new Env();
        $env->load($paths->envFile());
        $config = Config::fromEnv($env);
        $installed = is_file($paths->installedLock());

        $db = null;
        $tokens = null;
        $ipLimiter = null;
        $tokenLimiter = null;
        $apiTokenLimiter = null;
        $loginGuard = null;
        $audit = null;
        $apiLogs = null;
        $adminTtlHours = 12;
        if ($installed && $config->dbName !== '') {
            $db = Connection::connect([
                'host' => $config->dbHost,
                'port' => $config->dbPort,
                'database' => $config->dbName,
                'username' => $config->dbUser,
                'password' => $config->dbPassword,
                'charset' => $config->dbCharset,
            ]);
            $tokens = new TokenService($db);
            $settings = new Settings($db);
            $store = new DatabaseRateLimitStore($db);
            $adminTtlHours = max(1, $settings->int('auth.admin_token_ttl_hours', 12));
            $loginGuard = new LoginGuard(new RateLimiter(
                $store,
                max(60, $settings->int('security.login_window_seconds', 900)),
                max(1, $settings->int('security.login_max_attempts', 5)),
            ));
            $ipLimiter = new RateLimiter(
                $store,
                60,
                max(1, $settings->int('security.rate_limit_ip_per_minute', 120)),
            );
            $tokenLimiter = new RateLimiter(
                $store,
                60,
                max(1, $settings->int('security.rate_limit_token_per_minute', 300)),
            );
            $apiTokenLimiter = new RateLimiter(
                $store,
                60,
                max(1, $settings->int('security.rate_limit_api_token_per_minute', 120)),
            );
            $audit = new AuditLogger($db);
            $apiLogs = new ApiLogRepository($db);
        }

        $router = new Router();
        $kernel = new self(
            $paths,
            $config,
            $router,
            new ExceptionHandler($config, $paths),
            $db,
            $tokens,
            $installed,
            $ipLimiter,
            $tokenLimiter,
            $apiTokenLimiter,
            $loginGuard,
            $audit,
            $apiLogs,
            $adminTtlHours,
        );
        $kernel->registerRoutes();

        return $kernel;
    }

    public function handle(Request $request): Response
    {
        try {
            $canonical = Request::trailingSlashRedirectTarget(
                $request->method,
                (string) ($_SERVER['REQUEST_URI'] ?? $request->path),
            );
            if ($canonical !== null) {
                return $this->withSecurityHeaders(Response::redirect($canonical, 301));
            }

            return $this->dispatch($request);
        } catch (Throwable $e) {
            return $this->exceptions->handle($e);
        }
    }

    private function dispatch(Request $request): Response
    {
        if (!$this->installed) {
            if ($request->path === '/') {
                return is_file($this->paths->adminIndex())
                    ? Response::redirect('/admin/install')
                    : Response::redirect('/install.php');
            }
        }

        if ($this->isSpaPath($request->path)) {
            return $this->spa();
        }

        $matched = $this->router->match($request);
        if ($matched === null) {
            if (str_starts_with($request->path, '/admin/api') || str_starts_with($request->path, '/api/')) {
                return Response::error('NOT_FOUND', 'Not found', 404);
            }

            return $this->installed ? $this->spa() : Response::redirect('/install.php');
        }

        $route = $matched['route'];
        $auth = null;
        if (!$route->public) {
            if ($this->tokens === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }
            $auth = $this->authenticate($request, $route->auth);
            if ($auth instanceof Response) {
                return $auth;
            }
        } elseif ($this->tokens !== null && $request->bearerToken() !== null) {
            $resolved = $this->authenticate($request, 'api');
            if ($resolved instanceof AuthContext) {
                $auth = $resolved;
            }
        }

        if ($this->shouldRateLimit($request->path)) {
            $limited = $this->rateLimit($request, $auth instanceof AuthContext ? $auth : null);
            if ($limited !== null) {
                return $limited;
            }
        }

        $handler = $route->handler;
        $started = hrtime(true);
        $response = $handler($request, $matched['params'], $auth instanceof AuthContext ? $auth : null);
        $response = $this->withSecurityHeaders($response);

        if (
            $this->apiLogs !== null
            && str_starts_with($request->path, '/api/')
            && $request->path !== '/api/docs'
            && $request->path !== '/api/openapi.json'
            && $request->path !== '/api/v1/docs'
            && $request->path !== '/api/v1/openapi.json'
        ) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $tokenId = $auth instanceof AuthContext && !$auth->isAdmin() ? $auth->tokenId() : null;
            $this->apiLogs->write(
                $request->method,
                $request->path,
                $response->status,
                $durationMs,
                $tokenId,
                $request->ip,
            );
        }

        return $response;
    }

    private function authenticate(Request $request, string $type): AuthContext|Response
    {
        if ($this->tokens === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        $plain = $request->bearerToken();
        if ($plain === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        $token = $this->tokens->resolve($plain);
        if ($token === null) {
            return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
        }

        if ($type === 'admin' && ($token['type'] ?? '') !== 'admin') {
            return Response::error('FORBIDDEN', 'Admin token required', 403);
        }

        $user = null;
        if ($token['user_id'] !== null) {
            $user = $this->tokens->userById((int) $token['user_id']);
        }

        return new AuthContext($token, $user);
    }

    private function registerRoutes(): void
    {
        $auth = $this->tokens !== null && $this->loginGuard !== null && $this->audit !== null
            ? new AuthController($this->tokens, $this->loginGuard, $this->audit, $this->adminTtlHours)
            : null;
        $docs = new DocsController(
            new OpenApiGenerator(
                $this->config,
                $this->db !== null ? new ResourceRepository($this->db) : null,
                $this->db !== null ? new FieldRepository($this->db) : null,
            ),
        );

        $this->router->add('POST', '/admin/api/auth/login', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->login($request);
        }, true);

        $this->router->add('POST', '/admin/api/auth/logout', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->logout($request, $context);
        });

        $this->router->add('GET', '/admin/api/auth/me', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->me($request, $context);
        });

        if ($this->db !== null) {
            $system = new SystemController(
                new ChangelogRepository($this->paths),
                new LatestRelease($this->paths, $this->config),
                $this->db,
                new UpdateService(
                    $this->paths,
                    $this->config,
                    new LatestRelease($this->paths, $this->config),
                    new ChangelogRepository($this->paths),
                    $this->db,
                    new Settings($this->db),
                ),
            );
            $this->router->add('GET', '/admin/api/system/version', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->version($request, $context);
            });
            $this->router->add('GET', '/admin/api/system/changelog', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params, $context);

                return $system->changelog($request);
            });
            $this->router->add('POST', '/admin/api/system/changelog/seen', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->markSeen($request, $context);
            });
            $this->router->add('GET', '/admin/api/system/update/check', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->updateCheck($request, $context);
            });
            $this->router->add('POST', '/admin/api/system/update/preview', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->updatePreview($request, $context);
            });
            $this->router->add('GET', '/admin/api/system/update/status', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->updateStatus($request, $context);
            });
            $this->router->add('POST', '/admin/api/system/update/run', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $system->updateRun($request, $context);
            });

            $audit = $this->audit;
            if ($audit === null) {
                throw new \RuntimeException('Audit logger is required');
            }
            $resourceService = new ResourceService(
                $this->db,
                new ContentTypeRepository($this->db),
                new ResourceRepository($this->db),
            );
            $migrationService = new MigrationService(
                $this->db,
                new ResourceRepository($this->db),
                new FieldRepository($this->db),
                new SqlTypeMapper(new FieldTypeRegistry()),
                new SchemaDiff(),
            );
            $resources = new ResourceController($resourceService, $audit, $migrationService);

            $this->router->add('GET', '/admin/api/resources', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->index($request, $context);
            });
            $this->router->add('POST', '/admin/api/resources', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->create($request, $context);
            });
            $this->router->add('GET', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->show($request, $context, (int) $params['id']);
            });
            $this->router->add('PATCH', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->update($request, $context, (int) $params['id']);
            });
            $this->router->add('POST', '/admin/api/resources/{id}/publish', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->publish($request, $context, (int) $params['id']);
            });
            $this->router->add('DELETE', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resources->delete($request, $context, (int) $params['id']);
            });

            $fieldService = new FieldService(
                $this->db,
                new FieldRepository($this->db),
                new ResourceRepository($this->db),
                new FieldTypeRegistry(),
            );
            $fields = new FieldController($fieldService, $audit);

            $this->router->add('GET', '/admin/api/field-types', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->types($request, $context);
            });
            $this->router->add('GET', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->index($request, $context, (int) $params['id']);
            });
            $this->router->add('PUT', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->replace($request, $context, (int) $params['id']);
            });
            $this->router->add('POST', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->create($request, $context, (int) $params['id']);
            });
            $this->router->add('PATCH', '/admin/api/fields/{id}', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->update($request, $context, (int) $params['id']);
            });
            $this->router->add('DELETE', '/admin/api/fields/{id}', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $fields->delete($request, $context, (int) $params['id']);
            });

            $queryEngine = new QueryEngine(
                $this->db,
                new ResourceRepository($this->db),
                new FieldRepository($this->db),
            );
            $entries = new EntriesController(
                $queryEngine,
                new ResourceRepository($this->db),
                $audit,
            );
            $this->router->add('GET', '/admin/api/resources/{id}/entries', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $entries->index($request, $context, (int) $params['id']);
            });
            $this->router->add('POST', '/admin/api/resources/{id}/entries', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $entries->create($request, $context, (int) $params['id']);
            });
            $this->router->add('GET', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $entries->show($request, $context, (int) $params['id'], (int) $params['entryId']);
            });
            $this->router->add('PATCH', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $entries->update($request, $context, (int) $params['id'], (int) $params['entryId']);
            });
            $this->router->add('DELETE', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $entries->delete($request, $context, (int) $params['id'], (int) $params['entryId']);
            });

            $migrations = new MigrationController(
                $migrationService,
                $audit,
            );
            $this->router->add('POST', '/admin/api/resources/{id}/migrate', function (Request $request, array $params, ?AuthContext $context) use ($migrations): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $migrations->apply($request, $context, (int) $params['id']);
            });

            $apiTokens = new TokensController(
                new ApiTokenService(
                    $this->db,
                    new TokenService($this->db),
                    new TokenGrantRepository($this->db),
                    new ResourceRepository($this->db),
                ),
                $audit,
            );
            $this->router->add('GET', '/admin/api/tokens', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $apiTokens->index($request, $context);
            });
            $this->router->add('POST', '/admin/api/tokens', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $apiTokens->create($request, $context);
            });
            $this->router->add('GET', '/admin/api/tokens/{id}', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $apiTokens->show($request, $context, (int) $params['id']);
            });
            $this->router->add('PUT', '/admin/api/tokens/{id}/grants', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $apiTokens->updateGrants($request, $context, (int) $params['id']);
            });
            $this->router->add('DELETE', '/admin/api/tokens/{id}', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $apiTokens->delete($request, $context, (int) $params['id']);
            });

            $media = new MediaController(
                new MediaService($this->db, $this->paths),
                $audit,
            );
            $this->router->add('GET', '/admin/api/media', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $media->index($request, $context);
            });
            $this->router->add('POST', '/admin/api/media', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $media->upload($request, $context);
            });
            $this->router->add('GET', '/admin/api/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $media->show($request, $context, (int) $params['id']);
            });
            $this->router->add('DELETE', '/admin/api/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $media->delete($request, $context, (int) $params['id']);
            });
            $this->router->add('GET', '/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
                unset($context);

                return $media->file($request, (int) $params['id']);
            }, true);

            $logs = new LogsController(
                new AuditRepository($this->db),
                new ApiLogRepository($this->db),
            );
            $this->router->add('GET', '/admin/api/logs/audit', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $logs->audit($request, $context);
            });
            $this->router->add('GET', '/admin/api/logs/api', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $logs->api($request, $context);
            });
        }

        $this->router->add('GET', '/api/openapi.json', function (Request $request, array $params, ?AuthContext $context) use ($docs): Response {
            unset($params, $context);

            return $docs->openapi($request);
        }, true, 'api');
        $this->router->add('GET', '/api/docs', function (Request $request, array $params, ?AuthContext $context) use ($docs): Response {
            unset($params, $context);

            return $docs->swagger($request);
        }, true, 'api');
        $this->router->add('GET', '/api/v1/openapi.json', function (Request $request, array $params, ?AuthContext $context) use ($docs): Response {
            unset($params, $context);

            return $docs->openapi($request);
        }, true, 'api');
        $this->router->add('GET', '/api/v1/docs', function (Request $request, array $params, ?AuthContext $context) use ($docs): Response {
            unset($params, $context);

            return $docs->swagger($request);
        }, true, 'api');

        if ($this->db !== null) {
            $publicApi = new PublicApiController(
                new QueryEngine(
                    $this->db,
                    new ResourceRepository($this->db),
                    new FieldRepository($this->db),
                ),
                new ResourceRepository($this->db),
                new TokenGrantRepository($this->db),
            );
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->router->add($method, '/api/{slug}', function (Request $request, array $params, ?AuthContext $context) use ($publicApi): Response {
                    return $publicApi->handle($request, (string) $params['slug'], null, $context);
                }, true, 'api');
                $this->router->add($method, '/api/{slug}/{id}', function (Request $request, array $params, ?AuthContext $context) use ($publicApi): Response {
                    return $publicApi->handle($request, (string) $params['slug'], (string) $params['id'], $context);
                }, true, 'api');
                $this->router->add($method, '/api/v1/{slug}', function (Request $request, array $params, ?AuthContext $context) use ($publicApi): Response {
                    return $publicApi->handle($request, (string) $params['slug'], null, $context);
                }, true, 'api');
                $this->router->add($method, '/api/v1/{slug}/{id}', function (Request $request, array $params, ?AuthContext $context) use ($publicApi): Response {
                    return $publicApi->handle($request, (string) $params['slug'], (string) $params['id'], $context);
                }, true, 'api');
            }
        }

        $this->router->add('GET', '/admin/api/health', function (Request $request, array $params, ?AuthContext $context): Response {
            unset($request, $params, $context);

            return Response::data(['ok' => true, 'installed' => $this->installed]);
        }, true);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $headers = $response->headers + [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];
        if (!$this->config->debug) {
            $headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://unpkg.com; script-src 'self' 'unsafe-inline' https://unpkg.com; connect-src 'self'";
        }

        return new Response($response->status, $response->body, $headers);
    }

    private function shouldRateLimit(string $path): bool
    {
        if ($path === '/admin/api/health' || $path === '/api/docs' || $path === '/api/openapi.json') {
            return false;
        }
        if ($path === '/api/v1/docs' || $path === '/api/v1/openapi.json') {
            return false;
        }
        if (preg_match('#^/media/\\d+$#', $path) === 1) {
            return false;
        }

        return str_starts_with($path, '/admin/api') || str_starts_with($path, '/api/');
    }

    private function rateLimit(Request $request, ?AuthContext $auth): ?Response
    {
        if ($this->ipLimiter !== null) {
            if (!$this->ipLimiter->hit('ip:' . $request->ip)) {
                return Response::tooManyRequests($this->ipLimiter->retryAfter(), $this->ipLimiter->limit());
            }
        }

        if ($auth !== null) {
            $limiter = $auth->isAdmin() ? $this->tokenLimiter : $this->apiTokenLimiter;
            if ($limiter !== null && !$limiter->hit('token:' . $auth->tokenId())) {
                return Response::tooManyRequests($limiter->retryAfter(), $limiter->limit());
            }
        }

        return null;
    }

    private function isSpaPath(string $path): bool
    {
        return $path === '/admin' || (str_starts_with($path, '/admin/') && !str_starts_with($path, '/admin/api'));
    }

    private function spa(): Response
    {
        $index = $this->paths->adminIndex();
        if (!is_file($index)) {
            return Response::html('<!doctype html><html><body><p>Admin UI is not built. Run <code>npm run build</code>.</p></body></html>', 503);
        }

        return Response::html((string) file_get_contents($index));
    }

    public function installer(): Installer
    {
        return new Installer($this->paths);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }
}
