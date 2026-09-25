# Implementation plan — product gaps

План реализации по [`roadmap-product.md`](roadmap-product.md).  
Инженерный anti-spam (K–N): [`improvements.md`](improvements.md) — параллельно, не блокирует.

**Правило:** каждый эпик — opt-in через `settings` или новый field type; default поведение public API не ломаем.

**Статус:** Phase 0–6 + anti-spam K–N + growth P5–P7 (Event Bus, webhook presets, field/row ACL) + **P8 GraphQL** + entry comments + System UI spam autoban — shipped.  
Follow-up polish: content i18n UX; rich blocks editor (nested validation/config). Tech debt O: QueryEngine / god-panels / pages→features.  
Не в плане: multi-tenancy, CDN product, SAML.

---

## Locked decisions

| Тема | Решение |
|---|---|
| Content i18n | Linked rows: system cols `locale` + `translation_group_id` на `res_{slug}` при `settings.localization.enabled`. Reuse `cms_locales`. Не колонки-на-язык, не `cms_translations`. |
| Live Preview | Short-TTL preview token (HMAC/`APP_SECRET` или таблица) + `settings.preview.url`. Payload = текущий snapshot entry. Без полного workflow. |
| M2M | `cardinality: manyToMany` → join table `res_{slug}_{field}` (`left_id`, `right_id`, UNIQUE). API: `number[]`. |
| oneToOne | `manyToOne` + UNIQUE на FK (та же колонка BIGINT). |
| Blocks | Field type `blocks`: JSON `[{ type, ... }]`, `config.components` = map type → nested field specs; validate via `PayloadValidator`. |
| Workflows | Opt-in `settings.workflow.enabled` → col `status` (`draft\|in_review\|published`). Public GET только `published`. Entry comments shipped. |
| Cache | `settings.cache.maxAge` (0 = как сейчас). Anonymous GET → `public, max-age=N`; Bearer → `private, no-store`. |
| SDK | Thin `@hcms/sdk` + CLI typegen из `GET /api/openapi.json`. Не второй schema DSL. |
| GraphQL | Opt-in `api.graphql.enabled`; thin layer over QueryEngine; see [`graphql.md`](graphql.md). REST stays default. |
| CDN / marketplace / multi-tenancy | Не в этом плане (OOS). |
| Event Bus | Sync in-process `EventBus`; webhooks listen; controllers не зовут WebhookDispatcher напрямую. |
| Revalidation presets | Webhook `preset` + `payloadMode` + `headers`; UI templates. |
| Field/row ACL | `field_acl_json` + `own_entries_only` на `cms_user_resource_grants`; QueryEngine projection/WHERE. |

---

## Phase map

```text
Phase 0  Cache-Control          (1–2 дня)   — быстрый DX, без schema risk
Phase 1  Live Preview           (3–5 дней)  — editor UX, без миграций res_*
Phase 2  Content i18n           (1–2 нед)   — system cols + migrate
Phase 3  M2M + oneToOne         (1–2 нед)   — RelationType + join migrate
Phase 4  Blocks                 (1–1.5 нед) — новый field type
Phase 5  Entry workflows        (1 нед)     — status col + transitions
Phase 6  SDK + typegen          (3–5 дней)  — packages/ + CLI
```

Phase 0 можно влить в любой момент. Phase 1 и 2 — P1 из матрицы (можно параллелить разными людьми). Phase 5 лучше после Preview (preview token уже умеет отдавать draft-like snapshot; workflow усиливает).

Параллельно: K → M → L → N в `improvements.md`.

---

## Phase 0 — Cache-Control на public content API

### Scope

- `settings.cache`: `{ maxAge: number }` (default `0`).
- `PublicApiController` GET list/get: если anon и `maxAge > 0` → `Cache-Control: public, max-age=N`; если Bearer → `private, no-store`.
- Опционально ETag по `updated_at` (как Translates/FeatureFlags) — только для get-by-id.

### Files

- [`src/Resources/ResourceService.php`](../src/Resources/ResourceService.php) — `defaultSettings` / `normalizeSettings`
- [`src/Http/Controllers/PublicApiController.php`](../src/Http/Controllers/PublicApiController.php)
- [`frontend/src/types/resource.ts`](../frontend/src/types/resource.ts), `ResourceSettingsPanel.tsx`
- Docs: `docs/api.md`, `docs/resources.md`

### Done when

- `maxAge=0` — поведение как сейчас (нет cache headers).
- Anon GET с `maxAge=60` отдаёт header; PATCH/POST не кэшируются.
- PHPUnit на headers; UI поле в Resource Settings.

---

## Phase 1 — Live Preview

### Scope

1. `settings.preview`: `{ url: string }` template (`{token}`, `{slug}`, `{id}`, `{locale?}`).
2. `POST /admin/api/resources/{id}/entries/{entryId}/preview` → `{ previewUrl, token, expiresAt }`.
3. Resolve: `GET /api/preview/{token}` (или query) → entry JSON, `Cache-Control: no-store`, TTL ~5–15 min.
4. Admin: кнопка «Preview» на entry form (если `url` задан).
5. Audit: `entry.preview_issued`. Webhook event optional later.

### Token design

- HMAC payload: `resourceId|entryId|exp|nonce` signed with `APP_SECRET`, или row в `cms_preview_tokens` (resource_id, entry_id, token_hash, expires_at).
- Prefer signed token без таблицы (KISS), если payload не огромный: resolve читает live row из `res_*` по id (не embeds весь snapshot в token).

### Files

- New: `src/Preview/PreviewTokenService.php`, routes в admin + public
- `ResourceService` settings, `EntriesController` / `AdminResourceRoutes`
- `frontend` entry detail / FormRenderer toolbar
- Docs: `docs/api.md` + section in `docs/resources.md`

### Done when

- С валидным token → JSON entry; просроченный/битый → 401/404.
- Public `/api/{slug}` без token не меняется.
- UI открывает `preview.url` в новой вкладке.

### Out of scope

- Draft vs published dual-write (это Phase 5).
- iframe SDK / visual editor.

---

## Phase 2 — Content localization

### Scope

1. `settings.localization`: `{ enabled: bool }` (default false).
2. При enable + migrate/publish: system columns `locale VARCHAR(16) NOT NULL`, `translation_group_id CHAR(36) NOT NULL` (+ indexes: unique `(locale, translation_group_id)`, index `locale`).
3. Create entry: генерировать `translation_group_id` (UUID); `locale` = default locale из `LocaleRepository` или из body.
4. `POST .../entries/{id}/translations` — создать sibling row (copy non-localizable? v1: empty writable fields, same group).
5. Public: `?locale=xx` = hard filter on that locale (no sibling-fallback like Translates). List без `?locale` → default locale from `cms_locales` only.
6. Admin: locale switcher на entry; список «missing translations».

### Field-level localizable (v1.1, optional)

- Flag `localizable` на field — v1 можно пропустить: вся строка = один locale (Strapi-like localized entry). Проще и достаточнее.

### Files

- `MigrationService` — `ensureLocalizationColumns`
- `QueryEngine` — create/list/find filters
- `PublicApiController`, `EntriesController`, `OpenApiGenerator`
- `LocaleRepository` (reuse)
- Frontend: entry form, resources list indicators
- Docs: `docs/schema.md`, `docs/api.md`, `AGENTS.md` § i18n content

### Done when

- Resource без localization — zero schema change.
- С localization: две locale-строки одной group; public `?locale=` hard filter (no sibling-fallback).
- Package export/import сохраняет group + locale.
- PHPUnit + seed-demo smoke.

### Risks

- Unique fields (`slug`) — unique per locale: unique index `(locale, slug)` вместо global unique (migrate awareness).
- Soft delete: siblings независимы.

---

## Phase 3 — manyToMany + oneToOne

### Scope

1. `RelationType`: allow `manyToMany`, `oneToOne`.
2. **oneToOne:** SQL как m2o (`BIGINT`) + UNIQUE index на колонке.
3. **manyToMany:** нет колонки на `res_*`; join `res_{ownerSlug}_{fieldName}` (`left_id`, `right_id`), UNIQUE pair; migrate create/drop.
4. Payload: m2m = `number[]`; serialize = `number[]` (labels optional как сейчас для m2o).
5. SchemaBuilder UI: cardinality select + relatedSlug.
6. Custom API embeds: m2m later (v1 list ids only).

### Files

- [`src/Fields/Types/RelationType.php`](../src/Fields/Types/RelationType.php)
- `SqlTypeMapper`, `MigrationService`, `SchemaDiff` / apply ops
- `QueryEngine`, `PayloadValidator`
- `OpenApiGenerator`, `SchemaBuilder.tsx`, `useRelationLabels.ts`
- Docs: `docs/schema.md`, `AGENTS.md` § relations

### Done when

- Publish resource с m2m создаёт join table; drop field дропает join (confirmDestructive).
- CRUD sync join rows на create/patch.
- oneToOne: второй link на тот же FK → DB/validation error.

---

## Phase 4 — Blocks (repeatable components)

### Scope

1. New field type `blocks` in `FieldTypeRegistry`.
2. Config: `{ components: { hero: FieldSpec[], cta: FieldSpec[], ... } }` — nested specs reuse existing type validators (no nested blocks v1).
3. Storage: JSON/TEXT column (как `json`).
4. Value: `[{ "type": "hero", "title": "...", ... }, ...]`.
5. Admin FormRenderer: add/remove/reorder blocks; per-type subform.
6. OpenAPI: generic array of objects (или oneOf если просто).

### Files

- `src/Fields/Types/BlocksType.php` (+ register)
- `PayloadValidator`, `SqlTypeMapper`
- `frontend` FormRenderer + SchemaBuilder config UI
- Docs: `docs/schema.md`

### Done when

- Invalid `type` / missing required nested field → 422 `error.fields`.
- List/filter по blocks — не поддерживаем (как json); document.

### Out of scope

- Shared component library table, nested blocks, page-builder canvas.

---

## Phase 5 — Entry workflows (minimal)

### Scope

1. `settings.workflow`: `{ enabled: bool }` (default false).
2. Migrate: col `status` VARCHAR, default `draft` for new; existing rows → `published` (чтобы public не опустел).
3. Public GET: `WHERE status = 'published'` когда workflow on.
4. Transitions:
   - editor: `draft → in_review`, edit only if not published (or allow edit → back to draft — document one rule)
   - admin/owner (or ACL publish): `in_review|draft → published`, `published → draft` (unpublish)
5. Endpoints: `POST .../entries/{id}/submit`, `.../publish`, `.../unpublish` **или** PATCH `status` с policy check.
6. Webhooks: `entry.submitted`, `entry.published`, `entry.unpublished`.
7. Preview (Phase 1) работает для любого status.

### Files

- `MigrationService`, `QueryEngine`, `EntriesController`, `RolePolicy` / ACL
- UI: status badge + actions on entry
- Docs: `docs/permissions.md`, `docs/api.md`

### Done when

- Workflow off — все rows видимы как сейчас.
- Workflow on — anon видит только published; editor не может publish.

### Out of scope

- Comments, assignees, multi-step custom workflows.

---

## Phase 6 — SDK + typegen

### Scope

1. Package `packages/sdk` (или `sdk/`): `createClient({ baseUrl, token })` → `list/get/create/update/delete(slug, ...)`.
2. CLI `packages/hcms-types` / bin: fetch `/api/openapi.json` → emit `openapi-typescript` output.
3. Update `examples/react` to consume SDK.
4. README section + `docs/openapi.md` link.

### Done when

- `npm pack` / local file dep работает; types соответствуют одному published resource smoke.

---

## Cross-cutting checklist (каждый phase)

- [ ] Settings в `ResourceService::normalizeSettings` + TS types + Settings UI
- [ ] OpenAPI обновлён
- [ ] Webhooks/events если меняется lifecycle
- [ ] PHPUnit (+ Vitest UI)
- [ ] Docs + `AGENTS.md` если меняется agent runbook
- [ ] Package export/import совместимость (i18n / m2m / blocks)
- [ ] `composer qa` / `npm run qa` на затронутых частях

---

## Explicitly deferred

| Item | Why |
|---|---|
| Full DAM / CDN image pipeline | Separate media epic beyond AVIF encode |
| SAML SSO | Enterprise; Google + Telegram + generic OIDC cover MVP |
| Full ar/he UI catalogs | RTL shell + sparse `ar` shipped; complete translations later |
| Admin iframe apps / plugin fields | Hooks cover external; UI extensions later |
| CDN / HA / Redis / marketplace / compliance | Out of self-hosted core |

---

## Suggested first PR slice

Самый узкий mergeable шаг к P1 матрицы:

**PR1 = Phase 1 Live Preview (token + settings + button)** — без миграций `res_*`, сразу ценность для редакторов.

Затем **PR2 = Phase 0 Cache-Control** (если не сделали раньше) или сразу **Phase 2 localization** columns.
