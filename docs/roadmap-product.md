# Product roadmap — gap vs modern headless CMS

Gap-анализ HCMS против типичного чеклиста «современной headless CMS» (Contentful / Sanity / Strapi Cloud).  
Инженерный backlog (anti-spam K–N, QA): [`improvements.md`](improvements.md).  
План реализации по фазам: [`implementation-plan.md`](implementation-plan.md).

**Позиционирование:** self-hosted REST CMS (схема → SQL → admin → OpenAPI → webhooks → RBAC → revisions).  
Одна инсталляция = один tenant. Облачные SLA / встроенная CDN / marketplace / multi-tenancy — вне ядра; закрываются докой «за прокси» или отдельным cloud-tier позже.

---

## Status matrix

| Capability | Status | Notes |
|---|---|---|
| REST API-first + OpenAPI | **HAVE** | Public `/api/{slug}`, admin CRUD, `GET /api/openapi.json` |
| Webhooks | **HAVE** | HMAC outbound, deliveries, UI — [webhooks.md](webhooks.md) |
| Custom content types + fields | **HAVE** | Resources/fields, ~17 built-in types + `blocks` |
| Revisions / history | **HAVE** | Entry revisions + restore |
| RBAC | **HAVE** | owner/admin/editor/viewer + section/resource ACL |
| Field-level permissions | **HAVE** | Per-grant `fieldAcl` (readable/writable overrides) |
| Row-level permissions | **HAVE** | Per-grant `ownEntriesOnly` → `created_by` filter |
| MFA + OAuth | **PARTIAL** | TOTP + Google + Telegram + generic OIDC; no SAML |
| Audit logs | **HAVE** | `cms_audit_logs` + Logs UI |
| Admin UI | **HAVE** | React schema builder, media, data table (en/ru) |
| Media resize / WebP | **PARTIAL** | GD: WebP/JPEG/PNG, sizes; no AVIF / full DAM |
| External extensibility | **PARTIAL** | Hooks, inbound, custom APIs, plugin field types (extensions/); no admin iframe apps / custom field widgets |
| SDK / ecosystem | **HAVE** | [`packages/sdk`](../packages/sdk) (`@hcms/sdk`) + [`examples/react`](../examples/react) |
| TS typegen from schema | **HAVE** | `hcms-types` CLI from OpenAPI |
| Relations | **HAVE** | `manyToOne`, virtual `oneToMany`, `oneToOne`, `manyToMany` (join tables) |
| Content i18n | **PARTIAL** | System cols + `?locale=` / fallback; locale switcher UI polish later |
| Cache / ISR | **HAVE** | Cache-Control + Surrogate-Key + webhook revalidation presets |
| Live Preview | **HAVE** | `settings.preview.url`, `POST …/preview`, `GET /api/preview/{token}` |
| Workflows + comments | **PARTIAL** | Entry `status` + transitions / UI; comments later |
| Repeatable components / blocks | **HAVE** | Field type `blocks` + typed `config.components`; rich editor polish later |
| Internal Event Bus | **HAVE** | `Cms\Events\EventBus`; webhooks as listeners |
| Webhook revalidation presets | **HAVE** | Vercel / Netlify / Cloudflare / Fastly templates |
| Data backups (DB + media) | **HAVE** | Admin Backups + `php cms backup:*` + cloud/SFTP — [recovery.md](recovery.md#data-backups-бд--media) |
| GraphQL | **HAVE** (opt-in) | [`graphql.md`](graphql.md); System toggle `graphql.enabled`; REST stays default |
| Plugin field types | **PARTIAL** | Core + `extensions/*/manifest.php` + composer `extra.hcms.field-types`; no marketplace |
| RTL admin | **MISSING** | `en` / `ru` only; no `dir=rtl` |
| Multi-tenancy | **OUT OF SCOPE** | One install = one tenant; no `tenant_id` in core |
| Built-in CDN + SLA | **OUT OF SCOPE** | Document Cloudflare/proxy; do not build CDN |
| Horizontal scaling | **OUT OF SCOPE** (for now) | Single PHP+MySQL; local FileCache |
| Marketplace / plugins store | **OUT OF SCOPE** | No plugin API |
| GDPR/SOC2 tooling | **OUT OF SCOPE** | Audit helps; no consent/DPA/export product |
| Mobile/native SDKs | **OUT OF SCOPE** | REST is enough; native SDK only if demand |

---

## Chosen product backlog (priority)

Implementation status: see [`implementation-plan.md`](implementation-plan.md).

### Shipped

- Phases 0–6 (cache, preview, i18n cols, M2M/blocks, workflows, SDK) — ~0.62.11
- Growth P5–P7 (~0.62.12): Event Bus, revalidation presets, field/row ACL
- Data backups (DB + media, cloud/SFTP, CLI) — ~0.62.12
- Anti-spam K–N — [`improvements.md`](improvements.md)
- **P8 GraphQL** (opt-in) — [`graphql.md`](graphql.md)

### Next (remaining)

1. Polish: locale switcher UI, rich blocks editor, workflow comments, System UI for `ip_auto_block_after_spam_rejects` (частично shipped).
2. CDN / HA / compliance / marketplace / **multi-tenancy** — docs or future cloud-tier, **не** core MVP.
3. RTL admin, SAML, AVIF — secondary.

---

## Epic sketches (for implementers)

### Shipped (reference)

P1–P4, growth P5–P7 и **P8 GraphQL** реализованы — детали в [`implementation-plan.md`](implementation-plan.md), [webhooks.md](webhooks.md), [permissions.md](permissions.md), [graphql.md](graphql.md).

### P8 — GraphQL (shipped, opt-in)

- Thin opt-in layer над QueryEngine / той же схемой, что OpenAPI.
- Не dual source of truth; REST остаётся default public API.
- Enable: System → GraphQL; playground `/api/graphql`.

### Multi-tenancy — out of scope

Self-hosted core: одна инсталляция = один клиент. `tenant_id` — только cloud-tier.

---

## One-liner

HCMS закрыл self-host base + product cut + **Event Bus / ISR presets / fine-grained ACL / opt-in GraphQL**. Остаются secondary polish (RTL/AVIF/SAML) и out-of-scope cloud concerns.
