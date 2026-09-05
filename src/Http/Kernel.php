<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\DatabaseRateLimitStore;
use Cms\Auth\LoginGuard;
use Cms\Auth\RateLimiter;
use Cms\Auth\TokenService;
use Cms\Core\Config;
use Cms\Core\Env;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Http\Controllers\AuthController;
use Cms\Http\Controllers\DocsController;
use Cms\Http\Controllers\SystemController;
use Cms\Install\Installer;
use Cms\System\ChangelogRepository;
use Cms\System\LatestRelease;
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
        private readonly ?LoginGuard $loginGuard,
        private readonly ?AuditLogger $audit,
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
        $loginGuard = null;
        $audit = null;
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
            $audit = new AuditLogger($db);
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
            $loginGuard,
            $audit,
            $adminTtlHours,
        );
        $kernel->registerRoutes();

        return $kernel;
    }

    public function handle(Request $request): Response
    {
        try {
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
        }

        if ($this->shouldRateLimit($request->path)) {
            $limited = $this->rateLimit($request, $auth instanceof AuthContext ? $auth : null);
            if ($limited !== null) {
                return $limited;
            }
        }

        $handler = $route->handler;

        return $handler($request, $matched['params'], $auth instanceof AuthContext ? $auth : null);
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
        $docs = new DocsController($this->config);

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

        $this->router->add('GET', '/admin/api/health', function (Request $request, array $params, ?AuthContext $context): Response {
            unset($request, $params, $context);

            return Response::data(['ok' => true, 'installed' => $this->installed]);
        }, true);
    }

    private function shouldRateLimit(string $path): bool
    {
        if ($path === '/admin/api/health' || $path === '/api/docs' || $path === '/api/openapi.json') {
            return false;
        }
        if ($path === '/api/v1/docs' || $path === '/api/v1/openapi.json') {
            return false;
        }

        return str_starts_with($path, '/admin/api') || str_starts_with($path, '/api/');
    }

    private function rateLimit(Request $request, ?AuthContext $auth): ?Response
    {
        if ($this->ipLimiter !== null) {
            if (!$this->ipLimiter->hit('ip:' . $request->ip)) {
                return Response::tooManyRequests($this->ipLimiter->retryAfter());
            }
        }

        if ($auth !== null && $this->tokenLimiter !== null) {
            if (!$this->tokenLimiter->hit('token:' . $auth->tokenId())) {
                return Response::tooManyRequests($this->tokenLimiter->retryAfter());
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
