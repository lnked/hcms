# Authentication

Единственный механизм:

```http
Authorization: Bearer <token>
```

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

## Rate limit

`/admin/api/*` и `/api/*` (кроме docs/health): 120 req/min на IP, 300 req/min на токен.

## Audit

`auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.login_denied`, `auth.logout` пишутся в `cms_audit_logs`. Пароли не логируются.
