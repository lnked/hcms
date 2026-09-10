# API

Admin: `/admin/api/*` (Bearer admin token).  
Public: `/api/{slug}` and `/api/v1/{slug}` for **published** resources.  
Docs: `/api/docs`.

Email / Integrations: see [integrations-email.md](./integrations-email.md).

## Admin tokens

```http
GET    /admin/api/tokens
POST   /admin/api/tokens          # body: { name, expiresAt?, grants[], integrationGrants[],
                                  #         allowedOrigins[], requireOrigin, allowedIps[] }
GET    /admin/api/tokens/{id}
PUT    /admin/api/tokens/{id}/grants
PATCH  /admin/api/tokens/{id}     # partial; policy keys fall back to stored values
DELETE /admin/api/tokens/{id}     # revoke
```

Grant: `{ resourceId: null|number, canRead, canCreate, canUpdate, canDelete }`.  
`resourceId: null` = global. Empty grants → deny on private methods.

Integration grant: `{ integrationKey: "email", canUse: true }` — required for `POST /api/integrations/email/*`.

### Per-token restrictions

| Field | Empty / false | Set |
| --- | --- | --- |
| `allowedOrigins` | any origin | `Origin` must match (`https://app.example.com`, `example.com`, `*.example.com`) |
| `requireOrigin` | requests without `Origin` pass | requests without `Origin` are rejected (blocks curl / server-side use) |
| `allowedIps` | any IP | client IP must match an entry (`203.0.113.7`, `10.0.0.0/8`, `2001:db8::/32`) |

Checked after authentication, so browser preflight (`OPTIONS`, no `Authorization`) is still governed by the
global `api.access` policy and CORS headers are present on the `403`. Rejections land in the audit log as
`token.origin_rejected` / `token.ip_rejected` — a leaked token shows up there with the offending origin.

`Origin` is browser-supplied and forgeable by non-browser clients: the domain list contains misuse of a
leaked frontend token, it is not a security boundary. `allowedIps` is the only unforgeable constraint.

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

Rate limits: sliding window over IP + per-token buckets (admin vs API limits from settings); separate buckets for `/media/{id}` (and `/media/{id}/{filename}`) and anonymous writes. 429 includes `Retry-After` and `X-RateLimit-Limit`. Public create can use per-resource `settings.spam` (honeypot, captcha, duplicates, …) — see [anti-spam.md](anti-spam.md); its rate limit answers `429` too, the other checks `422`.

### Media URLs

Public file delivery is always through PHP (`GET /media/{id}` or pretty `GET /media/{id}/{filename}`; filename is cosmetic, lookup is by id). Disk paths under `storage/uploads` are not exposed.

Media objects in API responses include:

| Field | Example | Notes |
|---|---|---|
| `url` | `/media/50/cover.jpg` | Relative pretty path |
| `fullUrl` | `https://api.example.com/media/50/cover.jpg` | Absolute (`APP_URL` + `url`) |

Use `fullUrl` when the consumer is on another origin; `url` for same-host admin / reverse-proxy setups.

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

## Feature flags

Admin (`settings.write` / section `feature-flags`):

```http
GET/POST   /admin/api/feature-flags
GET/PATCH/DELETE /admin/api/feature-flags/{id}
GET/PUT    /admin/api/feature-flags/settings
```

Public (path configurable, default `/api/features`):

```http
GET /api/features
GET /api/features?keys=enabledNews,intMaxAmount
GET /api/features?keys=newCheckout&subject=user-42
```

Returns `{ "data": { "enabledNews": true, ... } }` for **enabled** flags only. Supports `ETag` / `If-None-Match`.

### A/B rollout (boolean only)

Per-flag fields: `abTest` (bool), `rolloutPercent` (0–100). When `abTest` is on, the public value is **not** the stored `value` — it is:

```text
crc32(flagKey + "\0" + subject) % 100 < rolloutPercent  →  true
```

Stable subject via `?subject=` / `?sid=` or header `X-Flag-Subject` (max 128 chars). Without subject, assignment is random per request (non-sticky). Responses with any A/B flag use `Cache-Control: private, no-store` and `Vary: X-Flag-Subject`.
## Translates

Admin (section `translates`):

```http
GET/POST/PATCH/DELETE /admin/api/locales[/{code}]
PUT    /admin/api/locales/{code}/default
GET/POST/PATCH/DELETE /admin/api/translations[/{id}]
GET/PUT /admin/api/translations/settings
GET    /admin/api/translations/export
POST   /admin/api/translations/import
```

Public (default `/api/translates`):

```http
GET /api/translates?locale=en
GET /api/translates?locale=ru&keys=amount.title,amount.description
```

Missing values fall back to the default locale, then `""`.
