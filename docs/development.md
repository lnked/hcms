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
