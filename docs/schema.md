# Schema

Fields are first-class entities in `cms_fields` with stable numeric `id`.

## Admin API

```text
GET  /admin/api/field-types
GET  /admin/api/resources/:id/fields
PUT  /admin/api/resources/:id/fields   # replace full schema
POST /admin/api/resources/:id/fields
PATCH /admin/api/fields/:id
DELETE /admin/api/fields/:id
```

MVP types: string, text, integer, float, boolean, date, datetime, email, url, uuid, json, enum, image, file.

Schema Builder UI: drag & drop reorder, inline settings, Save schema → `PUT .../fields`.
