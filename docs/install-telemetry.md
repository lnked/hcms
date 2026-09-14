# Install telemetry

После успешного `install.php?action=complete` инсталлятор может одним fire-and-forget
`POST` сообщить о факте установки. Это **не** счётчик скачиваний с лендинга
([landing-downloads.md](landing-downloads.md)) — только завершённые installs.

```text
Installer::complete → writeLock
                   └─ shutdown → POST https://api.2js.ru/api/installs
                        { version, php, source, os, date }
```

Ошибки сети/API глотаются. Установка никогда не зависит от телеметрии.

## Что уходит

| поле | пример | заметки |
|------|--------|---------|
| `version` | `0.4.2` | из `VERSION` |
| `php` | `8.3` | только major.minor |
| `source` | `wizard` / `api` / `cli` | whitelist; иное → `api` |
| `os` | `Linux` / `Darwin` / … | `PHP_OS_FAMILY` |
| `date` | `2026-09-14 22:00:00` | UTC `Y-m-d H:i:s` |

Без email, URL сайта, IP, hostname. IP видит только принимающий API (rate-limit).

## Opt-out

| способ | как |
|--------|-----|
| Мастер | снять галку «Share anonymous install stats» → `telemetry: false` |
| Headless JSON | `"telemetry": false` в теле `complete` |
| Env | `HCMS_TELEMETRY=0` или `HCMS_NO_TELEMETRY=1` |
| Endpoint | `HCMS_TELEMETRY_URL=off` (или пусто) — пинг не уходит |

По умолчанию включено. Мастер шлёт `telemetrySource: "wizard"`.

## Принимающая сторона

Ресурс `installs` на канонической CMS (`api.2js.ru`):

- `public.create = true`, `public.read = false`
- `spam.rateLimitPerMinute = 10`, `rejectDuplicates = false`
- смотреть строки — в админке `/admin/resources/{id}/entries`

Создать/починить:

```bash
php scripts/setup-installs.php \
  --url=https://api.2js.ru --email=admin@example.com --password=SECRET
```

Либо залить `setup-installs.php` рядом с `index.php` и открыть в браузере. После успеха — удалить файл.

Кастомный endpoint (тесты / self-host):

```bash
export HCMS_TELEMETRY_URL=https://your.cms/api/installs
```

## Проверка

```bash
# ресурс принимает анонимный create
curl -s -X POST https://api.2js.ru/api/installs \
  -H 'Content-Type: application/json' \
  -d '{"version":"0.0.0","php":"8.3","source":"api","os":"Linux","date":"2026-01-01 00:00:00"}'
# → 201

# наружу не читается
curl -s -o /dev/null -w '%{http_code}\n' https://api.2js.ru/api/installs
# → 401
```

Локальный install без пинга:

```bash
HCMS_TELEMETRY=0 php -S 127.0.0.1:8080 -t public public/router.php
```
