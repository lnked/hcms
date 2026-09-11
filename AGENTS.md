# AGENTS.md — HCMS runbook

Инструкция для агентов: развернуть HCMS, залогиниться, создать схему, получить работающий REST.
Всё делается через HTTP API — GUI не нужен. Источник правды по деталям: [`docs/`](docs/).

## 0. Модель данных (обязательно к прочтению)

Три сущности, создаются одним запросом и живут 1:1:

- **ContentType** — форма контента (`cms_content_types`)
- **Field** — поле схемы со стабильным `id` (`cms_fields`)
- **Resource** — публикация типа в `/api/{slug}` (`cms_resources`)

Схема полей — source of truth для SQL-таблицы `res_{slug}`, валидации, REST, OpenAPI и админки.
Таблица создаётся/изменяется только миграцией; правка `cms_fields` без миграции = схема есть, колонок нет.

Статусы ресурса: `draft` → `published` → `archived`. Публичный API отдаёт **только** `published` + `settings.apiEnabled`.

Цикл добавления схемы всегда один:

```text
POST /admin/api/resources          → создать (draft)
PUT  /admin/api/resources/{id}/fields  → залить схему целиком
POST /admin/api/resources/{id}/publish → миграция + published
GET  /api/{slug}                   → проверить
```

---

## 1. Развёртывание

### 1.1 Требования

PHP 8.3+, MySQL/MariaDB, расширения `pdo_mysql`, `json`, `mbstring`, `zip`, `curl`.
Для сборки админки — Node.js 20+, Composer.

### 1.2 Локально из репозитория (основной путь для агента)

```bash
composer install
npm install --prefix frontend
npm run build                 # собирает админку в public/admin/
php -S 127.0.0.1:8080 -t public public/router.php
```

- Админка: `http://127.0.0.1:8080/admin`
- Swagger: `http://127.0.0.1:8080/api/docs`
- Инсталлятор: `http://127.0.0.1:8080/install.php`

`npm run build` нужен только для UI. Если задача чисто API — можно пропустить.

### 1.3 Docker

```bash
docker compose up --build
# app:     http://127.0.0.1:8080
# adminer: http://127.0.0.1:8081
# mysql:   127.0.0.1:3307 (изнутри контейнера host=mysql)
```

БД/юзер/пароль в compose — `hcms`. При установке DB host указывать `mysql`, не `127.0.0.1`.

### 1.4 Установка без браузера (headless, curl)

`install.php` — это и веб-мастер, и JSON API. Агент ставит CMS так:

```bash
# 1. БД инсталлятор НЕ создаёт — создать заранее
mysql -h 127.0.0.1 -uroot -e \
  "CREATE DATABASE IF NOT EXISTS hcms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. поднять сервер (router.php отдаёт /install.php)
php -S 127.0.0.1:8080 -t public public/router.php &

BASE=http://127.0.0.1:8080

# 3. проверить окружение: requirements, srcReady, installed
curl -s "$BASE/install.php?action=status"

# 4. проверить коннект к БД
curl -s -X POST "$BASE/install.php?action=test-connection" \
  -H 'Content-Type: application/json' \
  -d '{"database":{"host":"127.0.0.1","port":3306,"name":"hcms","user":"root","password":"","charset":"utf8mb4"}}'

# 5. установить
curl -s -X POST "$BASE/install.php?action=complete" \
  -H 'Content-Type: application/json' \
  -d '{
    "database":{"host":"127.0.0.1","port":3306,"name":"hcms","user":"root","password":"","charset":"utf8mb4"},
    "application":{"name":"HCMS","url":"http://127.0.0.1:8080","timezone":"UTC","language":"en","publicDir":"public"},
    "administrator":{"name":"Admin","email":"admin@example.com","password":"Sup3rSecret!","passwordConfirm":"Sup3rSecret!"}
  }'
# → {"ok":true,"adminUrl":"/admin"}
```

Что делает `complete`: пишет `.env` (в т.ч. генерирует `APP_SECRET`), **дропает существующие `cms_*` таблицы**,
прогоняет `database/migrations/*.sql`, создаёт владельца с ролью `owner`, засеивает `cms_settings`,
ставит `storage/installed.lock`. После этого любой `action` кроме `status` отвечает `403 INSTALLED`.

Пароль админа — минимум 8 символов, `password === passwordConfirm`, иначе `422` с `error.fields`.

Другие `action`: `latest` (манифест релиза), `download` (скачать zip с GitHub; пропускается, если `src/bootstrap.php` уже на диске).

> `php install.php` без аргументов поднимает мастер на встроенном сервере и печатает URL с одноразовым ключом
> (`CMS_INSTALL_KEY`); без ключа — 403. Для headless-сценария выше ключ не нужен, т.к. сервер поднимает агент сам.

### 1.5 Продакшн из релиза

```bash
curl -fsSL -o install.php https://github.com/lnked/hcms/releases/latest/download/install.php
php install.php            # или положить install.php в docroot и открыть /install.php
```

Идеальная раскладка: docroot = web-папка (`public` / `public_html`), а `src/`, `vendor/`, `.env`, `storage/` — **рядом**, выше docroot.
Детали для shared-хостинга: [`docs/installation.md`](docs/installation.md).

Сборка релизного дерева:

```bash
composer install --no-dev --optimize-autoloader
npm run build
php scripts/verify-tree.php   # проверка, что дерево способно загрузиться
```

### 1.6 CLI

```bash
php cms status                 # version / installed / publicDir / кол-во ресурсов
php cms migrate                # миграция всех published ресурсов
php cms migrate --resource=1 [--confirm-destructive]
php cms cache:clear            # сбросить MetadataCache
php cms uptime:check           # HTTP-пробы due uptime-целей (cron)
```

Uptime без shell: `POST /admin/api/uptime/run` с **admin** Bearer (login / `remember: true`). API-токен из Tokens → `403 Admin token required`. Soft cron и примеры crontab: [`docs/development.md`](docs/development.md#uptime-checks-cron).

---

## 2. Аутентификация

Единственный механизм — `Authorization: Bearer <token>`.

```bash
TOKEN=$(curl -s -X POST "$BASE/admin/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"Sup3rSecret!"}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["token"])')

curl -s "$BASE/admin/api/auth/me" -H "Authorization: Bearer $TOKEN"
```

- TTL admin-токена — `auth.admin_token_ttl_hours` (12ч). С `"remember": true` — `auth.remember_token_ttl_hours` (720ч).
- 5 неудачных логинов с IP/email за 15 минут → `429` + `Retry-After`.
- Если у юзера включён TOTP — в теле нужен `totpCode`, иначе `401 TOTP_REQUIRED`.
- На Apache/CGI заголовок `Authorization` часто срезается: за это отвечает `HTTP_AUTHORIZATION` в `.htaccess` web-root. Если Bearer «не видно» после деплоя — смотреть туда.

Подробности: [`docs/authentication.md`](docs/authentication.md).

---

## 3. Добавление схемы: полный цикл

### 3.1 Создать ресурс

```bash
curl -s -X POST "$BASE/admin/api/resources" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "label": "Articles",
    "name": "articles",
    "slug": "articles",
    "description": "Blog posts",
    "settings": {
      "apiEnabled": true,
      "public": { "read": true, "create": false, "update": false, "delete": false }
    }
  }'
# → 201 { "data": { "id": 1, "slug": "articles", "endpoint": "/api/articles", "status": "draft", ... } }
```

- `label` — единственное обязательное поле. `slug` выводится из `name`/`label`, если не задан.
- Slug: `^[a-z][a-z0-9_]{0,47}$`. Дефис нельзя, начинается с буквы.
- `endpoint` по умолчанию `/api/{slug}`; валидная форма — `^/api(/v1)?/[a-z][a-z0-9_-]{0,62}$`.
- Ресурс создаётся в `draft`; таблицы ещё нет.

### 3.2 Залить схему

`PUT` заменяет схему **целиком** (чего нет в списке — удаляется). `POST` добавляет одно поле.

```bash
curl -s -X PUT "$BASE/admin/api/resources/1/fields" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "fields": [
      { "name": "title", "type": "string", "label": "Title",
        "required": true, "nullable": false, "searchable": true, "sortable": true,
        "config": { "maxLength": 200 } },
      { "name": "slug", "type": "string", "label": "Slug",
        "required": true, "nullable": false, "unique": true, "indexed": true,
        "config": { "maxLength": 200 } },
      { "name": "body", "type": "richtext", "label": "Body", "required": true, "nullable": false },
      { "name": "views", "type": "integer", "label": "Views",
        "default": 0, "nullable": false, "filterable": true, "sortable": true, "indexed": true },
      { "name": "status", "type": "enum", "label": "Status",
        "required": true, "nullable": false, "filterable": true,
        "config": { "options": ["draft", "review", "published"] } },
      { "name": "cover", "type": "image", "label": "Cover", "nullable": true },
      { "name": "author_id", "type": "relation", "label": "Author",
        "nullable": true, "filterable": true, "indexed": true,
        "config": { "cardinality": "manyToOne", "relatedSlug": "authors", "labelField": "name" } }
    ]
  }'
```

### 3.3 Опубликовать (публикация сама запускает миграцию)

```bash
curl -s -X POST "$BASE/admin/api/resources/1/publish" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"confirmDestructive": false}'
```

`publish` вызывает `MigrationService::applyForResource` (создаёт/альтерит `res_articles`), затем ставит `status = published`.
Отдельный вызов миграции нужен только когда схема меняется у уже опубликованного ресурса:

```bash
curl -s -X POST "$BASE/admin/api/resources/1/migrate" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"confirmDestructive": true}'
```

Деструктивные операции (`drop_field`, `change_type`) **требуют** `confirmDestructive: true`, иначе `422`.
Без флага миграция падает целиком, а не частично.

### 3.4 Проверить

```bash
curl -s "$BASE/admin/api/resources/1/fields" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/api/articles?limit=1"          # public read, если settings.public.read = true
curl -s "$BASE/api/openapi.json" | head -40   # схема попала в OpenAPI
```

### 3.5 Порядок при связях

Ресурс со `relation → relatedSlug` создавать **после** целевого ресурса:

1. `authors` (создать → поля → publish)
2. `articles` с `author_id` (`manyToOne`, `relatedSlug: "authors"`)
3. при желании — добавить в `authors` обратное поле `articles` (`oneToMany`, `foreignKey: "author_id"`, `writable: false`)

`oneToMany` — вычисляемое поле: колонки в БД не создаёт, всегда `"writable": false`.

Готовый рабочий пример всего цикла (4 ресурса, все типы полей, сотни записей):
[`scripts/seed-demo.php`](scripts/seed-demo.php) — читать его как эталон payload'ов.

```bash
php scripts/seed-demo.php --url=http://127.0.0.1:8080 \
  --email=admin@example.com --password=Sup3rSecret! --min=20 --max=40 --force
```

---

## 4. Справочник полей

### 4.1 Типы

`string`, `text`, `richtext`, `integer`, `float`, `boolean`, `date`, `datetime`, `email`, `url`, `uuid`,
`json`, `enum`, `slug`, `image`, `file`, `relation`.

Актуальный список всегда можно взять из API: `GET /admin/api/field-types`.

### 4.2 Флаги (все опциональны)

| Ключ | По умолчанию | Смысл |
|---|---|---|
| `required` | `false` | payload обязан содержать значение (валидация запроса) |
| `nullable` | **`true`** | колонка допускает `NULL` (SQL) |
| `unique` | `false` | UNIQUE-индекс |
| `indexed` | `false` | обычный индекс; ставить вместе с `filterable`/`unique` |
| `default` | `null` | DEFAULT в SQL |
| `searchable` | `false` | участвует в `?search=` |
| `sortable` | `false` | разрешён в `?sort=` |
| `filterable` | `false` | разрешён в `?filter[...]` и рисует контрол в админке |
| `readable` / `writable` | `true` | проекция на чтение / запись |
| `readonly`, `hidden` | `false` | поведение в админке |
| `label`, `description` | `name` / `null` | подписи в UI |
| `sortOrder` | индекс в массиве | порядок полей |
| `config` | дефолт типа | см. ниже |

**Грабля:** `nullable` по умолчанию `true`, поэтому `required: true` без `nullable: false` даёт
обязательное в API, но NULL-able в БД. Для обязательных полей ставить оба флага явно.

### 4.3 `config` по типам

| Тип | Ключи `config` |
|---|---|
| `string` | `maxLength` (255) |
| `slug` | `associatedWith` (имя исходного поля), `maxLength` (255) |
| `enum` | `options: string[]` — **обязателен и непустой** |
| `date`, `datetime` | `format` |
| `image`, `file` | `multiple: bool`, `formats: string[]` (accept), `encodeFormat: webp\|jpeg\|png\|null` (storage, только image), `sizes` (только image) |
| `relation` | `cardinality: manyToOne\|oneToMany`, `relatedSlug`, `labelField` (`id`), `foreignKey` (обязателен для `oneToMany`) |
| остальные | нет |

Имя поля валидируется тем же правилом, что и slug: `^[a-z][a-z0-9_]{0,47}$`, дубликаты в одном `PUT` → `422`.

---

## 5. Настройки ресурса (`settings`)

`PATCH /admin/api/resources/{id}` мержит `settings` глубоко (списки заменяются целиком, не по индексам).

```json
{
  "apiEnabled": true,
  "public":  { "read": false, "create": false, "update": false, "delete": false },
  "pagination": true, "search": true, "sorting": true, "filtering": true,
  "list": { "columns": [] },
  "deleteStrategy": "hard",
  "softDelete": false,
  "spam": {
    "honeypotField": "", "minSubmitMs": 0, "rateLimitPerMinute": 0,
    "requireCaptcha": false, "maxLinks": 0, "blocklist": [], "rejectDuplicates": true
  }
}
```

- `public.*` — анонимный доступ без токена. **Перед включением `public.create` настроить `spam`**: [`docs/anti-spam.md`](docs/anti-spam.md).
- `deleteStrategy: "soft"` включает `softDelete` (и наоборот).
- Именованные эндпоинты с проекцией полей — `/admin/api/resources/{id}/apis`, правила в [`docs/resources.md#custom-apis`](docs/resources.md#custom-apis).

---

## 6. Перенос схем пакетами (быстрый путь)

Вместо ручного `create → fields → publish` можно переносить ресурс целиком.

```bash
# экспорт схемы (+ данные и медиа при includeData=1)
curl -s "$BASE/admin/api/resources/1/package/export?includeData=1" \
  -H "Authorization: Bearer $TOKEN" -o articles.package.json

# импорт (slug опционален — переименовать при конфликте)
curl -s -X POST "$BASE/admin/api/resources/package/import" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "$(python3 -c 'import json;print(json.dumps({"package":json.load(open("articles.package.json")),"slug":"articles_copy"}))')"
```

Формат: `{ kind: "cms.resource.package", formatVersion: 1, contentType, resource, fields, apis, entries?, media? }`.
Тело можно прислать и как сам объект пакета, и multipart-файлом в поле `file`.

Ограничения: ≤ 50 MB, ≤ 5000 записей, `includeData=1` требует `published` ресурс.
Занятый slug не ломает импорт — сервис подберёт свободный и вернёт это в `warnings` + `slugResolved`.

---

## 7. Данные

### 7.1 Admin CRUD (ресурс должен быть `published`)

```http
GET    /admin/api/resources/{id}/entries?page=1&limit=20&sort=-id&search=foo&filter[status]=draft&filter[views][gte]=10
GET    /admin/api/resources/{id}/entries/{entryId}
POST   /admin/api/resources/{id}/entries
PATCH  /admin/api/resources/{id}/entries/{entryId}
DELETE /admin/api/resources/{id}/entries/{entryId}
POST   /admin/api/resources/{id}/entries/bulk-delete
GET    /admin/api/resources/{id}/entries/{entryId}/revisions
POST   /admin/api/resources/{id}/entries/{entryId}/revisions/{revId}/restore
```

### 7.2 Массовый импорт/экспорт записей

```bash
curl -s "$BASE/admin/api/resources/1/entries/export?format=csv" -H "Authorization: Bearer $TOKEN"

curl -s -X POST "$BASE/admin/api/resources/1/entries/import" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"format":"json","content":"[{\"title\":\"Hello\",\"slug\":\"hello\"}]"}'
# → { created, failed, errors: [{row, message}] }
```

`format` — `json` или `csv`; ≤ 5 MB, ≤ 5000 строк. Импорт всегда создаёт новые записи (upsert'а нет).

### 7.3 Медиа

```bash
curl -s -X POST "$BASE/admin/api/media" -H "Authorization: Bearer $TOKEN" -F 'file=@cover.png'
# → { data: { id: 12, ... } }   ← id кладётся в поля image/file
```

Публичная отдача — `GET /media/{id}` или pretty `GET /media/{id}/{filename}` (rate-limit на PHP-hit). После первого запроса безопасные типы прогреваются в `{publicDir}/media/` и дальше отдаются статикой. В JSON: `url` + `fullUrl`.

### 7.4 Публичный API

```http
GET    /api/{slug}?page=1&limit=20&sort=-created_at&search=foo
GET    /api/{slug}/{id}
POST   /api/{slug}
PATCH  /api/{slug}/{id}
DELETE /api/{slug}/{id}
```

Фильтры — `filter[field]=value` (оператор `eq`) или `filter[field][op]=value`:
`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `startsWith`, `endsWith`, `in` (значения через запятую).

```text
?filter[status]=published&filter[views][gte]=100&filter[status][in]=draft,review
```

Фильтровать можно только поля с `filterable: true` — иначе `422 Field not filterable`.
`/api` и `/api/v1` — один и тот же API. Полностью: [`docs/api.md`](docs/api.md).

### 7.5 Токены для клиентских приложений

```bash
curl -s -X POST "$BASE/admin/api/tokens" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "name": "frontend",
    "grants": [ { "resourceId": 1, "canRead": true, "canCreate": false, "canUpdate": false, "canDelete": false } ],
    "allowedOrigins": ["https://app.example.com"],
    "requireOrigin": false,
    "allowedIps": []
  }'
```

`resourceId: null` = глобальный грант. Пустые `grants` = запрет на всё приватное.
Admin-токен обходит гранты. Токен возвращается один раз — сохранить сразу.

---

## 8. Проверка результата (обязательный чеклист)

```bash
php cms status                                   # installed: yes, resources: N
curl -s "$BASE/admin/api/health"                 # без авторизации, вне rate-limit
curl -s "$BASE/admin/api/resources" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/api/{slug}?limit=1"               # публичное чтение
curl -s "$BASE/api/openapi.json" | grep {slug}   # ресурс в OpenAPI
```

Изменил PHP-код — прогнать гейты:

```bash
composer qa      # php-cs-fixer + phpstan L7 + deptrac + phpunit
npm run qa       # eslint + prettier + tsc + knip + vitest coverage
php scripts/verify-tree.php
```

---

## 9. Грабли (частые причины «не работает»)

| Симптом | Причина |
|---|---|
| `404` на `/api/{slug}` | ресурс не `published`, либо `settings.apiEnabled = false`, либо не совпал endpoint key |
| `401` на публичном GET | `settings.public.read = false` — нужен Bearer-токен с грантом |
| `422 VALIDATION_ERROR` на `PUT .../fields` | невалидное имя поля, дубликат, неизвестный тип, `enum` без `options`, `oneToMany` без `foreignKey` |
| миграция падает без изменений | деструктивная операция без `confirmDestructive: true` |
| поле есть в схеме, колонки в БД нет | не вызван `publish` / `migrate` после правки схемы |
| `429` при массовом заполнении | rate-limit (120 req/min на IP). Уважать `Retry-After`, ставить паузы (`seed-demo.php` — 220 мс) |
| `403 INSTALLED` от `install.php` | `storage/installed.lock` уже есть; переустановка — только после удаления lock (снесёт `cms_*` таблицы) |
| Bearer «не доходит» на хостинге | срезан `Authorization`; нужен `HTTP_AUTHORIZATION` rewrite в `.htaccess` web-root |
| админка отдаёт 404 / пустоту | не выполнен `npm run build` (`public/admin/`) |
| stale-схема в ответах API | `php cms cache:clear` (MetadataCache) |

Сломанная установка после апдейта: `php scripts/restore.php` (diagnose, `fix-autoload`, откат на бэкап, переустановка релиза) — [`docs/recovery.md`](docs/recovery.md).

---

## 10. Правила для агента

- **Секреты только в `.env`**, никогда в git, в zip и в примерах команд. Пароли не логировать.
- Не трогать `install.php?action=complete` на живой инсталляции: он дропает `cms_*`.
- Перед `PUT .../fields` на существующем ресурсе — сначала `GET .../fields`: `PUT` затирает всё, чего нет в payload.
- Публичную запись (`public.create/update/delete`) не включать без `settings.spam`.
- Релиз/публикация — только после зелёного CI: см. `.cursor/rules/pre-release-ci-loop.mdc`. Порядок: код → push → ждать CI → `npm run release`.
- Не удалять и не перезаписывать незакоммиченные правки пользователя.
- Проверять результат HTTP-вызовов по факту (`GET`), а не по коду ответа предыдущего шага.

## 11. Карта документации

| Файл | О чём |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | ContentType / Field / Resource, оси версионирования |
| [`docs/installation.md`](docs/installation.md) | раскладка каталогов, shared-хостинг, `public_html` |
| [`docs/deployment.md`](docs/deployment.md) | состав релиза, `latest.json` |
| [`docs/development.md`](docs/development.md) | локальная разработка, Docker, quality gates |
| [`docs/schema.md`](docs/schema.md) | эндпоинты схемы, миграции |
| [`docs/resources.md`](docs/resources.md) | ресурсы, custom APIs, фильтры админки |
| [`docs/api.md`](docs/api.md) | admin/public API, токены, ограничения |
| [`docs/authentication.md`](docs/authentication.md) | Bearer, social login, TOTP, rate limits |
| [`docs/permissions.md`](docs/permissions.md) | роли и RBAC |
| [`docs/anti-spam.md`](docs/anti-spam.md) | защита анонимной записи |
| [`docs/webhooks.md`](docs/webhooks.md) | исходящие HMAC-хуки |
| [`docs/hooks.md`](docs/hooks.md) | sync request hooks + inbound endpoints |
| [`docs/integrations-email.md`](docs/integrations-email.md) | Resend / Postmark / Mailgun |
| [`docs/openapi.md`](docs/openapi.md) | генерация OpenAPI |
| [`docs/recovery.md`](docs/recovery.md) | обновление и восстановление |
| [`examples/react`](examples/react) | консьюмер публичного API |
