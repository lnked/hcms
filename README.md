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
```

1. Положи [`install.php`](install.php) в корень сайта.
2. Открой `https://your-domain/install.php`.
3. Инсталлятор скачает latest zip из [GitHub Releases `lnked/hcms`](https://github.com/lnked/hcms/releases).
4. Заполни БД, URL приложения и администратора.
5. Открой Admin Panel и войди.

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

В админке: **Settings → System** — язык и CORS/origins для public API; **Settings → Integrations** — почта (Resend/Postmark/Mailgun), см. [docs/integrations-email.md](docs/integrations-email.md); **Settings → Webhooks** — исходящие HMAC-хуки на изменения контента, см. [docs/webhooks.md](docs/webhooks.md); у каждого ресурса — **Settings** (`public` CRUD, soft delete, pagination/search/sort/filter).

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
