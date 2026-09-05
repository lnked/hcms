# Development

```bash
composer install
npm install --prefix frontend
npm run dev --prefix frontend   # Vite, proxy на :8080
php -S 127.0.0.1:8080 -t public public/router.php
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
