<?php

declare(strict_types=1);

namespace Cms\Core;

final class Config
{
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $debug,
        public readonly string $appUrl,
        public readonly string $appSecret,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $dbCharset,
        public readonly string $githubRepo,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            appEnv: $env->get('APP_ENV', 'production') ?? 'production',
            debug: $env->bool('APP_DEBUG', false),
            appUrl: rtrim($env->get('APP_URL', 'http://localhost') ?? 'http://localhost', '/'),
            appSecret: $env->get('APP_SECRET', '') ?? '',
            dbHost: $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            dbPort: (int) ($env->get('DB_PORT', '3306') ?? '3306'),
            dbName: $env->get('DB_DATABASE', '') ?? '',
            dbUser: $env->get('DB_USERNAME', '') ?? '',
            dbPassword: $env->get('DB_PASSWORD', '') ?? '',
            dbCharset: $env->get('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4',
            githubRepo: $env->get('CMS_GITHUB_REPO', 'lnked/hcms') ?? 'lnked/hcms',
        );
    }

    public function isInstalled(): bool
    {
        return $this->appSecret !== '' && $this->dbName !== '';
    }
}
