# Обновление и восстановление

## Как устроено обновление

`Settings → System → Update` ставит задачу в очередь, отдаёт ответ браузеру и продолжает работу в
shutdown-хуке. Порядок шагов (`Cms\System\UpdateService`):

1. **preflight** — версия PHP (web), CLI `php` ≥ 8.3 (или `CMS_PHP_CLI` в `.env`),
   наличие `zip`, права на запись в корень / `src` / `vendor` /
   `database` / web-root, свободное место. Любая проблема — обновление не начинается вообще.
2. **backup** — копия всего, что может быть заменено (включая `vendor`), в
   `storage/backups/update-<дата>`.
3. **download** — зип релиза + проверка `sha256` из `latest.json`.
4. **unpack** — распаковка в `storage/update-work-<версия>/tree`. Живое дерево ещё не тронуто.
5. **verify** — `Cms\System\TreeVerifier` проверяет распакованное дерево: есть ли обязательные
   файлы и разрешаются ли пути автолоадера внутрь дерева.
6. **swap** — каталоги переносятся `rename()`: `src` → `src.old`, `tree/src` → `src`. Каждый шаг
   пишется в `storage/update-journal.json`, поэтому прерванный swap можно откатить.
7. **publish / migrate** — синхронизация админки и миграции БД.

`.env`, `.htaccess` и весь `storage/` не заменяются никогда.

Список изменений на экране обновления берётся из `latest.json` (поле `changelog`, последние 25
релизов), а не из локального `changelog.json`: установленный сайт знает историю только до своей
версии, поэтому иначе он не может показать, что именно приезжает, и гейт breaking-изменений не
срабатывает. Манифест собирает `scripts/latest-json.php` во время релиза.

Если процесс убили посреди swap (PHP-FPM `request_terminate_timeout` не подчиняется
`set_time_limit(0)`), первый же следующий запрос вызовет `UpdateJournal::revertInterrupted()` из
`src/bootstrap.php` и вернёт предыдущее дерево. Пока живой процесс держит `storage/update.lock`
со своим pid, откат не срабатывает.

Классы `Cms\*` грузит собственный PSR-4 автолоадер из `src/autoload.php`, а не Composer: runtime-
зависимостей у проекта нет, поэтому сломанный `vendor/` больше не может уронить сайт.

## Если сайт всё же лежит

`scripts/restore.php` — единственный инструмент, который не зависит ни от `vendor/`, ни от БД, ни
от классов CMS. Работает из CLI и из браузера.

```sh
php scripts/restore.php                      # диагностика (по умолчанию)
php scripts/restore.php fix-autoload         # починить vendor, собранный не на той глубине
php scripts/restore.php backups              # список бэкапов
php scripts/restore.php restore              # откатиться на последний бэкап
php scripts/restore.php restore --backup=update-20260907T221500
php scripts/restore.php reinstall            # перекачать последний релиз и переустановить
php scripts/restore.php reinstall --version=0.45.2
php scripts/restore.php unlock               # снять зависший update.lock
```

`diagnose` возвращает версию PHP, права, состояние автолоадера, `update-status.json`, журнал,
список бэкапов и последние фаталы из `storage/logs/php-fatal.log`.

### Без SSH

Файл можно залить куда угодно внутрь установки (корень, `public/`, `scripts/`) и открыть в
браузере — он сам найдёт корень по `VERSION` + `src/`:

```
https://site/restore.php?action=diagnose&token=<TOKEN>
https://site/restore.php?action=fix-autoload&token=<TOKEN>
https://site/restore.php?action=reinstall&version=latest&token=<TOKEN>
```

Токен — `sha256('hcms-restore:' . APP_SECRET)` из `.env`; напечатать: `php scripts/restore.php token`.
Если CLI нет, положите свой токен в `storage/restore.token` — он имеет приоритет. Без токена
браузерный доступ запрещён (CLI работает всегда).

## CLI PHP старше web (platform_check / migrations)

На shared-хостинге сайт часто крутится на PHP 8.3+, а `php` в shell — 8.2. Тогда шаг
**migrate** падает с `Composer detected issues in your platform` / `require a PHP version ">= 8.3.0"`.

1. Найдите бинарник 8.3+: `ls /usr/local/bin/php*` (или панель хостинга → PHP CLI).
2. В `.env` добавьте: `CMS_PHP_CLI=/usr/local/bin/php8.3` (свой путь).
3. Дождитесь релиза с `PhpCli` resolver (≥ 0.55.4) и повторите Update — preflight проверит CLI до swap.
4. Если swap уже прошёл, а migrate нет:  
   `$CMS_PHP_CLI scripts/apply-pending-migrations.php /path/to/install`

## Диагностика после падения

- `storage/logs/php-fatal.log` — фаталы, включая те, что случились до старта Kernel.
- `storage/logs/php-error.log` — warnings/notices PHP.
- `storage/update-status.json` — на каком шаге всё встало.
- `storage/update-journal.json` — существует только во время swap или после его обрыва.

## Проверка дерева перед деплоем

```sh
php scripts/verify-tree.php            # текущая установка
php scripts/verify-tree.php dist/stage # собранный релиз
```

`scripts/release.sh` вызывает эту проверку сам и не даёт собрать зип, который не бутается.
