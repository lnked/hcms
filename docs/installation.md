# Installation

## Ideal layout

HTTP **document root** = CMS web folder (`public/` or `public_html/`).  
Код (`src/`, `vendor/`, `.env`, `storage/`) лежит **рядом**, не внутри web root.

```text
/var/www/hcms/           # or /home/user/domain/
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

### Вариант A — залили прямо в `public_html` (типичный хостинг)

1. Распакуй релиз **внутрь** `…/public_html/` (рядом окажутся `install.php`, `src/`, `public/`).
2. Открой `/install.php`.
3. Web root folder должен быть **`public_html`** (installer подставит сам, если cwd уже так называется).
4. На complete installer:
   - содержимое `public/` переносит **в текущий** `public_html/` (`index.php`, `admin/`, …);
   - `src/`, `vendor/`, `storage/`, `.env`, `install.php`, … поднимает **на уровень выше** `public_html/`;
   - **не** создаёт вложенный `public_html/public_html`.
5. Document root хостинга остаётся `…/public_html` — менять не нужно.

Итог:

```text
/home/user/domain/
  src/ vendor/ storage/ .env install.php …
  public_html/          # ← docroot
    index.php admin/ .htaccess
```

### Вариант B — проект рядом, web-папка внутри

1. Залей релиз так, чтобы рядом с `src/` была папка `public`.
2. На шаге Application укажи **Web root folder** = `public_html`.
3. Installer переименует `public/` → `public_html/` и пропишет `CMS_PUBLIC_DIR` в `.env`.
4. В панели хостинга document root = `…/public_html`.

Если document root нельзя сменить и он уже = каталог проекта (не `public_html`), корневой `.htaccess` проксирует в `CMS_PUBLIC_DIR` — лучше всё же выставить docroot на web folder.

## Local skip-download

Если клонировал репозиторий и `src/bootstrap.php` есть на диске, шаг Download не качает GitHub.

```bash
composer install
npm run build --prefix frontend
# optional: CMS_PUBLIC_DIR=public_html npm run build --prefix frontend
php -S 127.0.0.1:8080 -t public public/router.php
```
