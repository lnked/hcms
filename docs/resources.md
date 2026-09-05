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

Slug: `^[a-z][a-z0-9_]{0,47}$`. Endpoint defaults to `/api/{slug}`.

Statuses: `draft` | `published` | `archived`. Public API runtime (Phase 6) only serves `published`.
