# Resources

Resource = ContentType published as an API endpoint.

## Admin API

```text
GET    /admin/api/resources
POST   /admin/api/resources
GET    /admin/api/resources/:id
PATCH  /admin/api/resources/:id
POST   /admin/api/resources/:id/publish
DELETE /admin/api/resources/:id
```

`POST /admin/api/resources` atomically creates a ContentType + Resource (1:1) in `draft`.

Slug: `^[a-z][a-z0-9_]{0,47}$`. Endpoint defaults to `/api/{slug}` (`/api/{key}` or `/api/v1/{key}`).

Public routes resolve by **endpoint key** first, then by slug. Changing the endpoint in the playground remounts the resource at the new path; custom APIs are nested under that endpoint (`{endpoint}/{apiSlug}`).

Statuses: `draft` | `published` | `archived`. Public API only serves `published` resources with `settings.apiEnabled`.

## Custom APIs

Named endpoints of one resource, managed under resource → **APIs** (`/admin/api/resources/{id}/apis`):

```text
GET    /api/{slug}/{apiSlug}
GET    /api/{slug}/{apiSlug}/{id}
POST   /api/{slug}/{apiSlug}
PATCH  /api/{slug}/{apiSlug}/{id}
DELETE /api/{slug}/{apiSlug}/{id}
```

`methods` picks the verbs that answer: `GET`, `POST`, `PATCH`, `DELETE` (`PUT` normalizes to `PATCH`, an empty list falls back to `GET`). Any other verb on the path returns 405.

The `fields` projection is also the **write mask**:

- a write body may only touch projected fields — anything else is a 422;
- `POST` requires every required writable field to be projected (a `slug` derived through `associatedWith` is exempt), otherwise saving the API config fails;
- `joins` and writes are mutually exclusive — an API with embeds stays read-only.

`settings.public.{read,create,update,delete}` is tri-state: `null` inherits the resource flag, `true` / `false` overrides it for that verb. Tokens are still checked against the **resource** grants, not per API — a token allowed to create on the resource can POST to every custom API of it.

The editor mirrors those rules: write methods are disabled while joins exist, the per-method access select offers *inherit / yes / no*, public writes raise a warning (configure `settings.spam` on the resource first), and saving is blocked while required fields are missing from a `POST` projection.

## Entries table filters (admin)

The filter row renders a control per column type instead of one text box:

| Field type | Control | Operator |
|---|---|---|
| `string`, `text`, `email`, `slug`, `url`, `uuid` | text input | `contains` |
| `integer`, `float` | number input | `eq` |
| `boolean` | tri-state checkbox (any → `1` → `0`) | `eq` |
| `enum` | select of the configured options | `eq` |
| `date` | date picker | `eq` |
| `datetime` | date picker | `startsWith` (day prefix of `YYYY-MM-DD HH:MM:SS`) |
| `relation` manyToOne | select of related entries by label field | `eq` |
| `image`, `file`, oneToMany relations | — | not filterable |

Columns marked `filterable: false` in the schema stay without a control. Selects carry an empty *Any* option, and the relation select loads its options through the public API of the related resource, falling back to the admin API when that one is closed — the same loader the relation form control uses. The mapping above also builds the query params for the admin entries endpoint (`filter[name]`, `filter[name][op]`).
