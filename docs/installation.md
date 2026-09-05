# Installation

## Shared hosting

1. Залей `install.php` в document root (или в каталог сайта).
2. Открой `/install.php`.
3. После распаковки корень выглядит так:

```text
install.php
public/
src/
vendor/
database/
storage/
```

Корневой `.htaccess` проксирует запросы в `public/`, кроме `install.php`.

Если хостинг позволяет сменить document root — укажи `public/`.

## Local skip-download

Если клонировал репозиторий и `src/bootstrap.php` есть на диске, шаг Download не качает GitHub — сразу мастер БД.

Нужны `composer install` и `npm run build` один раз.
