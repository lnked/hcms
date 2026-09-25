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

`GET /admin/api/field-types` returns rich descriptors:

```json
[
  {
    "name": "string",
    "label": "string",
    "widget": "text",
    "defaultConfig": { "maxLength": 255 },
    "configSchema": {}
  }
]
```

Built-in types: string, text, richtext, integer, float, boolean, date, datetime, email, url, uuid, json, enum, slug, image, file, relation, blocks.

Schema Builder UI: drag & drop reorder, inline settings, Save schema → `PUT .../fields`.

## Plugin field types (no marketplace)

Extend without editing the core registry constructor:

1. **`extensions/<name>/manifest.php`** — `require_once` your class file and `return [Fully\Qualified\Type::class]`.
2. **`composer.json` → `extra.hcms.field-types`** — list of class-strings (must be autoloadable).

Implement `Cms\Fields\FieldType` (usually extend `AbstractFieldType`):

- `name()`, `label()`, `widget()`
- `sqlType($config)` — SQL fragment or `null` (virtual)
- `usesCustomCast()` + `castValue()` for validation
- `defaultConfig()` / `validateConfig()` / `configSchema()`

Example shipped in-tree: [`extensions/color`](../extensions/color) (`type: color`, hex `#RGB` / `#RRGGBB`).

Admin UI: unknown plugin types use a JSON config editor and a JSON/text value control (no iframe widgets).

## Migrations

Publish / `POST /admin/api/resources/:id/migrate` creates or alters `res_{slug}` from the field schema.

Destructive ops (`drop_field`, `change_type`) require `{ "confirmDestructive": true }`.
