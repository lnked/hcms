# OpenAPI

- Spec: `GET /api/openapi.json`
- UI: `GET /api/docs` — тот же origin, что и `/admin`

В админке: **Documentation** → `/api/docs`.

Phase 1: пустые `paths`. После publish ресурсов генератор заполнит spec автоматически.

Также в spec: **Integrations** — `POST /integrations/email/send` и кастомные `POST /integrations/email/{slug}` (см. [integrations-email.md](./integrations-email.md)).
