# API

Admin: `/admin/api/*` (Bearer admin token).  
Public: `/api/{slug}` and `/api/v1/{slug}` for **published** resources.  
Docs: `/api/docs`.

## Public CRUD

```http
GET    /api/{slug}?page=1&limit=20&sort=-created_at&search=foo&filter[active]=true
GET    /api/{slug}/{id}
POST   /api/{slug}
PATCH  /api/{slug}/{id}
DELETE /api/{slug}/{id}
```

Auth: `Authorization: Bearer <token>` unless the resource `settings.public.{read|create|update|delete}` allows anonymous access.

Filters: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `startsWith`, `endsWith`, `in`.
