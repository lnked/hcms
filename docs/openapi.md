# OpenAPI

- Spec: `GET /api/openapi.json`
- UI: `GET /api/docs` — тот же origin, что и `/admin`

В админке: **Documentation** → `/api/docs`.

Генератор заполняет `paths` из published resources и их полей автоматически после publish.

Кастомные API ресурсов попадают в spec по своим `methods`: описываются только разрешённые операции (`get` / `post` / `patch` / `delete`), а для write-методов генерируется отдельная input-схема по проекции полей (`{slug}_{apiSlug}Input`). `security: []` (анонимный доступ) проставляется отдельно для каждой операции — по публичным флагам API с наследованием от ресурса.

Также в spec: **Integrations** — `POST /integrations/email/send` и кастомные `POST /integrations/email/{slug}` (см. [integrations-email.md](./integrations-email.md)).
