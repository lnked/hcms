# Authentication

Единственный механизм сессии админки:

```http
Authorization: Bearer <token>
```

Фронт хранит токен в `localStorage` (`hcms_token`). `POST /admin/api/auth/login` принимает `remember: true` — тогда TTL = `auth.remember_token_ttl_hours` (default 720 / 30 дней), иначе `auth.admin_token_ttl_hours` (default 12). `POST /admin/api/auth/logout` ревокает текущий токен.

On Apache/CGI shared hosting the `Authorization` header is often stripped before PHP.
HCMS restores it via web-root `.htaccess` (`HTTP_AUTHORIZATION`) and `Request::authorizationFromGlobals()`.
If Bearer auth still fails after deploy, confirm that `.htaccess` contains the `HTTP_AUTHORIZATION` rewrite.

Login:

```http
POST /admin/api/auth/login
{ "email": "...", "password": "...", "remember": false }
```

Ответ: `{ "data": { "token": "...", "expiresAt": "2026-09-06 04:00:00", "user": { ... } } }`.

Токен в БД не хранится открытым: `token_prefix` + `sha256`.

## Social login (Google / Telegram)

Новые аккаунты **не создаются**.

- **Google**: вход, если verified email совпадает с `cms_users.email` (identity создаётся автоматически) или Google id уже привязан в Аккаунте.
- **Telegram**: вход только после ручной привязки в `/admin/settings/account`.

Настройки провайдеров: Аккаунт → Вход через соцсети (`auth.google`, `auth.telegram` в `cms_settings`). Redirect URI: `{APP_URL}/admin/api/auth/google/callback`.

```http
GET  /admin/api/auth/providers
GET  /admin/api/auth/google/start
GET  /admin/api/auth/google/callback
POST /admin/api/auth/telegram
POST /admin/api/auth/totp/complete          # { ticket, totpCode } после Google+2FA
GET  /admin/api/auth/identities
POST /admin/api/auth/identities/google/start
POST /admin/api/auth/identities/telegram
DELETE /admin/api/auth/identities/{google|telegram}
```

Google callback редиректит на `/admin/oauth/complete#token=...` (fragment, не query). Если включён TOTP — `#ticket=...`.

## Смена своего пароля

```http
POST /admin/api/auth/password
{ "currentPassword": "...", "newPassword": "..." }
```

Доступно любой роли (`/admin/api/auth/*` не требует capability) — раздел Аккаунт в админке. Ответ: `{ "data": { "ok": true, "revokedSessions": 2 } }`.

- Текущий пароль обязателен; неверный → `422 VALIDATION_ERROR` с `error.fields.currentPassword` и штрафом в brute-force бакеты логина (IP + email).
- Новый пароль проверяется политикой `Cms\Auth\Password` (≥ 8 символов, буква + цифра) и не может совпадать с текущим.
- После смены ревокаются все admin-токены пользователя, кроме текущего — остальные устройства разлогиниваются. API-токены приложений (`type = api`) не затрагиваются.

Смена пароля другому пользователю — по-прежнему `PATCH /admin/api/users/{id}` (нужен `users.write`, старый пароль не требуется).

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

Окно скользящее: предыдущее минутное окно учитывается с весом оставшейся части, поэтому лимит нельзя удвоить всплеском на границе минуты. `Retry-After` считается по конкретному бакету — сколько ждать, пока вес хвоста освободит слот.

Trusted proxies: `security.trusted_proxies` (CIDR/IP list) — тогда IP берётся из `X-Forwarded-For`.

## Public create spam

В `settings.spam` ресурса: honeypot, minSubmitMs, rateLimitPerMinute, requireCaptcha, maxLinks, blocklist, rejectDuplicates.
По умолчанию включён только `rejectDuplicates`; разбор каждого параметра, порядок проверок и слабые места — [anti-spam.md](anti-spam.md).

## Audit

`auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.login_denied`, `auth.logout`, `auth.totp_*`, `auth.password_changed`, `auth.password_change_failed`, `auth.password_change_blocked`, `auth.identity_linked`, `auth.identity_unlinked`, `security.ip_blocked`, `integration.email.denied` пишутся в `cms_audit_logs`. Пароли не логируются.
