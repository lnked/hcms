# API

Admin: `/admin/api/*` (Bearer admin token).  
Public: `/api/{slug}` and `/api/v1/{slug}` for **published** resources.  
Docs: `/api/docs`.

Email / Integrations: see [integrations-email.md](./integrations-email.md).

## Admin tokens

```http
GET    /admin/api/tokens
POST   /admin/api/tokens          # body: { name, expiresAt?, grants[], integrationGrants[] }
GET    /admin/api/tokens/{id}
PUT    /admin/api/tokens/{id}/grants
DELETE /admin/api/tokens/{id}     # revoke
```

Grant: `{ resourceId: null|number, canRead, canCreate, canUpdate, canDelete }`.  
`resourceId: null` = global. Empty grants → deny on private methods.

Integration grant: `{ integrationKey: "email", canUse: true }` — required for `POST /api/integrations/email/*`.

## Admin entries

```http
GET    /admin/api/resources/{id}/entries?page=1&limit=20&sort=-id&search=foo
GET    /admin/api/resources/{id}/entries/{entryId}
POST   /admin/api/resources/{id}/entries
PATCH  /admin/api/resources/{id}/entries/{entryId}
DELETE /admin/api/resources/{id}/entries/{entryId}
```

Resource must be **published** (table exists). Same QueryEngine as the public API.

## Public CRUD

```http
GET    /api/{slug}?page=1&limit=20&sort=-created_at&search=foo&filter[active]=true
GET    /api/{slug}/{id}
POST   /api/{slug}
PATCH  /api/{slug}/{id}
DELETE /api/{slug}/{id}
```

Auth: `Authorization: Bearer <token>` unless the resource `settings.public.{read|create|update|delete}` allows anonymous access.  
API tokens need matching grants; admin tokens bypass grants.

Filters: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `startsWith`, `endsWith`, `in`.  
`search` — splits into words (drops prepositions), matches any word via `LIKE`, ranks by how many words hit; `sort` is secondary.

Rate limits: IP + per-token (admin vs API limits from settings); separate buckets for `/media/{id}` and anonymous writes. 429 includes `Retry-After` and `X-RateLimit-Limit`. Public create can use per-resource `settings.spam` (honeypot, captcha, duplicates, …).

## Custom resource APIs

Named GET-only endpoints with field projection and nested manyToOne embeds:

```http
GET /api/{slug}/{apiSlug}
GET /api/{slug}/{apiSlug}/{id}
```

Admin CRUD: `/admin/api/resources/{id}/apis`.  
`apiSlug` must start with a letter (not numeric-only) so it does not collide with entry ids.

## Email integrations

```http
POST /api/integrations/email/send
POST /api/integrations/email/{slug}
```

Requires Bearer API token with Email `integrationGrant` (or admin token).  
Full setup + examples: [integrations-email.md](./integrations-email.md).
