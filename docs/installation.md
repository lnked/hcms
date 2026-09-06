# Installation

## Ideal layout

HTTP **document root** = CMS web folder (`public/` or `public_html/`).  
Код (`src/`, `vendor/`, `.env`, `storage/`) лежит **рядом**, не внутри web root.

```text
/var/www/hcms/           # or /home/user/
  install.php
  src/
  vendor/
  storage/
  .env
  public_html/           # ← document root (CMS_PUBLIC_DIR)
    index.php
    admin/
    .htaccess
```

Admin URL: **`/admin`** (не `/public_html/admin`).

## Shared hosting (`public_html`)

1. Залей релиз / клон так, чтобы рядом с `src/` была папка `public` (из zip).
2. Открой `/install.php`.
3. На шаге Application укажи **Web root folder** = `public_html`.
4. Installer переименует `public/` → `public_html/` и пропишет `CMS_PUBLIC_DIR` в `.env`.
5. В панели хостинга document root = `.../public_html` (каталог CMS web root).

Если document root нельзя сменить и он уже = каталог проекта, корневой `.htaccess` проксирует в `CMS_PUBLIC_DIR` — лучше всё же выставить docroot на web folder.

## Local skip-download

Если клонировал репозиторий и `src/bootstrap.php` есть на диске, шаг Download не качает GitHub.

```bash
composer install
npm run build --prefix frontend
# optional: CMS_PUBLIC_DIR=public_html npm run build --prefix frontend
php -S 127.0.0.1:8080 -t public public/router.php
```
