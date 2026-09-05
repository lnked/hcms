# Changelog

## 0.12.0 — 2026-09-05

### Added

- Update check/preview/run with backup, preserve `.env`/uploads, breaking ack gate
- Security headers (nosniff, frame, referrer, CSP in production)

### Fixed

- Allow `frontend/src/features/logs` in git

## 0.11.0 — 2026-09-05

### Added

- Logs page with audit trail and public API request log
- Automatic `cms_api_logs` for `/api/*` (no secrets/query)

## 0.10.0 — 2026-09-05

### Added

- Upload/list/delete media; public `/media/{id}`; FormRenderer image/file picker

## 0.9.0 — 2026-09-05

### Added

- Full OpenAPI 3 spec generated from published resources and fields

### Changed

- Swagger UI deepLinking for `/api/docs#/{tag}`

## 0.8.0 — 2026-09-05

### Added

- API token CRUD UI and `/admin/api/tokens` with per-resource grants
- Public API enforces token grants (admin tokens bypass)

### Changed

- Separate API token rate limit + `X-RateLimit-Limit` on 429

## 0.7.0 — 2026-09-05

### Added

- Entries CRUD API under `/admin/api/resources/{id}/entries`
- Data tab with DataTable, FormRenderer, search/sort/pagination

### Fixed

- Flatten nested PHP query arrays for `filter[field]` params

## 0.6.0 — 2026-09-05

### Added

- Dynamic CRUD for published resources: filter/sort/search/pagination
- `/api/{slug}` and `/api/v1/{slug}` with Bearer or public flags

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
