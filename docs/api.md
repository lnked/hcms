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

Named endpoints with field projection and nested manyToOne embeds:

```http
GET    /api/{slug}/{apiSlug}
GET    /api/{slug}/{apiSlug}/{id}
POST   /api/{slug}/{apiSlug}
PATCH  /api/{slug}/{apiSlug}/{id}
DELETE /api/{slug}/{apiSlug}/{id}
```

Admin CRUD: `/admin/api/resources/{id}/apis`.  
`apiSlug` must start with a letter (not numeric-only) so it does not collide with entry ids.

`methods` per API (`GET`, `POST`, `PATCH`, `DELETE`; `PUT` normalizes to `PATCH`) decides which verbs answer — anything else returns 405. Writes are rejected for APIs with joins, and a `POST` projection must carry every required writable field. A write body may only touch fields inside the projection; anything else is a 422. Per-action public flags live in `settings.public.{read,create,update,delete}` and fall back to the resource flags when `null`. Full rules: [resources.md](./resources.md#custom-apis).

Writes through a custom API dispatch the usual `entry.*` webhooks with an extra `apiSlug` in the payload.

## Routing

Entry ids and custom API slugs share the same path shape, so the segment after the slug is dispatched by its content: digits address an entry (`/api/posts/12`), anything else an apiSlug (`/api/posts/leads`). Every verb is registered on both shapes, and `/api/v1` registers before `/api` so the version prefix is never swallowed by `{apiSlug}`.

Collection verbs reject a trailing id: `POST /api/{slug}/{id}` → 400 `Unexpected id`. Item verbs reject a missing one: `PATCH` / `DELETE` on the collection → 400 `Missing id`.

## Email integrations

```http
POST /api/integrations/email/send
POST /api/integrations/email/{slug}
```

Requires Bearer API token with Email `integrationGrant` (or admin token).  
Full setup + examples: [integrations-email.md](./integrations-email.md).
