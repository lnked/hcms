# Product roadmap — gap vs modern headless CMS

Gap-анализ HCMS против типичного чеклиста «современной headless CMS» (Contentful / Sanity / Strapi Cloud).  
Инженерный backlog (anti-spam K–N, QA): [`improvements.md`](improvements.md).  
План реализации по фазам: [`implementation-plan.md`](implementation-plan.md).

**Позиционирование:** self-hosted REST CMS (схема → SQL → admin → OpenAPI → webhooks → RBAC → revisions).  
Облачные SLA / встроенная CDN / marketplace — вне ядра; закрываются докой «за прокси» или отдельным cloud-tier позже.

---

## Status matrix

| Capability | Status | Notes |
|---|---|---|
| REST API-first + OpenAPI | **HAVE** | Public `/api/{slug}`, admin CRUD, `GET /api/openapi.json` |
| Webhooks | **HAVE** | HMAC outbound, deliveries, UI — [webhooks.md](webhooks.md) |
| Custom content types + fields | **HAVE** | Resources/fields, ~17 built-in types |
| Revisions / history | **HAVE** | Entry revisions + restore |
| RBAC | **HAVE** | owner/admin/editor/viewer + section/resource ACL |
| MFA + OAuth | **PARTIAL** | TOTP + Google (+ Telegram); no SAML / generic OIDC SSO |
| Audit logs | **HAVE** | `cms_audit_logs` + Logs UI |
| Admin UI | **HAVE** | React schema builder, media, data table (en/ru) |
| Media resize / WebP | **PARTIAL** | GD: WebP/JPEG/PNG, sizes; no AVIF / full DAM |
| External extensibility | **PARTIAL** | Hooks, inbound, custom APIs; no admin iframe apps / custom field widgets |
| SDK / ecosystem | **PARTIAL** | [`examples/react`](../examples/react) only; no npm packages |
| TS typegen from schema | **PARTIAL** | OpenAPI yes; no official CLI / `@hcms/sdk` codegen |
| Relations | **PARTIAL** | `manyToOne` + virtual `oneToMany`; no `oneToOne` / `manyToMany` |
| Content i18n | **PARTIAL** | UI locales + `/api/translates` (UI strings); **not** localized entries |
| Cache / ISR | **PARTIAL** | MetadataCache + webhooks DIY; no Cache-Control / surrogate keys on content API |
| Plugin field types | **PARTIAL** | Hardcoded registry; extend only in core |
| Live Preview | **MISSING** | No draft→frontend preview; «preview» = markdown / system update |
| Workflows + comments | **MISSING** | Resource status only; no entry review/approve/comments |
| Repeatable components / blocks | **MISSING** | No page-builder components; `json` is raw blob |
| GraphQL | **MISSING** | REST + filters + OpenAPI only |
| RTL admin | **MISSING** | `en` / `ru` only; no `dir=rtl` |
| Built-in CDN + SLA | **OUT OF SCOPE** | Document Cloudflare/proxy; do not build CDN |
| Horizontal scaling | **OUT OF SCOPE** (for now) | Single PHP+MySQL; local FileCache |
| Marketplace / plugins store | **OUT OF SCOPE** | No plugin API |
| GDPR/SOC2 tooling | **OUT OF SCOPE** | Audit helps; no consent/DPA/export product |
| Mobile/native SDKs | **OUT OF SCOPE** | REST is enough; SDK later if demand |

---

## Chosen product backlog (priority)

Implementation status: see [`implementation-plan.md`](implementation-plan.md).

Shipped (initial cut):

- Phase 0 Cache-Control (`settings.cache.maxAge`)
- Phase 1 Live Preview (`settings.preview.url`, `POST …/preview`, `GET /api/preview/{token}`)
- Phase 3 M2M + oneToOne (relation cardinalities + join tables)
- Phase 4 `blocks` field type
- Phase 2/5 settings + system columns on migrate (`locale` / `translation_group_id` / `status`) + public filters
- Phase 6 `@hcms/sdk` + `hcms-types` CLI under [`packages/sdk`](../packages/sdk)

First epic (historical priority):

1. **Content localization** — localized entries/fields, locale link, fallback (distinct from Translates UI strings).
2. **Live Preview** — draft content → frontend preview URL / token (same epic wave: editor headless UX).

Then:

3. **many-to-many (+ one-to-one)** and **repeatable components / blocks**
4. **Workflows** — minimum: entry status + approve (comments later)
5. **`@hcms/sdk` + OpenAPI typegen CLI**
6. **Cache-Control / revalidate hints** on public GET (amplifies existing webhooks)
7. **GraphQL** — only if real demand; otherwise OpenAPI + filters stay default
8. CDN / HA / compliance / marketplace — docs or future cloud-tier, not core MVP

Parallel: [`improvements.md`](improvements.md) items **K → M → L → N** (anti-spam).

---

## Epic sketches (for implementers)

### P1 — Content i18n + Live Preview

**Content i18n (sketch):**

- Locale already exists for Translates; reuse locale registry.
- Per-resource opt-in (`settings.localization`) or per-field `localizable`.
- Storage: either locale columns / side table, or linked entry group (`translationGroupId` + `locale`).
- Public API: `?locale=` with fallback to default locale.
- Admin: switcher on entry form, missing-translation indicators.

**Live Preview (sketch):**

- Preview token (short TTL) for draft/unpublished payload.
- Config: `settings.preview.url` template with `{token}` / `{slug}` / `{id}`.
- Optional webhook `entry.preview` or dedicated `GET /admin/api/.../preview`.
- Frontend site owns rendering; CMS only supplies draft JSON + auth.

### P2 — M2M + components

- `manyToMany`: join table `res_{a}_{b}` or `cms_relations`, migrate on publish.
- `oneToOne`: FK + unique constraint (or shared-PK variant — pick one, document).
- Components: reusable field groups; blocks: ordered JSON of `{type, fields}` with typed validation (not free-form `json`).

### P3 — Workflows (minimal)

- Entry-level status: `draft | in_review | published` (or map onto existing soft patterns).
- Role gate: only admin/owner (or ACL) can publish from `in_review`.
- Comments / mentions — later.

### P4 — SDK + typegen

- Thin fetch client + typed helpers.
- `npx hcms-types --url=... --token=...` → generate from OpenAPI.

---

## One-liner

HCMS already covers the **developer self-host base**. The biggest product gaps vs the “good headless CMS” checklist are **editor headless UX** (preview, content i18n, workflows) and **model depth** (M2M, components), plus **DX packaging** (SDK/typegen). GraphQL / CDN / marketplace / compliance are secondary or outside self-hosted scope.
