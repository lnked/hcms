# Authentication

Единственный механизм:

```http
Authorization: Bearer <token>
```

On Apache/CGI shared hosting the `Authorization` header is often stripped before PHP.
HCMS restores it via web-root `.htaccess` (`HTTP_AUTHORIZATION`) and `Request::authorizationFromGlobals()`.
If Bearer auth still fails after deploy, confirm that `.htaccess` contains the `HTTP_AUTHORIZATION` rewrite.

Login:

```http
POST /admin/api/auth/login
{ "email": "...", "password": "..." }
```

Ответ: `{ "data": { "token": "...", "expiresAt": "2026-09-06 04:00:00", "user": { ... } } }`.

Токен в БД не хранится открытым: `token_prefix` + `sha256`. Logout ревокает текущий токен.

## Expiry

Admin-токен живёт `auth.admin_token_ttl_hours` (по умолчанию 12). Просроченный → 401.

## Brute-force

После 5 неудачных логинов с одного IP или email за 15 минут → `429 TOO_MANY_REQUESTS` + `Retry-After`.

Настройки: `security.login_max_attempts`, `security.login_window_seconds`.

После N fails (default 2) при настроенном captcha (`security.captcha`) логин требует `captchaToken`.

Если у пользователя включён TOTP — в теле логина нужен `totpCode` (иначе `401 TOTP_REQUIRED`).

Disable user ревокает admin-токены; resolve отклоняет токены disabled-аккаунтов.

## Rate limit

`/admin/api/*` и `/api/*` (кроме docs/health): 120 req/min на IP, 300 req/min на admin-токен, 120 на API-токен.

Дополнительно:

- `/media/{id}` — `security.rate_limit_media_per_minute` (default 60)
- анонимные write на `/api/*` — `security.rate_limit_anon_write_per_minute` (default 20)

429 includes `Retry-After` and `X-RateLimit-Limit`.

Trusted proxies: `security.trusted_proxies` (CIDR/IP list) — тогда IP берётся из `X-Forwarded-For`.

## Public create spam

В `settings.spam` ресурса: honeypot, minSubmitMs, rateLimitPerMinute, requireCaptcha, maxLinks, blocklist, rejectDuplicates.

## Audit

`auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.login_denied`, `auth.logout`, `auth.totp_*`, `security.ip_blocked`, `integration.email.denied` пишутся в `cms_audit_logs`. Пароли не логируются.
