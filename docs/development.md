# Development

```bash
composer install
npm install --prefix frontend
npm run dev --prefix frontend   # Vite, proxy на :8080
php -S 127.0.0.1:8080 -t public public/router.php
```

## Docker

```bash
docker compose up --build
# PHP app: http://127.0.0.1:8080
# Adminer: http://127.0.0.1:8081  (MySQL host=`mysql`, user/pass=`hcms`)
# MySQL на хосте: 127.0.0.1:3307
```

После старта открой `/install.php` (или `/admin/install`) и укажи DB host `mysql`, database/user/password `hcms`.

Локальный Vite по-прежнему на хосте:

```bash
npm run dev --prefix frontend
```

Quality gates до коммита:

```bash
composer lint && composer stan
npm run lint && npm run typecheck
```

После фазы:

```bash
composer qa && npm run qa
```

Frontend собирается в `public/admin/` (`base: /admin/`).

## Uptime checks (cron)

UI: `/admin/settings/uptime`. Каждый тик прогоняет **due**-цели (enabled + истёк `intervalSeconds`), пишет checks, открывает/закрывает incidents. Уведомлений нет — только БД + UI.

### Soft cron (по умолчанию)

Due-пробы после `GET /admin/api/health` и при открытии Uptime/dashboard (throttle ~30с, после HTTP-ответа). Внешний cron нужен, если сайт часто idle и health никто не дергает.

В UI (`/admin/settings/uptime`) badge **Running / Stale / Never** — свежесть проб vs `2× interval`, не факт установки crontab.

### CLI cron (предпочтительно на VPS)

```bash
php cms uptime:check
# crontab, каждую минуту:
* * * * * cd /path/to/hcms && php cms uptime:check >/dev/null 2>&1
```

### HTTP cron (shared hosting без CLI)

Нужен **admin** Bearer из `POST /admin/api/auth/login` (`remember: true` → длинный TTL). Токен из Tokens (`type=api`) даёт `403 Admin token required`.

```bash
# один раз — сохранить токен
curl -fsS -X POST https://example.com/admin/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"…","remember":true}'

# crontab, каждую минуту:
* * * * * curl -fsS -X POST -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  https://example.com/admin/api/uptime/run >/dev/null
```

## Admin styles (CSS Modules)

Стили админки — CSS Modules рядом с компонентом: `ComponentName.module.css` (или `.module.scss`). Tailwind не используется.

- Без вложенности селекторов; модификаторы — отдельные классы.
- Отступы/радиусы/цвета только через токены из `frontend/src/index.css`:
  - `--hcms-SPx1` … `--hcms-SPx14` (шаг 4px),
  - `--hcms-CRx1` … `--hcms-CRx5` (шаг 4px),
  - `--hcms-color-*`.
