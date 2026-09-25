# ADMIN_UI_AGENT_GUIDE — гайд по админке HCMS для AI-агентов

Цель документа: дать агенту операционный playbook по UI и admin API, чтобы самостоятельно спроектировать схему, наполнить контент и подключить доставку (токены, webhooks, preview) для сайта на HCMS.

Источник правды — код: `frontend/src/` + `src/Http/*Routes.php`. Установка, CLI и низкоуровневые детали API — в [`AGENTS.md`](AGENTS.md) и [`docs/`](docs/). Этот гайд их не дублирует.

## Канонический цикл «развернуть сайт»

```text
POST /admin/api/auth/login                    → Bearer token
POST /admin/api/resources                      → draft resource
PUT  /admin/api/resources/{id}/fields          → схема целиком
POST /admin/api/resources/{id}/publish         → миграция + published
PATCH /admin/api/resources/{id}               → settings (public API, spam, preview, localization, workflow)
POST /admin/api/media                          → медиа
POST /admin/api/resources/{id}/entries         → контент
POST /admin/api/tokens                         → ключ для фронта сайта
POST /admin/api/webhooks                       → ISR/CDN/build hooks
GET  /api/{slug}                              → проверка публичного API
```

Клиент админки: [`frontend/src/lib/api.ts`](frontend/src/lib/api.ts) — `api` / `apiPage` / `apiUpload`, Bearer из `localStorage` (`hcms_token`), ошибки валидации в `ApiError.fields`. Basename админки: `getAdminBasename()` (обычно `/admin`).

ACL: роли `owner | admin | editor | viewer`, секции nav и `resourceGrants` / `tabs` — [`frontend/src/lib/rbac.ts`](frontend/src/lib/rbac.ts). Owner обходит section ACL; `account` всегда доступен.

---

## Оглавление

1. [Dashboard & System Overview](#1-dashboard--system-overview)
2. [Resources & Content Types](#2-resources--content-types)
3. [Schema Builder & Fields](#3-schema-builder--fields)
4. [Entries Management](#4-entries-management-data-table--form-renderer)
5. [Media Library](#5-media-library)
6. [Resource Settings](#6-resource-settings)
7. [Users, Roles & Auth](#7-users-roles--auth)
8. [API Tokens & Grants](#8-api-tokens--grants)
9. [Webhooks & Inbound Hooks](#9-webhooks--inbound-hooks)
10. [Translates & KeyValues](#10-translates--keyvalues)
11. [Integrations](#11-integrations)
12. [System & Updates](#12-system--updates)
- [Приложение A — E2E блог](#приложение-a--e2e-сценарий-блог)
- [Приложение B — RBAC routes](#приложение-b--route--section--minrole)

---

## 1. Dashboard & System Overview

**Цель раздела:** Быстро оценить здоровье инстанса (ресурсы, медиа, API-трафик, uptime) перед или после настройки сайта.

**Исходные файлы (Frontend):**
- [`frontend/src/features/auth/HomeLanding.tsx`](frontend/src/features/auth/HomeLanding.tsx) — `/` → Dashboard или redirect на `homeSection`
- [`frontend/src/pages/DashboardPage.tsx`](frontend/src/pages/DashboardPage.tsx)
- [`frontend/src/pages/DashboardCharts.tsx`](frontend/src/pages/DashboardCharts.tsx)
- [`frontend/src/app/router.tsx`](frontend/src/app/router.tsx), [`frontend/src/components/AppShell.tsx`](frontend/src/components/AppShell.tsx)

**Исходные файлы (Backend):**
- [`src/Http/SystemRoutes.php`](src/Http/SystemRoutes.php) → `SystemController`
- [`src/Http/UptimeRoutes.php`](src/Http/UptimeRoutes.php) → `UptimeController`
- Health: `GET /admin/api/health` в [`src/Http/Kernel.php`](src/Http/Kernel.php)

**Используемые API Endpoints:**
- `GET /admin/api/system/stats`
- `GET /admin/api/system/stats/timeseries?days=…`
- `GET /admin/api/uptime/summary`
- `GET /admin/api/health` (без auth, вне rate-limit)

### 🔄 Пользовательский сценарий (User Flow)

1. Логин → `/` (`HomeLanding`).
2. Если `user.homeSection === 'dashboard'` (или fallback) — рендер `DashboardPage`.
3. Смотрит карточки stats + графики timeseries + краткий uptime summary.
4. При ошибках API может открыть детали path/status из ответов.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Dashboard не создаёт схему. Используй только для smoke-check после install/publish: `stats` должен показать ненулевые `resources` после шагов §2–3.
- **Валидация и проверки:** `GET /admin/api/health` → ok; `GET /admin/api/system/stats` с Bearer. Если `homeSection` не dashboard — UI уведёт на другой раздел; для API это неважно.
- **Best Practices:** Не дергай `system/version` лишний раз со страниц, где shell уже его грузит (см. комментарий в `SystemPage`). Для агента достаточно `health` + `stats` + `php cms status`.

---

## 2. Resources & Content Types

**Цель раздела:** Создать сущности сайта (ContentType + Resource 1:1): страницы, статьи, товары, авторы и т.д.

**Исходные файлы (Frontend):**
- [`frontend/src/features/resources/ResourcesPage.tsx`](frontend/src/features/resources/ResourcesPage.tsx) — список, publish/delete, package import
- [`frontend/src/features/resources/CreateResourcePage.tsx`](frontend/src/features/resources/CreateResourcePage.tsx)
- [`frontend/src/features/resources/ResourceDetailPage.tsx`](frontend/src/features/resources/ResourceDetailPage.tsx) — табы `overview|schema|data|settings|api|hooks|export`

**Исходные файлы (Backend):**
- [`src/Http/AdminResourceRoutes.php`](src/Http/AdminResourceRoutes.php)
- `ResourceController`, `ResourcePackageController`, `MigrationController`

**Используемые API Endpoints:**
- `GET /admin/api/resources`
- `POST /admin/api/resources`
- `GET /admin/api/resources/{id}`
- `PATCH /admin/api/resources/{id}`
- `POST /admin/api/resources/{id}/publish`
- `DELETE /admin/api/resources/{id}`
- `POST /admin/api/resources/{id}/migrate`
- `POST /admin/api/resources/package/import`
- `GET /admin/api/resources/{id}/package/export`

UI routes: `/resources`, `/resources/new`, `/resources/:id/:tab?/:entryId?`.

### 🔄 Пользовательский сценарий (User Flow)

1. Nav → Resources → New.
2. Заполнить `label` (обязательно), опционально `name` / `slug` / `endpoint` / `description`.
3. Submit → redirect `/resources/{id}/overview`.
4. Schema → Data → Settings (см. §3–6).
5. Publish (кнопка на detail) или publish из списка.
6. Опционально: import package на `ResourcesPage`.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:**
  ```http
  POST /admin/api/resources
  { "label": "Articles", "name": "articles", "slug": "articles",
    "settings": { "apiEnabled": true,
      "public": { "read": true, "create": false, "update": false, "delete": false } } }
  ```
  Создаётся `draft`; таблицы `res_*` ещё нет. Связанный ContentType создаётся вместе с ресурсом.
- **Валидация и проверки:**
  - Slug: `^[a-z][a-z0-9_]{0,47}$` (без дефиса).
  - Endpoint: `^/api(/v1)?/[a-z][a-z0-9_-]{0,62}$`.
  - Публичный API отдаёт только `status=published` + `settings.apiEnabled`.
  - `isSystem` ресурсы не удалять без явной необходимости.
- **Best Practices:** Сначала целевые ресурсы для relations (authors → articles). Для копирования схемы — package export/import. После смены схемы у уже published — `POST .../migrate` с `confirmDestructive: true` при деструктивных операциях.

---

## 3. Schema Builder & Fields

**Цель раздела:** Описать поля ресурса (типы, флаги, relations, blocks) — это source of truth для SQL, REST, OpenAPI и FormRenderer.

**Исходные файлы (Frontend):**
- [`frontend/src/features/schema-builder/SchemaBuilder.tsx`](frontend/src/features/schema-builder/SchemaBuilder.tsx)
- [`frontend/src/features/resources/ResourceDetailPage.tsx`](frontend/src/features/resources/ResourceDetailPage.tsx) (save: `PUT .../fields`)
- [`frontend/src/types/field.ts`](frontend/src/types/field.ts)

**Исходные файлы (Backend):**
- `FieldController`, `MigrationController`
- FieldType registry / discovery (в т.ч. plugin types из `extensions/`)

**Используемые API Endpoints:**
- `GET /admin/api/field-types`
- `GET /admin/api/resources/{id}/fields`
- `PUT /admin/api/resources/{id}/fields` — **полная замена схемы**
- `POST /admin/api/resources/{id}/fields` — одно поле
- `PATCH /admin/api/fields/{id}`
- `DELETE /admin/api/fields/{id}`
- `POST /admin/api/resources/{id}/migrate`

UI: `/resources/:id/schema`.

### 🔄 Пользовательский сценарий (User Flow)

1. Открыть Schema tab.
2. Добавить поля, выбрать type из `GET /admin/api/field-types` (builtin + plugins).
3. Настроить flags (`required`, `nullable`, `unique`, `searchable`, `sortable`, `filterable`, …) и `config`.
4. Save → `PUT` без `clientKey`.
5. Если ресурс published — migrate (destructive → confirm).
6. Publish из overview, если ещё draft.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Builtin типы (`FIELD_TYPES`): `string`, `text`, `richtext`, `integer`, `float`, `boolean`, `date`, `datetime`, `email`, `url`, `uuid`, `json`, `blocks`, `enum`, `slug`, `image`, `file`, `relation`. Плюс plugin names из API.

  Типичный `config`:
  | type | config |
  |---|---|
  | `string` / `slug` | `maxLength`; slug: `associatedWith` |
  | `enum` | `options: string[]` (обязателен) |
  | `date` / `datetime` | `format` |
  | `image` / `file` | `multiple`, `formats`; image: `encodeFormat`, `sizes[]` |
  | `relation` | `cardinality`: `manyToOne` \| `oneToOne` \| `manyToMany` \| `oneToMany`; `relatedSlug`, `labelField`; для `oneToMany` — `foreignKey`, UI ставит `writable: false` |
  | `blocks` | `components: { [componentType]: [{ name, type, required? }] }` (вложенный `blocks` запрещён в UI) |

- **Валидация и проверки:**
  - Имя поля: тот же regex, что slug. Дубликаты в одном PUT → `422`.
  - **`PUT` затирает поля, которых нет в payload** — всегда `GET` перед правкой.
  - `required: true` без `nullable: false` → обязательно в API, но NULL в SQL. Для обязательных колонок ставь оба.
  - После правки схемы без migrate/publish — колонок в БД нет.
  - Деструктивные migrate без `confirmDestructive: true` → `422`, откат целиком.
- **Best Practices:** `filterable`/`sortable`/`searchable` только на нужных полях (иначе шумные индексы и фильтры). Relations: сначала publish целевого slug. Для SEO-страниц: `title` (string) + `slug` (slug, `associatedWith: "title"`, unique). Blocks — для гибких лендингов; для жёсткой структуры предпочтительнее отдельные поля.

---

## 4. Entries Management (Data Table & Form Renderer)

**Цель раздела:** CRUD контента сайта: список, форма, revisions, preview, workflow status, translations, comments, import/export.

**Исходные файлы (Frontend):**
- [`frontend/src/features/resources/ResourceEntriesPanel.tsx`](frontend/src/features/resources/ResourceEntriesPanel.tsx)
- [`frontend/src/features/resources/useResourceEntriesList.ts`](frontend/src/features/resources/useResourceEntriesList.ts)
- [`frontend/src/features/resources/EntryRevisionsPanel.tsx`](frontend/src/features/resources/EntryRevisionsPanel.tsx)
- [`frontend/src/features/resources/EntryCommentsPanel.tsx`](frontend/src/features/resources/EntryCommentsPanel.tsx)
- [`frontend/src/features/form-renderer/FormRenderer.tsx`](frontend/src/features/form-renderer/FormRenderer.tsx)
- [`frontend/src/features/form-renderer/BlocksEditor.tsx`](frontend/src/features/form-renderer/BlocksEditor.tsx)
- `frontend/src/features/data-table/*`

**Исходные файлы (Backend):**
- `EntriesController` (+ preview token logic / `PreviewController` по маршрутам entries)

**Используемые API Endpoints:**
- `GET/POST /admin/api/resources/{id}/entries`
- `GET/PATCH/DELETE /admin/api/resources/{id}/entries/{entryId}`
- `GET .../entries/relation-labels`
- `GET .../entries/export`, `POST .../entries/import`
- `POST .../entries/bulk-delete`
- `GET .../entries/{entryId}/revisions`
- `POST .../entries/{entryId}/revisions/{revId}/restore`
- `POST .../entries/{entryId}/preview`
- `POST .../entries/{entryId}/status`
- `GET/POST .../entries/{entryId}/translations`
- `GET/POST/DELETE .../entries/{entryId}/comments[/{commentId}]`

UI: `/resources/:id/data`, `/resources/:id/data/:entryId`.

Зависимости от Settings (§6):
- `settings.localization.enabled` → locale filter, sibling translations, `POST .../translations`, при create передаётся `locale`
- `settings.workflow.enabled` → `POST .../status` (`draft` \| `in_review` \| `published`), comments panel
- `settings.preview.url` → кнопка Preview → `POST .../preview` → open `previewUrl`

### 🔄 Пользовательский сценарий (User Flow)

1. Resource должен быть `published` (иначе data tab ограничен).
2. Список: search/sort/filter по filterable полям, колонки из `settings.list.columns`.
3. New / Edit → `FormRenderer` по схеме.
4. Save → POST/PATCH; image/file через Media picker (§5).
5. Опционально: preview, смена workflow status, add translation, revisions restore, comments.
6. Bulk delete / CSV|JSON import-export.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Сначала schema+publish. Затем:
  ```http
  POST /admin/api/resources/{id}/entries
  { "title": "Hello", "slug": "hello", "body": "<p>…</p>", "locale": "en" }
  ```
  `locale` — только если localization включена. Media fields: id из `POST /admin/api/media` (см. тип `MediaFieldValue` в `field.ts`).
- **Валидация и проверки:**
  - Entries на draft resource → ошибка/пусто (UI требует published).
  - Фильтр по не-filterable полю → `422 Field not filterable`.
  - Import: format `json`|`csv`, лимиты размера/строк (см. AGENTS.md); всегда create, не upsert.
  - Preview без `settings.preview.url` в UI скрыт.
- **Best Practices:** Включай localization только после заведения locales в §10. Workflow: контент-редакторы работают в `draft`/`in_review`, publish status отдельно от resource.publish. Не путай resource `status` и entry workflow status.

---

## 5. Media Library

**Цель раздела:** Загрузить и подготовить изображения/файлы для полей `image`/`file` и публичной раздачи `/media/{id}`.

**Исходные файлы (Frontend):**
- [`frontend/src/features/media/MediaPage.tsx`](frontend/src/features/media/MediaPage.tsx)
- [`frontend/src/features/media/MediaFieldPicker.tsx`](frontend/src/features/media/MediaFieldPicker.tsx)
- [`frontend/src/features/media/ImageEditorDialog.tsx`](frontend/src/features/media/ImageEditorDialog.tsx)
- [`frontend/src/features/media/OptimizeImageDialog.tsx`](frontend/src/features/media/OptimizeImageDialog.tsx)

**Исходные файлы (Backend):**
- `MediaController` ([`AdminResourceRoutes.php`](src/Http/AdminResourceRoutes.php))

**Используемые API Endpoints:**
- `GET /admin/api/media?page=&limit=`
- `POST /admin/api/media` (multipart, `apiUpload`)
- `GET/DELETE /admin/api/media/{id}`
- `POST /admin/api/media/{id}/regenerate`
- `POST /admin/api/media/{id}/edit`
- `POST /admin/api/media/{id}/optimize`
- `POST /admin/api/media/bulk-optimize`
- `POST /admin/api/media/bulk-delete`
- Public: `GET /media/{id}`, `GET /media/{id}/{filename}`

UI: `/media` (minRole `editor`).

### 🔄 Пользовательский сценарий (User Flow)

1. Media → upload файлов.
2. Edit/crop → `.../edit`, regenerate sizes → `.../regenerate`.
3. Optimize / bulk-optimize.
4. Delete / bulk-delete.
5. Из entries: `MediaFieldPicker` upload или выбор существующего.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** В схеме image-поля задай `sizes` / `encodeFormat` до массовой загрузки — иначе понадобится regenerate.
- **Валидация и проверки:** Upload только через multipart (`apiUpload`), не JSON. В entries кладётся структура с `id` (+ variants), не сырой URL. Rate-limit на PHP-hit публичной раздачи; после первого запроса безопасные типы могут прогреваться в `{publicDir}/media/`.
- **Best Practices:** Сначала media, потом entries со ссылками. Для CDN — webhooks surrogate_keys (§9) на update/delete.

---

## 6. Resource Settings

**Цель раздела:** Включить публичный API, кэш, preview URL, localization, workflow, spam; настроить custom APIs, sync hooks, export package.

**Исходные файлы (Frontend):**
- [`frontend/src/features/resources/ResourceSettingsPanel.tsx`](frontend/src/features/resources/ResourceSettingsPanel.tsx)
- [`frontend/src/features/resources/ResourceCustomApisPanel.tsx`](frontend/src/features/resources/ResourceCustomApisPanel.tsx)
- [`frontend/src/features/resources/ResourceApiPlayground.tsx`](frontend/src/features/resources/ResourceApiPlayground.tsx)
- [`frontend/src/features/resources/ResourceHooksPanel.tsx`](frontend/src/features/resources/ResourceHooksPanel.tsx)
- [`frontend/src/features/resources/ResourceExportPanel.tsx`](frontend/src/features/resources/ResourceExportPanel.tsx)
- [`frontend/src/features/resources/ResourceFetchExample.tsx`](frontend/src/features/resources/ResourceFetchExample.tsx)
- Тип: [`frontend/src/types/resource.ts`](frontend/src/types/resource.ts) → `ResourceSettings`

**Исходные файлы (Backend):**
- `ResourceController` (`PATCH`), `ResourceApiController`, `ResourceHooksController`, `ResourcePackageController`

**Используемые API Endpoints:**
- `PATCH /admin/api/resources/{id}` — merge settings (+ label и т.д.)
- `GET/POST /admin/api/resources/{id}/apis`, `GET/PATCH/DELETE .../apis/{apiId}`
- `GET/POST /admin/api/resources/{id}/hooks`, `PATCH/DELETE .../hooks/{hookId}`, `GET .../deliveries`, `POST .../test`
- `GET /admin/api/resources/{id}/package/export?includeData=`

UI tabs: `/resources/:id/settings`, `/api`, `/hooks`, `/export`.

Ключи `settings` (из типа + UI):
- `apiEnabled`, `pagination`, `search`, `sorting`, `filtering`
- `public.{read,create,update,delete}`
- `spam.*` (honeypotField, minSubmitMs, rateLimitPerMinute, requireCaptcha, maxLinks, blocklist, rejectDuplicates)
- `cache.maxAge`
- `preview.url`
- `localization.enabled`
- `workflow.enabled`
- `deleteStrategy` / `softDelete`, `list.columns`

### 🔄 Пользовательский сценарий (User Flow)

1. Settings tab → toggles + spam + cache + preview URL + localization/workflow.
2. Save → `PATCH`.
3. API tab → custom named endpoints + playground.
4. Hooks tab → sync hooks `before_create` | `after_create` (url, secret, timeoutMs, onFailure reject|continue).
5. Export tab → package download.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:**
  ```http
  PATCH /admin/api/resources/{id}
  { "settings": {
      "apiEnabled": true,
      "public": { "read": true, "create": false, "update": false, "delete": false },
      "preview": { "url": "https://site.example/preview?path={path}&token={token}" },
      "localization": { "enabled": true },
      "workflow": { "enabled": true },
      "cache": { "maxAge": 60 }
  } }
  ```
  Точный placeholder preview URL — как в UI/бэкенде preview service; агент обязан сверить с `POST .../preview` ответом.
- **Валидация и проверки:**
  - `public.create` без spam → UI показывает unprotected warning; не включай write-public без spam ([`docs/anti-spam.md`](docs/anti-spam.md)).
  - Localization без locales (§10) бесполезна в entries.
  - Resource hooks ≠ global webhooks (§9): hooks — sync на create phases; webhooks — async event bus.
- **Best Practices:** Корпоративный сайт: `public.read=true`, остальные false + API token. Формы обратной связи: отдельный resource + spam + `public.create`. Custom APIs — для урезанных проекций под конкретный фронт.

---

## 7. Users, Roles & Auth

**Цель раздела:** Доступ в админку: логин, роли, ACL секций/ресурсов, TOTP, OAuth identities.

**Исходные файлы (Frontend):**
- [`frontend/src/features/auth/LoginPage.tsx`](frontend/src/features/auth/LoginPage.tsx)
- [`frontend/src/features/auth/OAuthCompletePage.tsx`](frontend/src/features/auth/OAuthCompletePage.tsx)
- [`frontend/src/features/auth/RequireAuth.tsx`](frontend/src/features/auth/RequireAuth.tsx)
- [`frontend/src/features/auth/RequireSection.tsx`](frontend/src/features/auth/RequireSection.tsx)
- [`frontend/src/pages/UsersPage.tsx`](frontend/src/pages/UsersPage.tsx)
- [`frontend/src/pages/AccountPage.tsx`](frontend/src/pages/AccountPage.tsx)
- [`frontend/src/features/account/SecurityCard.tsx`](frontend/src/features/account/SecurityCard.tsx), `TotpSection`, `ChangePasswordDialog`, `UserAclDialogs`
- [`frontend/src/pages/OauthIntegrationsCard.tsx`](frontend/src/pages/OauthIntegrationsCard.tsx) (провайдеры — на Integrations, см. §11)

**Исходные файлы (Backend):**
- [`src/Http/AuthRoutes.php`](src/Http/AuthRoutes.php) → `AuthController`
- `UsersController`

**Используемые API Endpoints:**
- Auth: `POST /admin/api/auth/login`, `logout`, `password`, `telegram`, `totp/{setup,enable,disable,complete}`
- `GET /admin/api/auth/me`, `captcha`, `providers`
- OAuth: `GET /admin/api/auth/google/{start,callback}`, `GET /admin/api/auth/oidc/{start,callback}`
- Identities: `GET /admin/api/auth/identities`, link/unlink endpoints
- Users: `GET/POST /admin/api/users`, `PATCH/DELETE /admin/api/users/{id}`, `GET/PATCH /admin/api/users/{id}/acl`

UI: `/login`, `/oauth/complete`, `/settings/users` (admin+), `/settings/account`.

### 🔄 Пользовательский сценарий (User Flow)

1. Login email/password (± captcha, ± totpCode / TOTP complete).
2. Social: Google / OIDC / Telegram per `providers`.
3. Users: create with role, patch, ACL dialog (sections + resourceGrants/tabs), disable/delete.
4. Account: password, TOTP, linked identities.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Для headless-скриптов достаточно owner/admin Bearer. Редакторов контента заводи через `POST /admin/api/users` + ACL tabs `schema|data` без `settings` при необходимости.
- **Валидация и проверки:** 5 fails / 15 min → `429`. `401 TOTP_REQUIRED` без кода. API-токен из §8 ≠ admin session: admin-only endpoints (uptime run, update run) отклонят token. ACL owner-only операции — см. [`docs/permissions.md`](docs/permissions.md).
- **Best Practices:** Не логируй пароли. Для CI/агента — `remember: true` login. OAuth credentials настраиваются в §11 (`/admin/api/integrations/oauth`), не на Users page.

---

## 8. API Tokens & Grants

**Цель раздела:** Выдать ключи фронтенду/мобильному приложению сайта с грантами на ресурсы и ограничениями origin/IP.

**Исходные файлы (Frontend):**
- [`frontend/src/pages/TokensPage.tsx`](frontend/src/pages/TokensPage.tsx)
- [`frontend/src/pages/TokenRestrictionsFields.tsx`](frontend/src/pages/TokenRestrictionsFields.tsx)

**Исходные файлы (Backend):**
- `TokensController`

**Используемые API Endpoints:**
- `GET/POST /admin/api/tokens`
- `GET/PATCH/DELETE /admin/api/tokens/{id}`
- `PUT /admin/api/tokens/{id}/grants`
- `POST /admin/api/tokens/{id}/restore`

UI: `/settings/tokens` (admin+).

### 🔄 Пользовательский сценарий (User Flow)

1. Tokens → Create: name, grants (`resourceId` null = global; canRead/Create/Update/Delete), allowedOrigins, requireOrigin, allowedIps.
2. Сохранить plaintext token один раз.
3. Edit grants/restrictions; revoke/restore.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:**
  ```http
  POST /admin/api/tokens
  { "name": "frontend",
    "grants": [{ "resourceId": 1, "canRead": true, "canCreate": false, "canUpdate": false, "canDelete": false }],
    "allowedOrigins": ["https://app.example.com"],
    "requireOrigin": false,
    "allowedIps": [] }
  ```
  Пустые grants = запрет на приватное. Если `public.read=true`, анонимный GET может работать без токена.
- **Валидация и проверки:** Токен показывается один раз. Admin Bearer обходит grants — не путай в скриптах. Для CORS/Origin смотри `requireOrigin` + `allowedOrigins`.
- **Best Practices:** Отдельный read-only token на production frontend. Не клади token в git. После создания ресурса — обнови grants под новые `resourceId`.

---

## 9. Webhooks & Inbound Hooks

**Цель раздела:** Исходящие события (ISR/CDN/build) и входящие endpoints для внешней записи в ресурс.

**Исходные файлы (Frontend):**
- [`frontend/src/features/webhooks/WebhooksPage.tsx`](frontend/src/features/webhooks/WebhooksPage.tsx)
- [`frontend/src/features/inbound/InboundEndpointsPage.tsx`](frontend/src/features/inbound/InboundEndpointsPage.tsx)
- Resource sync hooks: [`ResourceHooksPanel.tsx`](frontend/src/features/resources/ResourceHooksPanel.tsx) (см. §6)

**Исходные файлы (Backend):**
- `WebhooksController`, `InboundEndpointsController`
- Public inbound: `PublicInboundController` в [`PublicApiRoutes.php`](src/Http/PublicApiRoutes.php)

**Используемые API Endpoints:**
- Webhooks: `GET/POST /admin/api/webhooks`, `GET/PATCH/DELETE /admin/api/webhooks/{id}`, `GET .../deliveries`, `POST .../test`
- Inbound: `GET/POST /admin/api/inbound-endpoints`, `GET/PATCH/DELETE .../{id}`, `GET .../deliveries`, `POST .../test`
- Public: `POST /api/inbound/{slug}` (и `/api/v1/inbound/{slug}`)

UI: `/settings/webhooks`, `/settings/inbound`.

События webhooks (из UI): `entry.created`, `entry.updated`, `entry.deleted`, `entry.submitted`, `entry.published`, `entry.unpublished`, `resource.published`.

Presets: `custom` (payloadMode `hcms`), `vercel_deploy` / `netlify_build` (`empty`), `cloudflare_purge` / `fastly_purge` (`surrogate_keys`).

Inbound: `slug`, `resourceId`, optional `secret` → URL `/api/inbound/{slug}`.

### 🔄 Пользовательский сценарий (User Flow)

1. Webhooks → выбрать preset → url/secret/events/resource scope → create → Test → deliveries.
2. Inbound → slug + target resource → secret → Test.
3. Для sync validation на create — Resource → Hooks (§6).

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** После publish контента повесь webhook на `entry.updated` + `resource.published` под твой host (Vercel/Netlify/CDN). Для внешних форм/CRM — inbound endpoint на нужный resource.
- **Валидация и проверки:** HMAC/secret должен совпадать на приёмнике. Test delivery смотри в deliveries. Resource hooks phases только `before_create` | `after_create` (не полный набор webhook events).
- **Best Practices:** ISR: preset empty + deploy hook URL. CDN purge: surrogate_keys. Не дублируй один и тот же URL и в webhooks, и в resource hooks без нужды. Подробности: [`docs/webhooks.md`](docs/webhooks.md), [`docs/hooks.md`](docs/hooks.md).

---

## 10. Translates & KeyValues

**Цель раздела:** UI-строки сайта (translations + locales), простой KV store, feature flags; публичные пути `/api/translates`, `/api/kv`, `/api/features` (настраиваемые).

**Исходные файлы (Frontend):**
- [`frontend/src/pages/TranslatesPage.tsx`](frontend/src/pages/TranslatesPage.tsx)
- [`frontend/src/pages/KeyValuesPage.tsx`](frontend/src/pages/KeyValuesPage.tsx)
- [`frontend/src/pages/FeatureFlagsPage.tsx`](frontend/src/pages/FeatureFlagsPage.tsx)

**Исходные файлы (Backend):**
- [`src/Http/FeatureTranslatesRoutes.php`](src/Http/FeatureTranslatesRoutes.php) → `TranslatesController`, `FeatureFlagsController`
- [`src/Http/KeyValuesRoutes.php`](src/Http/KeyValuesRoutes.php) → `KeyValuesController`

**Используемые API Endpoints:**
- Locales: `GET/POST /admin/api/locales`, `PATCH/DELETE /admin/api/locales/{code}`, `PUT /admin/api/locales/{code}/default`
- Translations: `GET/PUT /admin/api/translations/settings`, `GET/POST /admin/api/translations`, `GET/PATCH/DELETE .../{id}`, export/import
- Key-values: `GET/PUT /admin/api/key-values/settings`, CRUD `/admin/api/key-values`
- Feature flags: `GET/PUT /admin/api/feature-flags/settings`, CRUD `/admin/api/feature-flags`
- Public paths регистрируются динамически из settings (`enabled`, `path`, `requireToken`)

UI: `/settings/translates`, `/settings/key-values`, `/settings/feature-flags`.

### 🔄 Пользовательский сценарий (User Flow)

1. Translates: создать locales → default → CRUD keys per locale → settings public path → export/import.
2. KeyValues: CRUD entries → settings public API.
3. FeatureFlags: CRUD flags → settings public API.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:**
  1. `POST /admin/api/locales` `{ "code": "en", "label": "English", ... }` + default.
  2. Включить `settings.localization` на контентных ресурсах (§6).
  3. UI copy сайта → translations; runtime config → key-values; toggles → feature-flags.
- **Валидация и проверки:** Content i18n (entry translations) ≠ UI translates dictionary. Без enabled locales entries localization UI пустой. Public path conflict — не пересекай с `/api/{resourceSlug}`.
- **Best Practices:** Default locale до наполнения entries. KV для menu/footer/contacts; translations для i18n UI strings фронта; flags для постепенного выката фич на сайте.

---

## 11. Integrations

**Цель раздела:** Email-провайдер (Resend/Postmark/Mailgun и т.д.), email send APIs, OAuth/OIDC/Telegram credentials для логина в админку.

**Исходные файлы (Frontend):**
- [`frontend/src/pages/IntegrationsPage.tsx`](frontend/src/pages/IntegrationsPage.tsx)
- [`frontend/src/pages/OauthIntegrationsCard.tsx`](frontend/src/pages/OauthIntegrationsCard.tsx)
- [`frontend/src/pages/buildEmailSendFetchExample.ts`](frontend/src/pages/buildEmailSendFetchExample.ts)

**Исходные файлы (Backend):**
- `IntegrationsController` ([`AdminResourceRoutes.php`](src/Http/AdminResourceRoutes.php))

**Используемые API Endpoints:**
- `GET/PUT /admin/api/integrations/email`
- `POST /admin/api/integrations/email/test`
- `GET/POST /admin/api/integrations/email/apis`
- `GET/PATCH/DELETE /admin/api/integrations/email/apis/{id}`
- `GET/PUT /admin/api/integrations/oauth`

UI: `/settings/integrations` (admin+).

### 🔄 Пользовательский сценарий (User Flow)

1. Email: выбрать provider, сохранить credentials → Test.
2. Создать named email API endpoints для вызова с сайта.
3. OAuth card: Google / OIDC / Telegram settings → Save → появляются на Login.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Для сайта с формами «напишите нам» — email integration + public/inbound resource или email API. Для SSO админов — `PUT /admin/api/integrations/oauth` до проверки Login.
- **Валидация и проверки:** Секреты только в settings/.env, не в репозитории. Test endpoint проверяет доставку. OAuth redirect URLs должны совпадать с инстансом.
- **Best Practices:** См. [`docs/integrations-email.md`](docs/integrations-email.md), [`docs/authentication.md`](docs/authentication.md). Не смешивай email API tokens с content API tokens (§8).

---

## 12. System & Updates

**Цель раздела:** Версия/обновление инстанса, locale UI админки, api-access / admin-base / sections / security; data backups; кратко logs & uptime.

**Исходные файлы (Frontend):**
- [`frontend/src/pages/SystemPage.tsx`](frontend/src/pages/SystemPage.tsx), `ApiAccessForm`, `SecuritySettingsCard`
- [`frontend/src/pages/BackupsPage.tsx`](frontend/src/pages/BackupsPage.tsx)
- [`frontend/src/features/logs/LogsPage.tsx`](frontend/src/features/logs/LogsPage.tsx)
- [`frontend/src/features/uptime/UptimePage.tsx`](frontend/src/features/uptime/UptimePage.tsx)

**Исходные файлы (Backend):**
- [`SystemRoutes.php`](src/Http/SystemRoutes.php) → `SystemController`
- [`BackupRoutes.php`](src/Http/BackupRoutes.php) → `BackupsController`
- [`UptimeRoutes.php`](src/Http/UptimeRoutes.php) → `UptimeController`
- `LogsController`, `SettingsController`

**Используемые API Endpoints:**
- System: `GET /admin/api/system/version`, `stats`, `stats/timeseries`, `changelog`; `POST changelog/seen`
- Update: `GET .../update/check`, `status`; `POST .../update/preview`, `.../update/run`
- Settings: `GET /admin/api/settings/locale|api-access|admin-base|admin-sections|security`; `PATCH /admin/api/settings`
- Backups: `GET/POST /admin/api/backups`, `GET .../status`, cloud connect/test/push, `POST .../{id}/restore`, `DELETE`, `GET .../download`
- Uptime: `GET .../summary|status|targets`, CRUD targets, `POST .../run`, check/incidents
- Logs: `GET /admin/api/logs/audit|api|anomalies|ip-blocks`, `POST/DELETE` ip-blocks

UI: `/settings/system`, `/settings/backups`, `/logs`, `/settings/uptime`.

### 🔄 Пользовательский сценарий (User Flow)

1. System: language, api-access, admin base path, visible sections, security; check update → preview → run (owner-sensitive).
2. Backups: create snapshot (± pushTo cloud), configure Google/Yandex/Dropbox/SFTP, restore/download/delete.
3. Uptime: targets + cron/`POST .../run` (admin Bearer).
4. Logs: audit/api/anomalies, IP blocks.

### 🤖 Инструкции для AI-агента (Action Plan)

- **Для создания структуры данных:** Перед продом — backup create. После схемы/контента — uptime target на `/api/{slug}` и `/admin/api/health`.
- **Валидация и проверки:** Update/run и часть settings — owner-only. `POST /admin/api/uptime/run` с API token из §8 → `403 Admin token required`. Restore деструктивен — только с confirm. Не вызывать `install.php?action=complete` на живой БД.
- **Best Practices:** Backup до migrate destructive и до system update. Подробности: [`docs/recovery.md`](docs/recovery.md). После смены `admin-base` обновить URL скриптов агента.

---

## Приложение A — E2E сценарий «блог»

Минимальный скрипт через admin API (после login → `$TOKEN`, `$BASE`).

```bash
# 1. Authors
AUTH_ID=$(curl -s -X POST "$BASE/admin/api/resources" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"label":"Authors","slug":"authors","settings":{"apiEnabled":true,"public":{"read":true,"create":false,"update":false,"delete":false}}}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])')

curl -s -X PUT "$BASE/admin/api/resources/$AUTH_ID/fields" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"fields":[
    {"name":"name","type":"string","label":"Name","required":true,"nullable":false,"searchable":true,"sortable":true,"config":{"maxLength":120}},
    {"name":"bio","type":"text","label":"Bio","nullable":true,"searchable":true,"config":{}}
  ]}'

curl -s -X POST "$BASE/admin/api/resources/$AUTH_ID/publish" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'

# 2. Articles
ART_ID=$(curl -s -X POST "$BASE/admin/api/resources" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"label":"Articles","slug":"articles","settings":{"apiEnabled":true,"public":{"read":true,"create":false,"update":false,"delete":false}}}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])')

curl -s -X PUT "$BASE/admin/api/resources/$ART_ID/fields" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"fields":[
    {"name":"title","type":"string","label":"Title","required":true,"nullable":false,"searchable":true,"sortable":true,"config":{"maxLength":200}},
    {"name":"slug","type":"slug","label":"Slug","required":true,"nullable":false,"unique":true,"indexed":true,"config":{"associatedWith":"title","maxLength":200}},
    {"name":"body","type":"richtext","label":"Body","required":true,"nullable":false,"config":{}},
    {"name":"cover","type":"image","label":"Cover","nullable":true,"config":{"multiple":false,"formats":[],"encodeFormat":null,"sizes":[]}},
    {"name":"author_id","type":"relation","label":"Author","nullable":true,"filterable":true,"indexed":true,
      "config":{"cardinality":"manyToOne","relatedSlug":"authors","labelField":"name"}}
  ]}'

curl -s -X POST "$BASE/admin/api/resources/$ART_ID/publish" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'

# 3. Optional: preview + workflow
curl -s -X PATCH "$BASE/admin/api/resources/$ART_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"settings":{"preview":{"url":"https://blog.example/api/preview"},"workflow":{"enabled":true}}}'

# 4. Media + entries (упрощённо — без multipart здесь; используй apiUpload / curl -F)
# curl -s -X POST "$BASE/admin/api/media" -H "Authorization: Bearer $TOKEN" -F file=@cover.jpg

curl -s -X POST "$BASE/admin/api/resources/$AUTH_ID/entries" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Ada","bio":"Editor"}'

curl -s -X POST "$BASE/admin/api/resources/$ART_ID/entries" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Hello","slug":"hello","body":"<p>Hi</p>","author_id":1}'

# 5. Frontend token
curl -s -X POST "$BASE/admin/api/tokens" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"name\":\"blog-fe\",\"grants\":[{\"resourceId\":$ART_ID,\"canRead\":true,\"canCreate\":false,\"canUpdate\":false,\"canDelete\":false},{\"resourceId\":$AUTH_ID,\"canRead\":true,\"canCreate\":false,\"canUpdate\":false,\"canDelete\":false}]}"

# 6. Webhook (Vercel-style)
curl -s -X POST "$BASE/admin/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"vercel","url":"https://api.vercel.com/v1/integrations/deploy/…","events":["entry.created","entry.updated","entry.deleted","resource.published"],"payloadMode":"empty","preset":"vercel_deploy"}'

# 7. Verify
curl -s "$BASE/api/articles?limit=1"
curl -s "$BASE/admin/api/health"
```

Готовый расширенный сид: [`scripts/seed-demo.php`](scripts/seed-demo.php).

---

## Приложение B — route → section → minRole

Из [`frontend/src/app/router.tsx`](frontend/src/app/router.tsx) + [`frontend/src/lib/rbac.ts`](frontend/src/lib/rbac.ts). Basename обычно `/admin`.

| Path | Section | minRole (nav) |
|---|---|---|
| `/` | `dashboard` | — |
| `/resources`, `/resources/new`, `/resources/:id/...` | `resources` | — |
| `/media` | `media` | `editor` |
| `/logs` | `logs` | `admin` |
| `/docs/:chapter?` | `docs` | — |
| `/changelog` | `changelog` | — |
| `/settings/tokens` | `tokens` | `admin` |
| `/settings/webhooks` | `webhooks` | `admin` |
| `/settings/inbound` | `inbound` | `admin` |
| `/settings/uptime` | `uptime` | `admin` |
| `/settings/feature-flags` | `feature-flags` | `admin` |
| `/settings/key-values` | `key-values` | `admin` |
| `/settings/translates` | `translates` | `admin` |
| `/settings/users` | `users` | `admin` |
| `/settings/account` | `account` | — (всегда) |
| `/settings/integrations` | `integrations` | `admin` |
| `/settings/backups` | `backups` | `admin` |
| `/settings/system` | `system` | `admin` |
| `/login`, `/oauth/complete`, `/install` | — | public |

Resource tabs ACL: `overview | schema | data | settings | api | hooks | export` (`RESOURCE_TABS`).

---

*Документ сгенерирован по коду HCMS. При расхождении с UI — верь `*Routes.php` и актуальным page-компонентам.*
