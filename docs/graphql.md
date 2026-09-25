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
3. Setting key: `cms_settings` → `graphql.enabled` (`true` / `false`, default **false**).

When disabled, `GET`/`POST /api/graphql` (and `/api/v1/graphql`) return **404**.

Per-resource visibility reuses **`settings.apiEnabled`** (same as OpenAPI). Auth reuses `settings.public.*` and API token grants ([api.md](./api.md), [authentication.md](./authentication.md)).

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/graphql` | GraphiQL playground |
| `POST` | `/api/graphql` | Execute query / mutation |
| | `/api/v1/graphql` | Same (v1 alias) |

Admin sidebar link **GraphQL** opens the playground (404 until enabled). Companion: **Swagger** → `/api/docs` ([openapi.md](./openapi.md)).

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
  articles(page: Int, limit: Int, sort: String, search: String, filter: [FilterInput!]): ArticlesConnection!
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

## Example

```bash
curl -s -X POST "$BASE/api/graphql" \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ articles(limit: 5) { data { id title } meta { total } } }"}'
```

With nested relation (after `author_id` manyToOne → `authors`):

```bash
curl -s -X POST "$BASE/api/graphql" \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ articles(limit: 1) { data { id title author { id name } } } }"}'
```

## Out of scope (v1)

Subscriptions, DataLoader batching, custom resource APIs in the GraphQL schema, GraphQL client in `@hcms/sdk`.
