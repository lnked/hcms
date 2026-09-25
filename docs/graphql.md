# GraphQL

Opt-in GraphQL layer over the same [`QueryEngine`](../src/Api/QueryEngine.php) path as the public REST API. **REST remains the default.**

Admin UI playbook: [`ADMIN_UI_AGENT_GUIDE.md` §13](../ADMIN_UI_AGENT_GUIDE.md#13-graphql-opt-in).

## Enable

1. Admin → **Settings → System → GraphQL** → enable (owner only), or:
2. API:
   ```bash
   curl -s -X PATCH "$BASE/admin/api/settings" \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"graphql":{"enabled":true}}'
   ```
3. Setting keys (`cms_settings`):
   - **`api.graphql.enabled`** — POST execute (default `false`). Legacy alias: `graphql.enabled`.
   - **`api.graphql.playground`** — GET GraphiQL (default `false`; requires enabled).

When disabled, `POST /api/graphql` returns **404**. GraphiQL is off by default even when the API is on.

Per-resource visibility reuses **`settings.apiEnabled`** (same as OpenAPI). Auth reuses `settings.public.*` and API token grants ([api.md](./api.md), [authentication.md](./authentication.md)).

Schema rebuilds after publish / fields / migrate via [`MetadataCache`](../src/Core/MetadataCache.php) invalidation.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/graphql` | Execute query / mutation (when `api.graphql.enabled`) |
| `GET` | `/api/graphql` | GraphiQL (only when `api.graphql.playground`) |
| | `/api/v1/graphql` | Same (v1 alias) |

Admin sidebar **GraphQL** opens `/api/graphql` (404 until playground is on). Companion: **Swagger** → `/api/docs` ([openapi.md](./openapi.md)).

## Auth

Same rules as public REST:

- Optional `Authorization: Bearer <token>`
- If `public.read` (etc.) is false → token with grant required
- Admin Bearer bypasses grants
- Token origin/IP policy still applies

## Schema shape

For each published + `apiEnabled` resource with public key `articles`:

```graphql
type Query {
  articles(page: Int, limit: Int, sort: String, search: String, locale: String, filter: [FilterInput!]): ArticlesConnection!
  article(id: Int!): Articles
}

type Mutation {
  createArticles(input: ArticlesInput!): Articles!
  updateArticles(id: Int!, input: ArticlesInput!): Articles!
  deleteArticles(id: Int!): Boolean!
}
```

List response mirrors REST: `{ data, meta { total, page, limit } }`.

`FilterInput`: `{ field, op, value }` → REST `filter[field]` / `filter[field][op]`.

### Relations

- Scalar FK stays on the field (`author_id: Int`)
- Nested companion for manyToOne: `author` (strips `_id`) via `QueryEngine::find`
- Max nested depth: **3** (then nested fields resolve to `null` / `[]`)
- Custom resource APIs are REST-only (not in GraphQL v1)

## Example

```bash
curl -s -X POST "$BASE/api/graphql" \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ articles(limit: 5) { data { id title } meta { total } } }"}'
```

## Out of scope (v1)

Subscriptions, DataLoader batching, custom resource APIs in the GraphQL schema, GraphQL client in `@hcms/sdk`, admin GraphQL.
