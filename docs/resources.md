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
