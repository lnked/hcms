# HCMS

Готовая админка для вашего SPA: визуально описываешь схему — получаешь REST, OpenAPI и админский CRUD.

[2js.ru](https://2js.ru) · [![CI](https://github.com/lnked/hcms/actions/workflows/ci.yml/badge.svg)](https://github.com/lnked/hcms/actions/workflows/ci.yml)

Администратор управляет схемой и данными, а не пишет backend-код.

## Requirements

- PHP 8.3+
- MySQL / MariaDB
- PDO MySQL, JSON, mbstring, zip, curl (или `allow_url_fopen`)
- Для разработки: Node.js 20+, Composer

## Installation (production)

```bash
curl -fsSL -o install.php https://github.com/lnked/hcms/releases/latest/download/install.php
php install.php
```

`php install.php` поднимает мастер на встроенном сервере PHP (`0.0.0.0:8080`, первый свободный порт) и печатает ссылку
с одноразовым ключом — без ключа мастер отвечает 403, поэтому открытый порт не значит открытую установку. Хост и порт
переопределяются: `php install.php --host=127.0.0.1 --port=9000`.

Без SSH — положи [`install.php`](install.php) в корень сайта и открой `https://your-domain/install.php`.

Дальше в обоих случаях:

1. Инсталлятор скачает latest zip из [GitHub Releases `lnked/hcms`](https://github.com/lnked/hcms/releases) и сверит sha256.
2. Заполни БД, URL приложения и администратора.
3. Открой Admin Panel и войди.

После установки `install.php` отвечает отказом.

## Development

```bash
composer install
npm install --prefix frontend
npm run build
php -S 127.0.0.1:8080 -t public public/router.php
```

Админка: `http://127.0.0.1:8080/admin`  
Swagger: `http://127.0.0.1:8080/api/docs`  
Installer: `http://127.0.0.1:8080/install.php`

Если `src/` уже на диске, download в installer пропускается.

CLI:

```bash
php cms status
php cms migrate                # все published
php cms migrate --resource=1
php cms cache:clear
```

## Production build

```bash
composer install --no-dev --optimize-autoloader
npm run build
```

В релизный zip входят `vendor/`, `public/admin/`, `src/`, `database/`.

## Configuration

Секреты только в `.env` (не в git). Пример: [`.env.example`](.env.example).

## API usage

Все запросы:

```http
Authorization: Bearer <token>
```

- Admin API: `/admin/api/*`
- Public API: `/api/*` и `/api/v1/*` (published resources с `settings.apiEnabled`)
- OpenAPI: `/api/openapi.json`
- Swagger UI: `/api/docs`
- React consumer demo: [`examples/react`](examples/react) (`npm run dev` после seed)

В админке: **Settings → System** — язык и CORS/origins для public API; **Settings → Integrations** — почта (Resend/Postmark/Mailgun), см. [docs/integrations-email.md](docs/integrations-email.md); **Settings → Webhooks** — исходящие HMAC-хуки на изменения контента, см. [docs/webhooks.md](docs/webhooks.md); **Feature flags** — remote config / A/B (см. ниже); **Переводы** — i18n-ключи для клиентов (см. ниже); у каждого ресурса — **Settings** (`public` CRUD, soft delete, pagination/search/sort/filter, анти-спам для анонимной записи — см. [docs/anti-spam.md](docs/anti-spam.md)) и **APIs** — именованные эндпоинты с проекцией полей, своим набором методов (`GET`/`POST`/`PATCH`/`DELETE`) и правами по каждому из них, см. [docs/resources.md](docs/resources.md#custom-apis).

## Feature flags

Remote-конфиг для SPA/мобилок: флаги живут в `cms_feature_flags`, правятся в админке (**Feature flags**), читаются публичным `GET` без схемы ресурсов.

Типы: `boolean`, `integer`, `string`, `object`. В публичный ответ попадают только **enabled**. Путь по умолчанию `/api/features` (настраивается в settings: `enabled`, `path`, `requireToken`).

Admin:

```http
GET/POST          /admin/api/feature-flags
GET/PATCH/DELETE  /admin/api/feature-flags/{id}
GET/PUT           /admin/api/feature-flags/settings
```

Public:

```http
GET /api/features
GET /api/features?keys=enabledNews,intMaxAmount
GET /api/features?keys=newCheckout&subject=user-42
```

Ответ: `{ "data": { "enabledNews": true, ... } }`. Есть `ETag` / `If-None-Match`.

### A/B rollout (только boolean)

Поля флага: `abTest` + `rolloutPercent` (0–100). При `abTest: true` публичное значение **не** берётся из `value`, а считается sticky-бакетом:

```text
crc32(flagKey + "\0" + subject) % 100 < rolloutPercent  →  true
```

Subject (макс. 128 символов): `?subject=` / `?sid=` или заголовок `X-Flag-Subject`. Один и тот же subject всегда попадает в один бакет. Без subject — случайный бакет на каждый запрос (не sticky). Ответы с A/B: `Cache-Control: private, no-store`, `Vary: X-Flag-Subject`.

Примеры:

```bash
# обычный флаг
curl -s -X POST "$BASE/admin/api/feature-flags" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"News banner","key":"enabledNews","type":"boolean","value":true,"enabled":true}'

# A/B: 30% subject'ов получают true
curl -s -X POST "$BASE/admin/api/feature-flags" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "name":"New checkout","key":"newCheckout","type":"boolean","value":false,
    "enabled":true,"abTest":true,"rolloutPercent":30
  }'

# клиент: sticky по user id
curl -s "$BASE/api/features?keys=newCheckout&subject=user-42"
# или
curl -s "$BASE/api/features?keys=newCheckout" -H 'X-Flag-Subject: user-42'
```

В SPA — один раз при старте (или на смене юзера) запросить карту флагов с `subject = userId` / анонимный id из localStorage и ветвить UI по `data.newCheckout`.

Подробности API: [docs/api.md](docs/api.md#feature-flags).

## Переводы (Translates)

i18n-ключи для клиентов: локали + dotted-ключи со строками по языкам. Управление в админке (**Переводы**). Публичный `GET` отдаёт плоскую карту `key → string` для одной локали. Пустые значения — fallback на default locale, затем `""`.

Путь по умолчанию `/api/translates` (`enabled`, `path`, `requireToken`).

Admin:

```http
GET/POST/PATCH/DELETE /admin/api/locales[/{code}]
PUT    /admin/api/locales/{code}/default
GET/POST/PATCH/DELETE /admin/api/translations[/{id}]
GET/PUT /admin/api/translations/settings
GET    /admin/api/translations/export
POST   /admin/api/translations/import
```

Public:

```http
GET /api/translates?locale=en
GET /api/translates?locale=ru&keys=amount.title,amount.description
```

```bash
curl -s -X POST "$BASE/admin/api/translations" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"key":"amount.title","values":{"en":"Amount","ru":"Сумма"}}'

curl -s "$BASE/api/translates?locale=ru&keys=amount.title"
# → { "data": { "amount.title": "Сумма" } }
```

Подробности: [docs/api.md](docs/api.md#translates). Админ-доки: `/admin/docs/translates`.

## Quality

```bash
composer qa
npm run qa
php scripts/verify-tree.php   # установка/релиз способны загрузиться
```

## Обновление и восстановление

Обновление ставится из **Settings → System**: релиз распаковывается рядом, проверяется и только
потом атомарно подменяет каталоги; прерванный swap откатывается сам. Если сайт всё же лёг —
`php scripts/restore.php` (диагностика, `fix-autoload`, откат на бэкап, переустановка релиза).
Подробности: [docs/recovery.md](docs/recovery.md).

История релизов: [`changelog.json`](changelog.json).
