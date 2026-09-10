# Защита публичной записи (anti-spam)

Документ про то, что происходит с запросом **без токена** на `POST /api/{slug}` — когда у ресурса включён `settings.public.create`. Всё остальное (`/admin/api/*`, токены, логин) защищено отдельно, см. [authentication.md](authentication.md).

Ключевые файлы: [`src/Http/Kernel.php`](../src/Http/Kernel.php) (`handle`, `rateLimit`), [`src/Security/SpamGuard.php`](../src/Security/SpamGuard.php), [`src/Security/IpBlockRepository.php`](../src/Security/IpBlockRepository.php), [`src/Http/Controllers/PublicApiController.php`](../src/Http/Controllers/PublicApiController.php) (`guardAnonymousCreate`), [`src/Auth/RateLimiter.php`](../src/Auth/RateLimiter.php).

## Порядок проверок

Запрос проходит слои сверху вниз, первый отказ прекращает обработку:

| # | Слой | Где | Ключ / бакет | Отказ |
|---|------|-----|--------------|-------|
| 1 | Резолв реального IP | `Kernel::handle` → `ClientIp::resolve` | `security.trusted_proxies` | — |
| 2 | Бан IP | `Kernel::handle` → `IpBlockRepository::isBlocked` | таблица `cms_ip_blocks` | `403 FORBIDDEN` |
| 3 | Лимит анонимной записи | `Kernel::rateLimit` | `anon-write:ip:<ip>` | `429` + `Retry-After` |
| 4 | Общий лимит по IP | `Kernel::rateLimit` | `ip:<ip>` | `429` + `Retry-After` |
| 5 | CORS / API access | `Kernel::handle` | allowed origins | `403` на preflight |
| 6 | Права ресурса | `PublicApiController::authorize` | `settings.public.create` | `403` |
| 7 | `settings.spam` ресурса | `SpamGuard::assertCreateAllowed` | см. ниже | `429` для `rateLimitPerMinute`, `422 VALIDATION_ERROR` для остальных |
| 8 | Валидация схемы | `QueryEngine::create` | типы/required полей | `422` |

Отказ по лимиту — всегда `429 TOO_MANY_REQUESTS` с `Retry-After` и `X-RateLimit-Limit`, независимо от того, сработал глобальный лимитер (слои 3–4) или `rateLimitPerMinute` ресурса (слой 7): клиенту незачем разбирать два разных кода для одного и того же «притормози». Остальные спам-проверки остаются `422` с текстом причины.

Что ломает ожидания:

- **SpamGuard работает только на create и только для анонима.** `guardAnonymousCreate` вызывается из `create()` при `$auth === null`. Публичные `PATCH` / `DELETE` (`settings.public.update` / `.delete`) не проходят ни одну проверку из `settings.spam` — их держат только глобальные лимиты слоёв 3–4. Открывать публичный update/delete без токена == отдать таблицу наружу.

## Слой 1: глобальные лимиты

Значения лежат в настройках (создаются инсталлятором, [`src/Install/Installer.php`](../src/Install/Installer.php)):

| Ключ | Default | На что | Бакет |
|------|---------|--------|-------|
| `security.rate_limit_anon_write_per_minute` | 20 | `POST/PUT/PATCH/DELETE` на `/api/*` **без токена** | `anon-write:ip:<ip>` |
| `security.rate_limit_ip_per_minute` | 120 | вообще все запросы с IP | `ip:<ip>` |
| `security.rate_limit_token_per_minute` | 300 | админ-токен | `token:<tokenId>` |
| `security.rate_limit_api_token_per_minute` | 120 | API-токен | `token:<tokenId>` |
| `security.rate_limit_media_per_minute` | 60 | `GET /media/{id}` (+ optional `/{filename}`) | `media:ip:<ip>` |

Механика окна — **скользящее окно на двух счётчиках**: `RateLimiter` складывает попадания в `cms_rate_limits (bucket, window_start, hits)` по минутным окнам, но при проверке учитывает и предыдущее окно с весом той части, что ещё попадает в последние 60 секунд: `hits(текущее) + hits(предыдущее) × (60 − прошло) / 60`. Следствия:

- всплеск `2 × limit` на стыке минут (20 запросов в 12:00:59 + 20 в 12:01:00) больше не проходит: сразу после границы предыдущее окно весит почти единицу;
- бюджет возвращается плавно, а не рывком в начале минуты;
- `Retry-After` считается по бакету: сколько секунд должно пройти, чтобы вес предыдущего окна освободил место под один запрос. Без бакета (`retryAfter()` без аргумента) ответ деградирует до конца текущего окна — это никогда не раньше времени;
- отжившие строки `cms_rate_limits` подчищает сам `DatabaseRateLimitStore`: примерно один `increment` из 500 удаляет до 1000 строк старше суток. Крон не нужен, но и мгновенной очистки нет — после всплеска таблица усыхает за несколько сотен последующих запросов. Retention в сутки задан с запасом под самое длинное окно в системе — логиновое `security.login_window_seconds` (900 с по умолчанию); если поставить его больше суток, счётчик попыток начнёт обнуляться раньше времени.

**IP берётся из соединения**, `X-Forwarded-For` учитывается только если источник попал в `security.trusted_proxies` (список IP/CIDR). Если CMS стоит за прокси/CDN и список пуст — все посетители схлопываются в один бакет, и 20 анонимных записей в минуту делятся на всех сразу. Это ровно кейс 2js.ru, описанный в [landing-downloads.md](landing-downloads.md).

## Слой 2: `settings.spam` ресурса

Админка: `/admin/resources/{id}` → **Settings** → блок Spam. Нормализация и дефолты — `ResourceService::defaultSettings` / `normalizeSettings` в [`src/Resources/ResourceService.php`](../src/Resources/ResourceService.php).

```json
{
  "spam": {
    "honeypotField": "",
    "minSubmitMs": 0,
    "rateLimitPerMinute": 0,
    "requireCaptcha": false,
    "maxLinks": 0,
    "blocklist": [],
    "rejectDuplicates": true
  }
}
```

Проверки внутри `SpamGuard` идут строго в этом порядке: rate limit → honeypot → minSubmitMs → captcha → maxLinks → blocklist → duplicates. Порядок важен: `rateLimitPerMinute` **тратит бюджет до** остальных проверок, поэтому отбитый honeypot-ом бот всё равно съедает слот минуты у живых посетителей.

Служебные ключи вычищаются из payload перед записью: поле-ловушка (`honeypotField`), `captchaToken`, `_startedAt`. В схему ресурса их добавлять не нужно.

### `rateLimitPerMinute`

Сколько анонимных create в минуту разрешено с одного IP на этом ресурсе. `0` — выключено.

- Бакет: `spam:write:<slug>:<ip>`, окно 60 с, тот же скользящий `RateLimiter`. Счётчик свой у каждого ресурса: строгий лимит на форме не режет соседний счётчик кликов.
- Отказ: `429 TOO_MANY_REQUESTS` с `Retry-After` и `X-RateLimit-Limit` (= `rateLimitPerMinute`). Внутри это `Cms\Security\RateLimitExceeded`, который `PublicApiController` ловит отдельно от валидационных ошибок.
- Дублирует глобальный `security.rate_limit_anon_write_per_minute`, но считается **после** него, поэтому имеет смысл только если он строже 20 — и, в отличие от глобального, он per-resource.

Практическое значение: единственная включённая по умолчанию защита от накрутки в сценариях, где остальные проверки отключены (счётчик кликов). От распределённого спама с пула IP не спасает вообще.

### `honeypotField`

Имя поля-ловушки в форме. Пусто — выключено.

- Срабатывает, **только если ключ присутствует в payload**: `array_key_exists($honeypot, $payload)`. Бот, который шлёт JSON по OpenAPI-схеме и вообще не знает про поле, проходит насквозь — ловушка ловит только тех, кто парсит HTML-форму и заполняет все input-ы.
- «Заполнено» = непустая строка после `trim`, либо любое значение кроме `null`, `false`, `0`, `0.0`. Пустая строка и `false` — норма.
- Отказ: `422 Spam check failed`. Поле удаляется из payload при успехе.
- В разметке поле надо прятать CSS-ом (`position:absolute;left:-9999px`) + `autocomplete="off"` + `tabindex="-1"`, а не `type="hidden"`: скрытые инпуты боты часто пропускают.

Цена — ноль (без внешних запросов, без UX-трения), поэтому включать стоит на любой публичной форме. Эффективность против современных headless-ботов — низкая.

### `minSubmitMs`

Минимальное время между отрисовкой формы и отправкой, в миллисекундах. `0` — выключено. Разумные значения — 1500–3000.

- Источник времени старта: `payload._startedAt` **или** заголовок `X-Form-Started-At`. Значение — unix-время в **миллисекундах** (`Date.now()`).
- Если ни того, ни другого нет или значение не числовое — **проверка молча пропускается**. То есть бот, не приславший метку, проходит. Это клиентская подсказка, а не барьер.
- Отказ: `422 Submission too fast` при `0 <= elapsed < minSubmitMs`. Отрицательный `elapsed` (часы клиента спешат) пропускается.
- Клиент: `fetch('/api/leads', { body: JSON.stringify({ ...values, _startedAt: startedAt }) })`, где `startedAt = Date.now()` в момент монтирования формы.

Ловит скрипты, отправляющие форму мгновенно; не ловит того, кто добавит `sleep`.

### `requireCaptcha`

Требовать проверенный токен Turnstile / hCaptcha.

- Токен ищется в `payload.captchaToken`, затем в заголовке `X-Captcha-Token`.
- Провайдер настраивается глобально: `security.captcha` (`enabled`, `provider` = `turnstile` | `hcaptcha`, `siteKey`, `secretKey`), Settings → System. `siteKey` для фронта отдаёт публичный `GET /admin/api/auth/captcha`.
- **Если captcha не настроена, а флаг включён — падают все анонимные create**: `422 Captcha is required but not configured`. Включать флаг только после того, как ключи сохранены и проверены.
- Верификация — синхронный HTTP-POST на `challenges.cloudflare.com` / `hcaptcha.com` с таймаутом 5 с из [`CaptchaVerifier`](../src/Security/CaptchaVerifier.php). Провайдер лёг или исходящие запросы закрыты фаерволом → `422 Captcha verification failed` на всех формах и +5 с к каждому запросу. Для форм с высоким трафиком это заметная нагрузка на php-fpm воркеры.

Единственная проверка из списка, которая реально останавливает массовый автоматизированный спам. Всё остальное — фильтры от ленивых ботов.

### `maxLinks`

Максимум ссылок во всём payload. `0` — выключено.

- Считается `preg_match_all('#https?://#i', $text)` по **всем** строковым и числовым значениям, рекурсивно (`flattenText`), включая вложенные объекты и массивы.
- Считаются только явные `http://` / `https://`. `www.example.com`, `t.me/spam`, `example[.]com` — не считаются.
- Отказ: `422 Too many links` при `count > maxLinks`.
- Осторожно с полями, куда ссылка попадает штатно (`referrer`, `url`, `source`): они тоже входят в подсчёт. Для формы «имя + телефон + комментарий» рабочее значение `maxLinks = 0…1`.

### `blocklist`

Список стоп-слов. Пустой — выключено. В админке вводится через запятую.

- Матч — **подстрочный**, регистронезависимый (`mb_strtolower` + `str_contains`) по тому же склеенному тексту payload.
- Отказ: `422 Content blocked`.
- Подстрока значит подстроку: `casino` заматчит `Casinovo`, а `сайт` — `посайтил`. Ложные срабатывания молча теряют лида, поэтому лучше держать список коротким и из редких токенов (`bit.ly`, `viagra`, `заработок в интернете`), а не общих слов.
- Регулярок, границ слова и юникод-нормализации нет: `с a s i n o` и `сasino` (кириллическая `с`) проходят.

### `rejectDuplicates`

**Единственный пункт, включённый по умолчанию** (`true`).

- Ключ: `sha256(slug + '|' + ip + '|' + json_encode(payload))`, окно **600 секунд**, лимит — 1 запись. Бакет `spam:dup:<hash>`. Slug в хеше нужен, чтобы одинаковый payload в разные ресурсы (`{"name":"Ada"}` в `leads` и в `comments`) не считался повтором.
- Отказ: `422 Duplicate submission` на второй идентичный payload с того же IP в течение 10 минут.
- Хеш считается по JSON **в исходном порядке ключей**: `{"a":1,"b":2}` и `{"b":2,"a":1}` — разные хеши. Любое меняющееся поле (клиентский timestamp, uuid, `referrer`) полностью обнуляет проверку.
- Обратная сторона — **тихая потеря легитимных повторов**. Для счётчика кликов, где payload по построению одинаковый, это означает, что все скачивания с одного IP за 10 минут схлопываются в одно. Ровно поэтому счётчик скачиваний на 2js.ru вообще ушёл с анонимной записи на серверную по токену: весь `settings.spam` применяется только к `$auth === null`, а троттлинг переехал туда, где виден настоящий `REMOTE_ADDR`. См. [landing-downloads.md](landing-downloads.md) и `scripts/setup-downloads.php`.
- Для форм обратной связи — наоборот, оставлять включённым: защищает от дабл-клика по кнопке и от ретраев `keepalive`-запроса.

## Слой 3: блокировка IP

Таблица `cms_ip_blocks`, проверка на **каждом** запросе в `Kernel::handle` до роутинга: совпадение по точному IP при `expires_at IS NULL OR expires_at > now` → `403 FORBIDDEN "IP blocked"`. Диапазоны/CIDR не поддерживаются, только точные адреса.

Админка: **Logs → Security**. API:

```http
GET    /admin/api/logs/ip-blocks?page=1&limit=50
POST   /admin/api/logs/ip-blocks     {"ip":"1.2.3.4","reason":"spam","ttlSeconds":3600}
DELETE /admin/api/logs/ip-blocks/{id}
```

`ttlSeconds <= 0` → бан бессрочный (`expires_at = NULL`). Повторный `POST` по тому же IP обновляет причину и TTL, а не плодит записи. Каждый бан пишется в audit как `security.ip_blocked`.

Автобан есть, но **только по логину**: `AuthController::maybeAutoBlockIp` считает события `auth.login_blocked` за `security.ip_auto_block_window_seconds` (3600) и при достижении `security.ip_auto_block_after_login_blocks` (3) банит IP на `security.ip_auto_block_ttl_seconds` (3600) с причиной `auto:login_blocked`. **Спам в публичный API к автобану не приводит** — реакция на него ручная.

Кого банить, подсказывает `GET /admin/api/logs/anomalies` (вкладка Security): топ IP по подозрительным audit-действиям и по 4xx/429 в `cms_api_logs`.

## Что включено на чистой установке

| Механизм | Состояние | Что реально держит |
|----------|-----------|--------------------|
| `security.rate_limit_anon_write_per_minute` = 20 | ✅ | 20 анонимных записей/мин с IP на весь `/api/*` |
| `security.rate_limit_ip_per_minute` = 120 | ✅ | общий поток с IP |
| `spam.rejectDuplicates` = true | ✅ | точный повтор payload с IP за 10 мин |
| `spam.rateLimitPerMinute` | ❌ (0) | — |
| `spam.honeypotField` | ❌ ('') | — |
| `spam.minSubmitMs` | ❌ (0) | — |
| `spam.requireCaptcha` | ❌ (false) | — |
| `spam.maxLinks` | ❌ (0) | — |
| `spam.blocklist` | ❌ ([]) | — |
| Автобан IP | ⚠️ только для брутфорса логина | не реагирует на спам в `/api/*` |
| Бан IP вручную | ✅ | точечно, после факта |

Итого на дефолтах публичная форма держит поток в 20 запросов в минуту с одного IP и режет точные дубли. Один хост с ротацией payload наливает ~28k записей в сутки легально; пул из 50 IP — 1.4M. Всё, что стоит между этим и базой, — включаемые вручную honeypot/minSubmitMs/captcha.

Админка предупреждает об этом сама: `ResourceSettingsPanel` показывает warning, если `public.create` включён, а `honeypotField`, `requireCaptcha` и `minSubmitMs` пусты.

## Пресеты

**Счётчик кликов (append-only, PII нет).** Важно не терять события:

```json
{ "honeypotField": "", "minSubmitMs": 0, "rateLimitPerMinute": 20,
  "requireCaptcha": false, "maxLinks": 0, "blocklist": [], "rejectDuplicates": false }
```

**Форма обратной связи / лид.** Дубли лучше резать, ссылки в комментарии не нужны:

```json
{ "honeypotField": "website", "minSubmitMs": 2000, "rateLimitPerMinute": 5,
  "requireCaptcha": true, "maxLinks": 0, "blocklist": [], "rejectDuplicates": true }
```

Без captcha (когда ключи Turnstile ставить некуда) — тот же набор с `requireCaptcha: false`, но `minSubmitMs: 3000` и `rateLimitPerMinute: 3`.

**Комментарии / отзывы.** Текст длинный, ссылки — главный маркер спама:

```json
{ "honeypotField": "url", "minSubmitMs": 3000, "rateLimitPerMinute": 3,
  "requireCaptcha": true, "maxLinks": 1, "blocklist": ["bit.ly", "t.me/"], "rejectDuplicates": true }
```

## Проверка

```bash
# honeypot: заполненная ловушка → 422 Spam check failed
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://example.com/api/leads \
  -H 'Content-Type: application/json' \
  -d '{"name":"Ada","website":"http://spam"}'

# minSubmitMs: метка «форму открыли только что» → 422 Submission too fast
curl -s -X POST https://example.com/api/leads \
  -H 'Content-Type: application/json' \
  -H "X-Form-Started-At: $(($(date +%s) * 1000))" \
  -d '{"name":"Ada"}'

# дубль: второй одинаковый payload → 422 Duplicate submission
for i in 1 2; do
  curl -s -X POST https://example.com/api/leads \
    -H 'Content-Type: application/json' -d '{"name":"Ada"}'
done

# глобальный лимит анонимной записи → 429 + Retry-After
for i in $(seq 1 25); do
  curl -s -o /dev/null -w '%{http_code} ' -X POST https://example.com/api/leads \
    -H 'Content-Type: application/json' -d "{\"name\":\"n$i\"}"
done; echo
```

Если 429 не приходит на 21-м запросе — скорее всего запросы идут через прокси и IP схлопнут: проверь `security.trusted_proxies` и то, что видит CMS (`cms_api_logs.ip`).

## Известные ограничения

1. Не-лимитные отказы `SpamGuard` — `422` с текстом причины. Бот по сообщению понимает, какая именно проверка сработала, и подстраивается.
2. Скользящее окно приблизительное (взвешенное предыдущее окно, а не лог запросов): на равномерном потоке погрешность в единицы процентов, на резком всплеске в первую секунду окна оценка чуть строже реальности.
3. Счётчик увеличивается и на отклонённых запросах, поэтому долбёжка в закрытую дверь продлевает блокировку.
4. `minSubmitMs` и `honeypot` полагаются на данные клиента: не прислал — проверка пропущена.
5. Публичные `update` / `delete` не проходят `SpamGuard`.
6. Автобана по спаму нет; `cms_ip_blocks` не поддерживает подсети.
7. Верификация капчи — синхронный внешний HTTP-запрос в основном потоке запроса (до 5 с).
