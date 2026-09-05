# Changelog

## 0.5.0 — 2026-09-05

### Added

- Migration engine: `res_{slug}` tables, schema diff, publish applies SQL
- `POST /admin/api/resources/:id/migrate`

## 0.4.0 — 2026-09-05

### Added

- Field types registry and resource schema CRUD
- Visual Schema Builder with drag & drop

## 0.3.0 — 2026-09-05

### Added

- CRUD ContentType + Resource (атомарное создание draft)
- Список, создание, publish/delete ресурсов в админке

## 0.2.0 — 2026-09-05

### Added

- TTL admin-токена, brute-force login и rate limit с 429
- Audit login/logout/fail в `cms_audit_logs`

## 0.1.0 — 2026-09-05

### Added

- Однофайловый `install.php` с загрузкой GitHub Releases
- Админка на shadcn/ui, login по Bearer-токену
- Версия продукта, changelog и What's new
- Заготовка Swagger UI на `/api/docs`
