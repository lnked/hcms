# Improvements roadmap

Чеклист доработок HCMS.

- [x] A Tech debt (clean.php, htaccess, frontend devDeps, CHANGELOG, Phase comments, ChangelogPage states)
- [x] B CI GitHub Actions (`.github/workflows/ci.yml` + badge)
- [x] C Webhooks (migration 005, HMAC dispatcher, admin CRUD + UI)
- [x] D Content revisions (migration 006, EntryRevisionService, restore API + UI)
- [x] E Rich text markdown (`richtext` field + editor/preview)
- [x] F Dashboard analytics (timeseries + recharts)
- [x] G UX (column filters, mobile nav drawer, bulk delete)
- [x] H RBAC (roles owner/admin/editor/viewer, RolePolicy, UI)
- [x] I Test coverage (PHPUnit 101, Vitest 34)
- [x] J Docker (`Dockerfile` + `docker-compose.yml` + docs)
- [ ] K Anti-spam на публичные update/delete
- [ ] L Автобан IP по спаму
- [ ] M Нейтральный текст отказа спам-проверок
- [ ] N Подсети (CIDR) в блокировках IP

Контекст к K–N: [anti-spam.md](anti-spam.md), раздел «Известные ограничения». Порядок — K → M → L → N: K закрывает дыру, M дешёвый и меняет тот же код, L опирается на сигнал, который добавит M, N независим.

## K. Anti-spam на публичные update/delete

Сейчас `PublicApiController::guardAnonymousCreate` вызывается только из `create()`, поэтому при `settings.public.update` / `.delete` анонимный `PATCH` / `DELETE` держат лишь глобальные лимиты из `Kernel::rateLimit`.

1. Переименовать в `guardAnonymousWrite(Request $request, string $slug, string $action, array &$payload)` и звать из `update()` и `delete()` при `$auth === null` (для `delete()` payload пустой массив).
2. Бакет `spam:write:<slug>:<ip>` оставить общим на все глаголы: `rateLimitPerMinute` = «столько анонимных записей в ресурс в минуту», а не отдельный бюджет на каждый метод.
3. Проверки, которым нужен payload (honeypot, maxLinks, blocklist, minSubmitMs), на `DELETE` отключаются сами — пустой payload их не триггерит. Явно передавать `$action` нужно только ради одного исключения: `rejectDuplicates` на `DELETE` ломает идемпотентность (повторный `DELETE /api/leads/7` — нормальный ретрай, а не спам), поэтому дубли проверять только для `create` и `update`.
4. `requireCaptcha` на `DELETE` берёт токен из заголовка `X-Captcha-Token` — в теле его взять неоткуда.
5. Тесты: `SpamGuardTest` — дубли не режутся при `action = 'delete'`; `PublicApiRoutingTest` — анонимный `PATCH` с заполненной ловушкой отдаёт 422.

Риск: клиент, который слал в публичный `PATCH` поле с именем honeypot, начнёт получать 422. Ловушки — редкие имена (`website`, `url`), плюс поле и так вычищается из payload при create.

Оценка: ~2 часа.

## M. Нейтральный текст отказа

Сейчас `SpamGuard` возвращает `422` с точной причиной (`Spam check failed`, `Too many links`, `Content blocked`, `Duplicate submission`) — бот по сообщению понимает, какая проверка сработала, и подстраивается за несколько попыток.

1. В `SpamGuard` завести `SpamRejected extends InvalidArgumentException` с полем `reason` (машинный код: `honeypot`, `too_fast`, `captcha`, `links`, `blocklist`, `duplicate`).
2. Наружу — один текст на все причины: `422 VALIDATION_ERROR / "Submission rejected"`. Причина уходит в audit (`security.spam_rejected`, см. L) и в `cms_api_logs`.
3. Исключение — `captcha`: «капча не пройдена» полезно показать живому человеку. Оставить отдельный текст, но без разделения «не настроена / не прошла» (первое — подсказка о конфиге сервера).
4. Тесты: `SpamGuardTest` проверяет `reason` вместо строки сообщения.

Оценка: ~1 час. Делать до L — audit-событие из п. 2 и есть сигнал, на котором L строится.

## L. Автобан IP по спаму

`AuthController::maybeAutoBlockIp` умеет банить по `auth.login_blocked`, но спам в публичный API не оставляет audit-следа вообще: `SpamGuard` бросает исключение, и дальше остаётся только строка в `cms_api_logs` со статусом 422/429.

1. Писать audit `security.spam_rejected` (ip, slug, reason из M) при отказе `SpamGuard` в `PublicApiController`. Под флудом это запись в БД на каждый запрос, поэтому сэмплировать: одна запись на IP в минуту через тот же `RateLimiter` (бакет `spam:audit:<ip>`, лимит 1) — для порога важен факт, а не точный счёт.
2. Обобщить автобан: вынести из `AuthController` в `Cms\Security\AutoBlock` с сигнатурой `maybeBlock(Request $request, string $action, int $threshold, string $reason)` — внутри уже существующий `IpBlockRepository::countAuditActions`. `AuthController` переводится на него без изменения поведения.
3. Новая настройка `security.ip_auto_block_after_spam_rejects`, default **0 = выключено** (иначе апгрейд начнёт банить живых посетителей форм). Окно и TTL переиспользовать: `security.ip_auto_block_window_seconds`, `security.ip_auto_block_ttl_seconds`. Причина бана в `cms_ip_blocks` — `auto:spam_rejected`.
4. Порог считать только по «сильным» причинам (`honeypot`, `blocklist`, `too_fast`): `duplicate` и `links` слишком часто дают ложные срабатывания на живом трафике.
5. UI: поле в **Settings → System** рядом с остальными `ip_auto_block_*`; в **Logs → Security** причина уже отображается как есть.
6. Тесты: unit на `AutoBlock` (порог, окно, идемпотентность повторного бана), тест на сэмплирование audit.

Риск: самобан офиса за общим NAT. Смягчается дефолтом 0, коротким TTL и тем, что `security.trusted_proxies` должен быть настроен — иначе за прокси в бан уедет один общий IP и ляжет весь трафик.

Оценка: ~4 часа.

## N. CIDR в блокировках IP

`IpBlockRepository::isBlocked` сравнивает точное совпадение строки, поэтому спам с /24 приходится банить по одному адресу.

1. Хранить в `cms_ip_blocks.ip` как есть, но принимать запись вида `1.2.3.0/24`. Матчинг уже написан: `ClientIp::matchesAny($ip, $entries)` умеет и точные адреса, и маски, для IPv4 и IPv6.
2. `isBlocked` не может остаться одним индексным `SELECT`: точные адреса ищутся как сейчас, подсети (`ip LIKE '%/%'`) выбираются отдельным запросом и проверяются в PHP. Их обычно единицы, но запрос на каждый HTTP-запрос — держать за кешем в `MetadataCache` с инвалидацией на block/unblock.
3. Валидация в `LogsController::blockIp`: сейчас `filter_var(..., FILTER_VALIDATE_IP)` режет всё с маской.
4. Тесты: `ClientIpTest` уже покрывает матч по CIDR, нужен тест репозитория на смешанный список.

Оценка: ~3 часа.
