# OpenAPI

- Spec: `GET /api/openapi.json`
- UI: `GET /api/docs` — тот же origin, что и `/admin`

В админке: **Documentation** → `/api/docs`.

Генератор заполняет `paths` из published resources и их полей автоматически после publish.

Также в spec: **Integrations** — `POST /integrations/email/send` и кастомные `POST /integrations/email/{slug}` (см. [integrations-email.md](./integrations-email.md)).
