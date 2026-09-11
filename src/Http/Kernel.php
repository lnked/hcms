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
use Cms\Auth\OAuthService;
use Cms\Auth\OAuthSettings;
use Cms\Auth\RateLimiter;
use Cms\Auth\RateLimitStore;
use Cms\Auth\RolePolicy;
use Cms\Auth\TokenGrantRepository;
use Cms\Auth\TokenPolicyRepository;
use Cms\Auth\TokenService;
use Cms\Auth\UserAclGuard;
use Cms\Auth\UserIdentityRepository;
use Cms\Auth\UserResourceGrantRepository;
use Cms\Auth\UserSectionGrantRepository;
use Cms\Auth\UsersRepository;
use Cms\Auth\UsersService;
use Cms\Content\ContentTypeRepository;
use Cms\Content\EntryRevisionService;
use Cms\Content\EntryService;
use Cms\Core\Config;
use Cms\Core\Env;
use Cms\Core\FileCache;
use Cms\Core\MetadataCache;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Database\PendingMigrations;
use Cms\Database\SchemaDiff;
use Cms\FeatureFlags\FeatureFlagRepository;
use Cms\FeatureFlags\FeatureFlagService;
use Cms\Fields\FieldRepository;
use Cms\Fields\FieldService;
use Cms\Fields\FieldTypeRegistry;
use Cms\Fields\SqlTypeMapper;
use Cms\Hooks\HookClient;
use Cms\Hooks\HookDeliveryRepository;
use Cms\Hooks\InboundEndpointRepository;
use Cms\Hooks\InboundEndpointService;
use Cms\Hooks\ResourceHookRepository;
use Cms\Hooks\ResourceHookService;
use Cms\Http\Controllers\AuthController;
use Cms\Http\Controllers\DocsController;
use Cms\Http\Controllers\EntriesController;
use Cms\Http\Controllers\FeatureFlagsController;
use Cms\Http\Controllers\FieldController;
use Cms\Http\Controllers\InboundEndpointsController;
use Cms\Http\Controllers\IntegrationsController;
use Cms\Http\Controllers\KeyValuesController;
use Cms\Http\Controllers\LogsController;
use Cms\Http\Controllers\MediaController;
use Cms\Http\Controllers\MigrationController;
use Cms\Http\Controllers\PublicApiController;
use Cms\Http\Controllers\PublicInboundController;
use Cms\Http\Controllers\PublicIntegrationApiController;
use Cms\Http\Controllers\ResourceApiController;
use Cms\Http\Controllers\ResourceController;
use Cms\Http\Controllers\ResourceHooksController;
use Cms\Http\Controllers\ResourcePackageController;
use Cms\Http\Controllers\SettingsController;
use Cms\Http\Controllers\SystemController;
use Cms\Http\Controllers\TokensController;
use Cms\Http\Controllers\TranslatesController;
use Cms\Http\Controllers\UptimeController;
use Cms\Http\Controllers\UsersController;
use Cms\Http\Controllers\WebhooksController;
use Cms\Install\Installer;
use Cms\Integrations\IntegrationApiRepository;
use Cms\Integrations\IntegrationApiService;
use Cms\KeyValues\KeyValueRepository;
use Cms\KeyValues\KeyValueService;
use Cms\Mail\EmailIntegration;
use Cms\Mail\Mailer;
use Cms\Media\MediaRefService;
use Cms\Media\MediaService;
use Cms\OpenApi\OpenApiGenerator;
use Cms\Resources\EntryImportExportService;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceApiService;
use Cms\Resources\ResourcePackageService;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use Cms\Security\CaptchaVerifier;
use Cms\Security\IpBlockRepository;
use Cms\Security\SpamGuard;
use Cms\System\AdminUiPublisher;
use Cms\System\ChangelogRepository;
use Cms\System\LatestRelease;
use Cms\System\UpdateService;
use Cms\Translates\LocaleRepository;
use Cms\Translates\TranslationRepository;
use Cms\Translates\TranslationService;
use Cms\Uptime\UptimeCheckRepository;
use Cms\Uptime\UptimeHeartbeatService;
use Cms\Uptime\UptimeIncidentRepository;
use Cms\Uptime\UptimeProbeService;
use Cms\Uptime\UptimeScheduler;
use Cms\Uptime\UptimeService;
use Cms\Uptime\UptimeSettings;
use Cms\Uptime\UptimeStatusService;
use Cms\Uptime\UptimeTargetRepository;
use Cms\Webhooks\WebhookDispatcher;
use Cms\Webhooks\WebhookRepository;
use Cms\Webhooks\WebhookService;
use Throwable;

final class Kernel
{
    private ?UserAclGuard $userAcl = null;

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
        private readonly ?RateLimiter $mediaLimiter = null,
        private readonly ?RateLimiter $anonWriteLimiter = null,
        private readonly ?RateLimitStore $rateLimitStore = null,
        private readonly ?IpBlockRepository $ipBlocks = null,
        private readonly ?Settings $runtimeSettings = null,
        private readonly ?TokenPolicyRepository $tokenPolicies = null,
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
        $mediaLimiter = null;
        $anonWriteLimiter = null;
        $rateLimitStore = null;
        $ipBlocks = null;
        $runtimeSettings = null;
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
            PendingMigrations::apply($db, $paths, $settings);
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
            $mediaLimiter = new RateLimiter(
                $store,
                60,
                max(1, $settings->int('security.rate_limit_media_per_minute', 60)),
            );
            $anonWriteLimiter = new RateLimiter(
                $store,
                60,
                max(1, $settings->int('security.rate_limit_anon_write_per_minute', 20)),
            );
            $rateLimitStore = $store;
            $runtimeSettings = $settings;
            $ipBlocks = new IpBlockRepository($db);
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
            $mediaLimiter,
            $anonWriteLimiter,
            $rateLimitStore,
            $ipBlocks,
            $runtimeSettings,
            $db !== null ? new TokenPolicyRepository($db) : null,
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
        $adminBase = $this->config->adminBase;

        if (!$this->installed) {
            if ($request->path === '/') {
                return is_file($this->paths->adminIndex())
                    ? Response::redirect($adminBase->path('/install'))
                    : Response::redirect('/install.php');
            }
        }

        $legacy = $adminBase->legacyRedirect($request->path);
        if ($legacy !== null && $legacy !== $request->path) {
            return Response::redirect($legacy, 301);
        }

        if ($adminBase->isSpaPath($request->path)) {
            return $this->spa();
        }

        $canonicalPath = $adminBase->canonicalize($request->path);
        if ($canonicalPath !== $request->path) {
            $request = $request->withPath($canonicalPath);
        }

        if ($this->runtimeSettings !== null) {
            $trusted = ClientIp::normalizeTrustedList($this->runtimeSettings->get('security.trusted_proxies'));
            $request = $request->withIp(ClientIp::resolve($request->ip, $request->header('x-forwarded-for'), $trusted));
        }
        if ($this->ipBlocks !== null && $this->ipBlocks->isBlocked($request->ip)) {
            return $this->withSecurityHeaders(Response::error('FORBIDDEN', 'IP blocked', 403));
        }

        $apiAccess = null;
        $apiOrigin = null;
        if ($this->isPublicApiPath($request->path)) {
            $apiAccess = $this->apiAccessPolicy();
            $apiOrigin = $request->header('origin');
            if (!$apiAccess->allows($apiOrigin)) {
                return $this->finalizeApiResponse(
                    Response::error('FORBIDDEN', 'Origin not allowed', 403),
                    $apiAccess,
                    $apiOrigin,
                );
            }
            if ($request->method === 'OPTIONS') {
                return $this->finalizeApiResponse(new Response(204, ''), $apiAccess, $apiOrigin);
            }
        }

        $matched = $this->router->match($request);
        if ($matched === null) {
            if (str_starts_with($request->path, '/admin/api') || str_starts_with($request->path, '/api/')) {
                $missing = Response::error('NOT_FOUND', 'Not found', 404);

                return $apiAccess !== null
                    ? $this->finalizeApiResponse($missing, $apiAccess, $apiOrigin)
                    : $this->withSecurityHeaders($missing);
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
                return $apiAccess !== null
                    ? $this->finalizeApiResponse($auth, $apiAccess, $apiOrigin)
                    : $auth;
            }
            if ($auth->isAdmin()) {
                $rbac = RolePolicy::enforce($auth, $request->method, $request->path);
                if ($rbac !== null) {
                    return $this->withSecurityHeaders($rbac);
                }
                if ($this->userAcl !== null) {
                    $acl = $this->userAcl->enforce($auth, $request->method, $request->path);
                    if ($acl !== null) {
                        return $this->withSecurityHeaders($acl);
                    }
                }
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
                return $apiAccess !== null
                    ? $this->finalizeApiResponse($limited, $apiAccess, $apiOrigin)
                    : $limited;
            }
        }

        if ($auth instanceof AuthContext) {
            $denied = $this->enforceTokenPolicy($request, $auth);
            if ($denied !== null) {
                return $apiAccess !== null
                    ? $this->finalizeApiResponse($denied, $apiAccess, $apiOrigin)
                    : $this->withSecurityHeaders($denied);
            }
        }

        $handler = $route->handler;
        $started = hrtime(true);
        $response = $handler($request, $matched['params'], $auth instanceof AuthContext ? $auth : null);
        $response = $apiAccess !== null
            ? $this->finalizeApiResponse($response, $apiAccess, $apiOrigin)
            : $this->withSecurityHeaders($response);

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

    private function apiAccessPolicy(): ApiAccess
    {
        if ($this->db === null) {
            return ApiAccess::defaults();
        }

        return ApiAccess::fromSettings(new Settings($this->db));
    }

    private function isPublicApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/') || $path === '/api';
    }

    private function finalizeApiResponse(Response $response, ApiAccess $access, ?string $origin): Response
    {
        return $this->withSecurityHeaders($response->withHeaders($access->corsHeaders($origin)));
    }

    /**
     * Per-token origin / IP allowlist. Runs after authentication, so browser
     * preflight (which carries no Authorization header) stays governed by the
     * global ApiAccess policy and CORS headers keep working on the 403.
     */
    private function enforceTokenPolicy(Request $request, AuthContext $auth): ?Response
    {
        if ($this->tokenPolicies === null || ($auth->token['type'] ?? '') !== 'api') {
            return null;
        }

        $policy = $this->tokenPolicies->forToken($auth->tokenId());
        if (!$policy->isRestricted()) {
            return null;
        }

        $origin = $request->header('origin');
        if (!$policy->allowsOrigin($origin)) {
            $this->audit?->log(
                $request,
                'token.origin_rejected',
                null,
                'token',
                (string) $auth->tokenId(),
                ['origin' => $origin ?? ''],
            );

            return Response::error('FORBIDDEN', 'Origin not allowed for this token', 403);
        }

        if (!$policy->allowsIp($request->ip)) {
            $this->audit?->log(
                $request,
                'token.ip_rejected',
                null,
                'token',
                (string) $auth->tokenId(),
            );

            return Response::error('FORBIDDEN', 'IP not allowed for this token', 403);
        }

        return null;
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
            if ($user === null || ($user['status'] ?? '') !== 'active') {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }
        }

        return new AuthContext($token, $user);
    }

    private function registerRoutes(): void
    {
        $captcha = $this->runtimeSettings !== null ? new CaptchaVerifier($this->runtimeSettings) : null;
        $usersRepo = $this->db !== null ? new UsersRepository($this->db) : null;
        $sectionGrants = $this->db !== null ? new UserSectionGrantRepository($this->db) : null;
        $resourceGrants = $this->db !== null ? new UserResourceGrantRepository($this->db) : null;
        $usersService = $usersRepo !== null
            ? new UsersService($usersRepo, $this->tokens, $sectionGrants, $resourceGrants)
            : null;
        if ($this->db !== null && $sectionGrants !== null && $resourceGrants !== null) {
            $this->userAcl = new UserAclGuard($this->db, $sectionGrants, $resourceGrants);
        }
        $oauth = $this->db !== null && $this->tokens !== null && $this->runtimeSettings !== null
            ? new OAuthService(
                new OAuthSettings($this->runtimeSettings),
                new UserIdentityRepository($this->db),
                $this->tokens,
                new CurlHttpClient(),
                $this->config->appUrl,
                $this->config->appSecret,
                $this->config->adminBase,
            )
            : null;
        $auth = $this->tokens !== null && $this->loginGuard !== null && $this->audit !== null
            ? new AuthController(
                $this->tokens,
                $this->loginGuard,
                $this->audit,
                $this->adminTtlHours,
                $captcha,
                $this->runtimeSettings,
                $this->ipBlocks,
                $usersRepo,
                $oauth,
                $usersService,
            )
            : null;
        $metadata = new MetadataCache(new FileCache($this->paths->cache()));
        $docs = new DocsController(
            new OpenApiGenerator(
                $this->config,
                $this->db !== null ? new ResourceRepository($this->db) : null,
                $this->db !== null ? new FieldRepository($this->db) : null,
                $this->db !== null ? $metadata : null,
                $this->db !== null ? new ResourceApiRepository($this->db) : null,
                $this->db !== null ? new IntegrationApiRepository($this->db) : null,
            ),
        );

        AuthRoutes::register($this->router, $auth);

        /** @var WebhookDispatcher|null $webhookDispatcher */
        $webhookDispatcher = null;
        /** @var UptimeHeartbeatService|null $uptimeHeartbeat */
        $uptimeHeartbeat = null;
        /** @var UptimeScheduler|null $uptimeScheduler */
        $uptimeScheduler = null;

        if ($this->db !== null) {
            $system = new SystemController(
                new ChangelogRepository($this->paths),
                new LatestRelease($this->config),
                $this->db,
                new UpdateService(
                    $this->paths,
                    $this->config,
                    new LatestRelease($this->config),
                    new ChangelogRepository($this->paths),
                    $this->db,
                    new Settings($this->db),
                ),
            );
            SystemRoutes::register($this->router, $system);

            $audit = $this->audit;
            if ($audit === null) {
                throw new \RuntimeException('Audit logger is required');
            }
            $resourceService = new ResourceService(
                $this->db,
                new ContentTypeRepository($this->db),
                new ResourceRepository($this->db),
                $metadata,
            );
            $migrationService = new MigrationService(
                $this->db,
                new ResourceRepository($this->db),
                new FieldRepository($this->db),
                new SqlTypeMapper(new FieldTypeRegistry()),
                new SchemaDiff(),
                $metadata,
            );
            $webhookRepo = new WebhookRepository($this->db);
            $webhookDispatcher = new WebhookDispatcher($webhookRepo);
            $resources = new ResourceController($resourceService, $audit, $migrationService, $webhookDispatcher, $this->userAcl);

            $resourceApiService = new ResourceApiService(
                new ResourceRepository($this->db),
                new ResourceApiRepository($this->db),
                new FieldRepository($this->db),
                $metadata,
            );
            $resourceApis = new ResourceApiController($resourceApiService, $audit);

            $fieldService = new FieldService(
                $this->db,
                new FieldRepository($this->db),
                new ResourceRepository($this->db),
                new FieldTypeRegistry(),
                $metadata,
            );
            $fields = new FieldController($fieldService, $audit);

            $mediaRefs = new MediaRefService($this->db);
            $queryEngine = new QueryEngine(
                $this->db,
                new ResourceRepository($this->db),
                new FieldRepository($this->db),
                null,
                $mediaRefs,
                $this->config->appUrl,
            );
            $entryImportExport = new EntryImportExportService($queryEngine);
            $entryRevisions = new EntryRevisionService($this->db, new Settings($this->db));
            $entryService = new EntryService(
                $queryEngine,
                new ResourceRepository($this->db),
                new UsersRepository($this->db),
            );
            $entries = new EntriesController(
                $entryService,
                $audit,
                $entryImportExport,
                $webhookDispatcher,
                $entryRevisions,
            );

            $migrations = new MigrationController(
                $migrationService,
                $audit,
            );

            $apiTokens = new TokensController(
                new ApiTokenService(
                    $this->db,
                    new TokenService($this->db),
                    new TokenGrantRepository($this->db),
                    new ResourceRepository($this->db),
                    new TokenPolicyRepository($this->db),
                ),
                $audit,
            );

            $webhooksApi = new WebhooksController(
                new WebhookService(
                    $webhookRepo,
                    new ResourceRepository($this->db),
                    $webhookDispatcher,
                ),
                $audit,
            );

            $hookClient = new HookClient();
            $hookDeliveries = new HookDeliveryRepository($this->db);
            $resourceHookService = new ResourceHookService(
                new ResourceHookRepository($this->db),
                $hookDeliveries,
                new ResourceRepository($this->db),
                $hookClient,
            );
            $resourceHooksApi = new ResourceHooksController($resourceHookService, $audit);
            $inboundEndpointService = new InboundEndpointService(
                new InboundEndpointRepository($this->db),
                $hookDeliveries,
                new ResourceRepository($this->db),
                $hookClient,
            );
            $inboundEndpointsApi = new InboundEndpointsController($inboundEndpointService, $audit);

            $users = new UsersController(
                $usersService ?? new UsersService(new UsersRepository($this->db), $this->tokens),
                $audit,
            );

            $mimesRaw = $this->runtimeSettings?->get('security.media_allowed_mimes');
            $mimes = \is_array($mimesRaw) ? array_values(array_filter($mimesRaw, 'is_string')) : null;
            $mediaService = new MediaService(
                $this->db,
                $this->paths,
                $mimes,
                refs: $mediaRefs,
                appUrl: $this->config->appUrl,
            );
            $media = new MediaController(
                $mediaService,
                $audit,
                $this->userAcl,
            );

            $packageService = new ResourcePackageService(
                new ResourceRepository($this->db),
                new ContentTypeRepository($this->db),
                $fieldService,
                $resourceApiService,
                $resourceService,
                $queryEngine,
                $mediaService,
                $migrationService,
            );
            $packages = new ResourcePackageController($packageService, $audit);

            $logs = new LogsController(
                new AuditRepository($this->db),
                new ApiLogRepository($this->db),
                $this->ipBlocks,
                $audit,
            );

            $settingsController = new SettingsController(
                new Settings($this->db),
                $this->paths,
                $this->config->adminBase,
            );
            $settings = new Settings($this->db);
            $emailIntegration = new EmailIntegration($settings);
            $integrations = new IntegrationsController(
                $emailIntegration,
                new Mailer($settings, $emailIntegration),
                new IntegrationApiService(
                    new IntegrationApiRepository($this->db),
                    $metadata,
                ),
                $this->audit ?? new AuditLogger($this->db),
                new OAuthSettings($settings),
                $this->config->appUrl,
                $this->config->adminBase,
            );

            $featureFlags = new FeatureFlagsController(
                new FeatureFlagService(new FeatureFlagRepository($this->db), $settings),
                $audit,
            );
            $translatesApi = new TranslatesController(
                new TranslationService(
                    new LocaleRepository($this->db),
                    new TranslationRepository($this->db),
                    $settings,
                ),
                $audit,
            );
            $keyValuesApi = new KeyValuesController(
                new KeyValueService(new KeyValueRepository($this->db), $settings),
                $audit,
            );

            $uptimeTargets = new UptimeTargetRepository($this->db);
            $uptimeChecks = new UptimeCheckRepository($this->db);
            $uptimeIncidents = new UptimeIncidentRepository($this->db);
            $uptimeSettings = new UptimeSettings($settings);
            $uptimeProbes = new UptimeProbeService($uptimeTargets, $uptimeChecks, $uptimeIncidents, $uptimeSettings);
            $healthUrl = $this->config->adminBase->healthUrl($this->config->appUrl);
            $uptimeStatus = new UptimeStatusService(
                $uptimeTargets,
                $uptimeIncidents,
                $uptimeSettings,
                $healthUrl,
            );
            $uptimeService = new UptimeService(
                $uptimeTargets,
                $uptimeChecks,
                $uptimeIncidents,
                $uptimeProbes,
                $uptimeStatus,
                $uptimeSettings,
                $healthUrl,
            );
            $uptimeHeartbeat = new UptimeHeartbeatService(
                $uptimeTargets,
                $uptimeChecks,
                $uptimeIncidents,
                $uptimeSettings,
                $healthUrl,
            );
            $uptimeScheduler = new UptimeScheduler(
                $this->paths,
                $uptimeTargets,
                $uptimeProbes,
                $uptimeSettings,
            );
            $uptimeApi = new UptimeController($uptimeService, $audit, $uptimeScheduler);

            AdminResourceRoutes::register(
                $this->router,
                $resources,
                $resourceApis,
                $fields,
                $entries,
                $migrations,
                $apiTokens,
                $webhooksApi,
                $users,
                $packages,
                $media,
                $logs,
                $settingsController,
                $integrations,
                $resourceHooksApi,
                $inboundEndpointsApi,
            );
            FeatureTranslatesRoutes::registerAdmin($this->router, $featureFlags, $translatesApi);
            KeyValuesRoutes::registerAdmin($this->router, $keyValuesApi);
            UptimeRoutes::register($this->router, $uptimeApi);
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
            $resourceApiRepo = new ResourceApiRepository($this->db);
            $integrationApiService = new IntegrationApiService(
                new IntegrationApiRepository($this->db),
                $metadata,
            );
            $settingsForMail = new Settings($this->db);
            $emailIntegration = new EmailIntegration($settingsForMail);
            $mailer = new Mailer($settingsForMail, $emailIntegration, $this->rateLimitStore);
            $tokenGrants = new TokenGrantRepository($this->db);
            $publicIntegrations = new PublicIntegrationApiController(
                $mailer,
                $integrationApiService,
                $tokenGrants,
                $this->audit,
            );
            foreach (['/api', '/api/v1'] as $apiPrefix) {
                $this->router->add('POST', $apiPrefix . '/integrations/email/send', function (Request $request, array $params, ?AuthContext $context) use ($publicIntegrations): Response {
                    unset($params);

                    return $publicIntegrations->sendEmail($request, $context);
                }, true, 'api');
                $this->router->add('POST', $apiPrefix . '/integrations/email/{slug}', function (Request $request, array $params, ?AuthContext $context) use ($publicIntegrations): Response {
                    return $publicIntegrations->sendEmailCustom($request, (string) $params['slug'], $context);
                }, true, 'api');
            }

            $settingsForFlags = new Settings($this->db);
            $featureFlagsPublic = new FeatureFlagsController(
                new FeatureFlagService(new FeatureFlagRepository($this->db), $settingsForFlags),
                $this->audit ?? new AuditLogger($this->db),
            );
            $translatesPublic = new TranslatesController(
                new TranslationService(
                    new LocaleRepository($this->db),
                    new TranslationRepository($this->db),
                    $settingsForFlags,
                ),
                $this->audit ?? new AuditLogger($this->db),
            );
            $featuresPath = (new FeatureFlagService(new FeatureFlagRepository($this->db), $settingsForFlags))->getApiSettings()['path'];
            $translatesPath = (new TranslationService(
                new LocaleRepository($this->db),
                new TranslationRepository($this->db),
                $settingsForFlags,
            ))->getApiSettings()['path'];
            $keyValuesPublic = new KeyValuesController(
                new KeyValueService(new KeyValueRepository($this->db), $settingsForFlags),
                $this->audit ?? new AuditLogger($this->db),
            );
            $kvPath = (new KeyValueService(new KeyValueRepository($this->db), $settingsForFlags))->getApiSettings()['path'];
            FeatureTranslatesRoutes::registerPublic(
                $this->router,
                $featureFlagsPublic,
                $translatesPublic,
                $featuresPath,
                $translatesPath,
            );
            KeyValuesRoutes::registerPublic($this->router, $keyValuesPublic, $kvPath);

            $spamGuard = ($this->runtimeSettings !== null)
                ? new SpamGuard(
                    $captcha ?? new CaptchaVerifier($this->runtimeSettings),
                    $this->rateLimitStore,
                )
                : null;
            $hookClient = new HookClient();
            $hookDeliveries = new HookDeliveryRepository($this->db);
            $resourceHookService = new ResourceHookService(
                new ResourceHookRepository($this->db),
                $hookDeliveries,
                new ResourceRepository($this->db),
                $hookClient,
            );
            $inboundEndpointService = new InboundEndpointService(
                new InboundEndpointRepository($this->db),
                $hookDeliveries,
                new ResourceRepository($this->db),
                $hookClient,
            );
            $queryEnginePublic = new QueryEngine(
                $this->db,
                new ResourceRepository($this->db),
                new FieldRepository($this->db),
                $resourceApiRepo,
                new MediaRefService($this->db),
                $this->config->appUrl,
            );
            $publicApi = new PublicApiController(
                $queryEnginePublic,
                new ResourceRepository($this->db),
                $tokenGrants,
                $resourceApiRepo,
                $spamGuard,
                $webhookDispatcher,
                $resourceHookService,
            );
            $publicInbound = new PublicInboundController(
                $inboundEndpointService,
                new ResourceRepository($this->db),
                $queryEnginePublic,
                $resourceHookService,
                $spamGuard,
                $webhookDispatcher,
            );
            PublicApiRoutes::register($this->router, $publicApi, $publicInbound);
        }

        $this->router->add('GET', '/admin/api/health', function (Request $request, array $params, ?AuthContext $context) use ($uptimeHeartbeat, $uptimeScheduler): Response {
            unset($request, $params, $context);
            if ($uptimeHeartbeat !== null && $this->installed) {
                try {
                    $uptimeHeartbeat->touch();
                } catch (Throwable) {
                    // Health must never fail because of uptime bookkeeping.
                }
            }
            if ($uptimeScheduler !== null && $this->installed) {
                try {
                    // skipSelf: heartbeat covers CMS; probing self would recurse into health.
                    $uptimeScheduler->scheduleAfterResponse(true);
                } catch (Throwable) {
                    // Soft cron must never break health.
                }
            }

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
        if (
            $path === '/admin/api/health'
            || $path === '/admin/api/settings/locale'
            || $path === '/api/docs'
            || $path === '/api/openapi.json'
        ) {
            return false;
        }
        if ($path === '/api/v1/docs' || $path === '/api/v1/openapi.json') {
            return false;
        }

        return str_starts_with($path, '/admin/api')
            || str_starts_with($path, '/api/')
            || preg_match('#^/media/\\d+(/[^/]+)?$#', $path) === 1;
    }

    private function rateLimit(Request $request, ?AuthContext $auth): ?Response
    {
        if (preg_match('#^/media/\\d+(/[^/]+)?$#', $request->path) === 1 && $this->mediaLimiter !== null) {
            $bucket = 'media:ip:' . $request->ip;
            if (!$this->mediaLimiter->hit($bucket)) {
                return Response::tooManyRequests($this->mediaLimiter->retryAfter($bucket), $this->mediaLimiter->limit());
            }
        }

        if (
            $this->anonWriteLimiter !== null
            && $auth === null
            && \in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && str_starts_with($request->path, '/api/')
        ) {
            $bucket = 'anon-write:ip:' . $request->ip;
            if (!$this->anonWriteLimiter->hit($bucket)) {
                return Response::tooManyRequests($this->anonWriteLimiter->retryAfter($bucket), $this->anonWriteLimiter->limit());
            }
        }

        if ($this->ipLimiter !== null) {
            $bucket = 'ip:' . $request->ip;
            if (!$this->ipLimiter->hit($bucket)) {
                return Response::tooManyRequests($this->ipLimiter->retryAfter($bucket), $this->ipLimiter->limit());
            }
        }

        if ($auth !== null) {
            $limiter = $auth->isAdmin() ? $this->tokenLimiter : $this->apiTokenLimiter;
            $bucket = 'token:' . $auth->tokenId();
            if ($limiter !== null && !$limiter->hit($bucket)) {
                return Response::tooManyRequests($limiter->retryAfter($bucket), $limiter->limit());
            }
        }

        return null;
    }

    private function spa(): Response
    {
        $index = (new AdminUiPublisher($this->paths))->resolveIndex();
        // The shell names the hashed bundles, so a cached copy pins the browser to the
        // previous build. Static hits on /admin/index.html get this from the web server,
        // but every deep link is served from here instead.
        $noCache = ['Cache-Control' => 'no-cache'];

        if (!is_file($index)) {
            return Response::html('<!doctype html><html><body><p>Admin UI is not built. Run <code>npm run build</code>.</p></body></html>', 503)
                ->withHeaders($noCache);
        }

        $html = (string) file_get_contents($index);
        $html = $this->injectAdminRuntimeConfig($html);

        return Response::html($html)->withHeaders($noCache);
    }

    private function injectAdminRuntimeConfig(string $html): string
    {
        $json = json_encode(
            $this->config->adminBase->toPublicArray(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $script = '<script>window.__HCMS__=' . $json . ';</script>';
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $script . '</head>', $html, 1);
        }

        return $script . $html;
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
