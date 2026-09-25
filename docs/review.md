# HCMS — module review for agents

Карта кодовой базы для агентов: что где лежит, за что отвечает, как связано.  
Операционный runbook (curl-сценарии): [`../AGENTS.md`](../AGENTS.md).  
Архитектурная ось ContentType / Field / Resource: [`architecture.md`](architecture.md).

**Стек:** PHP 8.3+ (PSR-4 `Cms\` → `src/`), MySQL/MariaDB, React+Vite админка (`frontend/` → `public/admin/`), без фреймворка на бэке (свой `Kernel` + `Router`).

**Слои (Deptrac):** `Http` → `Api` / `Domain` → `Database` / `Core`. См. [`deptrac.yaml`](../deptrac.yaml).

```text
HTTP (Controllers + Routes)
        │
   ┌────┴────┐
 Api      Domain services (Auth, Resources, Fields, …)
   │         │
   └────┬────┘
     Database + Core
```

---

## 0. Точки входа и обвязка

| Путь | Роль |
|---|---|
| [`public/index.php`](../public/index.php) + [`public/router.php`](../public/router.php) | Front controller / built-in PHP server |
| [`src/Http/Kernel.php`](../src/Http/Kernel.php) | DI-lite, wiring всех сервисов, rate-limit, IP blocks, роуты |
| [`cms`](../cms) | CLI: `status`, `migrate`, `cache:clear`, `uptime:check`, `backup:*` |
| [`install.php`](../install.php) | Мастер + JSON API установки |
| [`packages/sdk`](../packages/sdk) | `@hcms/sdk` + `hcms-types` CLI |
| [`examples/react`](../examples/react) | Демо-консьюмер public API |
| [`scripts/`](../scripts/) | release, verify-tree, seed-demo, restore, pending migrations |
| [`database/migrations/`](../database/migrations/) | SQL foundation `cms_*` (001–021+) |
| [`landing/`](../landing/) | Сайт 2js.ru (downloads/installs counters) — не ядро CMS |

---

## 1. Core — `src/Core/`

Инфраструктура без бизнес-домена.

| Класс | Назначение |
|---|---|
| `Config` / `Env` / `EnvFile` | Чтение `.env`, `APP_SECRET`, URL, admin base |
| `Paths` | Корень, storage, cache, publicDir |
| `Settings` | Key-value runtime settings из `cms_settings` |
| `FileCache` / `MetadataCache` | Файловый кэш; OpenAPI/метаданные схемы |
| `Version` / `Locale` / `AdminBase` / `PhpCli` | Версия продукта, локаль UI, префикс админки |
| `Core\Exception\*` | `NotFound`, `ValidationFailed`, `Unauthorized`, … → map в `ExceptionHandler` |

**Когда трогать:** пути деплоя, секреты, кэш схемы, новые глобальные settings-ключи.

---

## 2. Database — `src/Database/`

| Класс | Назначение |
|---|---|
| `Connection` | PDO wrapper |
| `MigrationService` | `res_{slug}` create/alter; system cols (actors, localization, workflow); **M2M join tables** `res_{slug}_{field}` |
| `SchemaDiff` / `ColumnDefinition` | Diff желаемых колонок vs `SHOW COLUMNS` |
| `PendingMigrations` | Очередь SQL из `database/migrations` при апдейте |
| `MediaColumnRepair` | Чинит легаси media-колонки |

**Инвариант:** правка `cms_fields` без `publish`/`migrate` = схема есть, колонок нет.

---

## 3. Http — `src/Http/`

Транспортный слой.

| Часть | Назначение |
|---|---|
| `Kernel` | Bootstrap, middleware-ish (IP ban, rate limit, CORS/ApiAccess), регистрация роутов |
| `Router` / `Route` / `Request` / `Response` | Маршрутизация и HTTP-примитивы |
| `ExceptionHandler` | Domain exceptions → JSON error |
| `ClientIp` / `OriginMatcher` / `ApiAccess` | IP за прокси, CORS/origins, access policy |
| `*Routes.php` | Таблицы путей: Auth, Admin resources, Public API, System, Uptime, KeyValues, Feature/Translates |
| `Controllers/*` | Тонкие адаптеры → Domain/Api |

**Public API** (`PublicApiRoutes` + `PublicApiController` / `PreviewController` / `PublicInboundController`):

- `/api/{slug}` и `/api/v1/{slug}` — один API
- `GET /api/preview/{token}` — live preview (HMAC)
- `POST /api/inbound/{slug}` — inbound hooks

**Admin API** — префикс `/admin/api/…` (Bearer admin).

---

## 4. Api — `src/Api/`

Движок данных поверх `res_*`.

| Класс | Назначение |
|---|---|
| `QueryEngine` | list/find/create/patch/delete; filters/search/sort; custom APIs + joins; **M2M sync**; system fields `locale` / `translation_group_id` / `status`; public workflow filter |
| `PayloadValidator` | Валидация/cast payload (в т.ч. `blocks`, relation ids) |
| `SearchTokenizer` | Токены для `?search=` |

**Опции QueryEngine:** `public => true` (публичный контур), `actorUserId` (created_by/updated_by).

---

## 5. Content — `src/Content/`

| Класс | Назначение |
|---|---|
| `ContentTypeRepository` | CRUD content types (`cms_content_types`) |
| `EntryService` | Admin facade над QueryEngine + actors |
| `EntryRevisionService` | Снапшоты/restore (`cms_entry_revisions`) |
| `Slug` / `UrlSlug` | Валидация slug / генерация URL-slug из associated field |

Связка: ContentType 1:1 Resource при создании ресурса (см. ResourceService).

---

## 6. Fields — `src/Fields/`

Схема полей = source of truth.

| Класс | Назначение |
|---|---|
| `FieldTypeRegistry` | Built-in types + discovery (`extensions/`, composer `extra.hcms.field-types`) |
| `FieldService` / `FieldRepository` / `FieldSpec` | CRUD `cms_fields`, нормализация spec |
| `SqlTypeMapper` | Field → SQL column (null для oneToMany / manyToMany) |
| `Types/*` | Реализации типов |

**Типы:** `string`, `text`, `richtext`, `integer`, `float`, `boolean`, `date`, `datetime`, `email`, `url`, `uuid`, `json`, `blocks`, `enum`, `slug`, `image`, `file`, `relation`.

**Relation cardinality:** `manyToOne`, `oneToOne` (FK+UNIQUE), `oneToMany` (виртуальный), `manyToMany` (join table).

**Blocks:** JSON `[{type, …}]` + `config.components` map → nested field specs.

---

## 7. Resources — `src/Resources/`

Публикация схемы в API.

| Класс | Назначение |
|---|---|
| `ResourceService` / `ResourceRepository` | CRUD ресурсов, **normalizeSettings** (public, spam, cache, preview, localization, workflow, …) |
| `ResourceApiService` / `ResourceApiRepository` | Named custom APIs (projection, methods, joins) |
| `ResourcePackageService` | Export/import пакета схемы (+ data/media) |
| `EntryImportExportService` | CSV/JSON bulk entries |

**Статусы ресурса:** `draft` → `published` → `archived`. Публичный API только `published` + `settings.apiEnabled`.

**Важные settings (нормализуются всегда):**

- `public.*`, `spam.*`, `cache.maxAge`, `preview.url`
- `localization.enabled`, `workflow.enabled`
- `softDelete` / `deleteStrategy`, list columns, pagination/search/…

---

## 8. Auth — `src/Auth/`

| Класс | Назначение |
|---|---|
| `TokenService` / `AuthContext` | Bearer admin/api tokens (`cms_tokens`) |
| `ApiTokenService` / `TokenGrantRepository` / `TokenPolicy*` | API-токены, гранты на ресурсы, IP/origin policies |
| `LoginGuard` / `Password` / `RolePolicy` | Логин, хэш пароля, RBAC (`owner|admin|editor|viewer`), capability `entries.publish` |
| `UsersService` / `UsersRepository` | Пользователи |
| `UserAclGuard` / `User*GrantRepository` | Section + resource ACL |
| `OAuthService` / identities | Google + Telegram + generic OIDC; link/unlink |
| `RateLimiter` + stores | Sliding window (memory / DB) |

Admin token обходит resource grants; API token — нет.

---

## 9. Security — `src/Security/`

| Класс | Назначение |
|---|---|
| `SpamGuard` / `SpamRejected` | Anti-spam на anon create/update/delete; нейтральный текст наружу |
| `AutoBlock` | Автобан IP по audit (`auth.login_blocked`, `security.spam_rejected`) |
| `IpBlockRepository` / `IpMatcher` | Блокировки IP + **CIDR** |
| `CaptchaVerifier` | Captcha для логина/спама |
| `Totp` | MFA |
| `HmacSignature` | Общая HMAC-подпись (hooks/webhooks) |
| `RateLimitExceeded` | 429 |

Порядок anti-spam backlog: см. [`improvements.md`](improvements.md) (K–N закрыты).

---

## 10. Preview — `src/Preview/`

| Класс | Назначение |
|---|---|
| `PreviewTokenService` | HMAC short-TTL token (`APP_SECRET`); issue/parse; URL template `{token}/{slug}/{id}` |

**Поток:** admin `POST …/entries/{id}/preview` → frontend URL → site `GET /api/preview/{token}` → entry JSON (`no-store`).  
Нужен `settings.preview.url`.

---

## 11. Media — `src/Media/`

| Класс | Назначение |
|---|---|
| `MediaService` | Upload, storage (`storage/uploads`), warm static `public/media/` |
| `ImageProcessor` | Resize/crop, encode WebP/JPEG/PNG, sizes |
| `MediaValue` / `MediaFieldConfig` | JSON-форма поля image/file в entries |
| `MediaRefService` | Связи media↔entries |
| `MediaAccess` / `MediaAclScope` | ACL на медиа |

Public: `GET /media/{id}` / pretty `…/{filename}` + rate limit.

---

## 12. Events — `src/Events/`

In-process domain bus (без внешнего брокера).

| Класс | Назначение |
|---|---|
| `EventBus` | `listen` / `dispatch` / `dispatchAfterResponse` |

Контроллеры (entries, public API, publish) диспатчат сюда; Kernel регистрирует listener → `WebhookDispatcher::dispatch`.

---

## 13. Webhooks — `src/Webhooks/`

Исходящие HMAC-хуки после ответа (через Event Bus).

| Класс | Назначение |
|---|---|
| `WebhookService` / `WebhookRepository` | CRUD + deliveries + presets |
| `WebhookPresets` | vercel/netlify/cloudflare/fastly + payload modes |
| `WebhookDispatcher` | HTTP deliver + retries |

UI: Settings → Webhooks. Дока: [`webhooks.md`](webhooks.md).

---

## 14. Hooks — `src/Hooks/`

Sync request hooks + inbound endpoints (логика на внешнем URL).

| Класс | Назначение |
|---|---|
| `ResourceHookService` | before/after create (и фазы) к ресурсу |
| `InboundEndpointService` | `POST /api/inbound/{slug}` → external + optional create |
| `HookClient` / `HookDeliveryRepository` | HTTP + лог доставок |
| `RequestMeta` | ip/ua/source для webhooks/hooks |

Дока: [`hooks.md`](hooks.md).

---

## 15. Audit — `src/Audit/`

| Класс | Назначение |
|---|---|
| `AuditLogger` / `AuditRepository` | `cms_audit_logs` (auth, schema, spam, preview, …) |
| `ApiLogRepository` | `cms_api_logs` (HTTP hits, anomalies) |

UI: Logs (audit / api / security / IP blocks).

---

## 16. OpenApi — `src/OpenApi/`

| Класс | Назначение |
|---|---|
| `OpenApiGenerator` | `GET /api/openapi.json` (+ custom APIs, integrations) |

UI: `/api/docs` (Swagger). Клиентская типизация: `packages/sdk` `hcms-types` поверх этого JSON. Дока: [`openapi.md`](openapi.md).

---

## 16a. GraphQL — `src/GraphQL/` (opt-in)

Тонкий слой над `QueryEngine` + тот же auth, что public REST. Default выкл (`api.graphql.enabled`).

| Класс | Назначение |
|---|---|
| `GraphQLSchemaFactory` | Executable schema из published + `apiEnabled` resources |
| `GraphqlSettings` | `api.graphql.enabled` / `api.graphql.playground` |
| `JsonType` / `TypeNames` | JSON scalar + naming helpers |
| `GraphqlController` | `POST` execute; optional `GET` GraphiQL |
| `PublicApiAuthorizer` (`src/Api/`) | Shared REST/GraphQL authorize |

Endpoints: `/api/graphql`, `/api/v1/graphql`. Admin: System → GraphQL; сайдбар GraphQL. Дока: [`graphql.md`](graphql.md), UI: [`ADMIN_UI_AGENT_GUIDE.md`](../ADMIN_UI_AGENT_GUIDE.md#13-graphql-opt-in).

---

## 17. Install — `src/Install/`

| Класс | Назначение |
|---|---|
| `Installer` | `action=status|test-connection|complete|…`; пишет `.env`, дропает `cms_*`, миграции, owner, lock |
| `ReleaseDownloader` | Zip с GitHub Releases |
| `InstallTelemetry` | Анонимный ping после успешной установки |

**Опасно:** `complete` на живой инсталляции сносит `cms_*`.

---

## 18. System — `src/System/`

Обновления продукта и служебное.

| Класс | Назначение |
|---|---|
| `UpdateService` / `LatestRelease` / `UpdateJournal` | Check/apply release zip |
| `AdminUiPublisher` | Выкладка собранной админки |
| `ChangelogRepository` / `ReleaseNotes` | CHANGELOG для UI |
| `TreeVerifier` | `scripts/verify-tree.php` — дерево способно загрузиться |

---

## 18a. Backup — `src/Backup/`

Data snapshots (БД `cms_*`/`res_*` + `storage/uploads`), отдельно от code-бэкапов апдейта.

| Класс | Назначение |
|---|---|
| `DataBackupService` | create / list / restore / push / retention |
| `SqlDumper` / `SqlRestorer` | PDO SQL dump/restore |
| `BackupRemoteSettings` | `cms_settings` key `backups.remote` |
| `BackupCloudOAuthService` | OAuth connect Google / Yandex / Dropbox |
| `RemoteDriver` + drivers | Google Drive, Yandex Disk, Dropbox, SFTP (curl) |
| API / UI | `/admin/api/backups*` · Settings → Backups |
| CLI | `php cms backup:create\|list\|restore\|push` |

Дока: [`recovery.md`](recovery.md#data-backups-бд--media).

---

## 19. Mail / Integrations

**Mail (`src/Mail/`):** Resend / Postmark / Mailgun transports, `EmailIntegration`, `Mailer`.  
**Integrations (`src/Integrations/`):** named integration API endpoints (email send и т.п.), OpenAPI-схемы.

UI: Settings → Integrations. Дока: [`integrations-email.md`](integrations-email.md).

---

## 20. FeatureFlags — `src/FeatureFlags/`

Remote config / A–B (`cms_feature_flags`). Public GET с Cache-Control/ETag; admin CRUD.

---

## 21. Translates — `src/Translates/`

**UI-/client string i18n** (не content localization).

| Класс | Назначение |
|---|---|
| `LocaleRepository` | `cms_locales` (default locale) |
| `TranslationRepository` / `TranslationService` | Key → locale strings |
| Public | `GET /api/translates?locale=` + fallback |

Content localization (entry rows) — отдельная ось в Resource settings + QueryEngine/MigrationService.

---

## 22. KeyValues — `src/KeyValues/`

Простой KV store для клиентов (`cms_key_values`), public GET + admin CRUD, cache headers.

---

## 23. Uptime — `src/Uptime/`

Мониторинг HTTP-целей: targets, probes, incidents, heartbeat, soft cron через `/admin/api/health` или `php cms uptime:check`.

Дока: [`development.md`](development.md) (uptime cron).

---

## 24. Frontend — `frontend/src/`

React SPA → build в `public/admin/`.

| Область | Назначение |
|---|---|
| `features/schema-builder` | Редактор полей / relation / blocks config |
| `features/resources` | Entries CRUD, settings (cache/preview/localization/workflow), custom APIs, revisions, preview/status actions |
| `features/form-renderer` | Динамическая форма по схеме |
| `features/data-table` | Список entries, колонки, filters |
| `features/media` | Медиатека |
| `features/webhooks` / `inbound` / `uptime` / `logs` / `docs` / `auth` / `account` / `install` | Соответствующие экраны |
| `pages/*` | Dashboard, Users, Tokens, FeatureFlags, Translates, KeyValues, Integrations, System |
| `i18n` | en/ru/ar админки (`dir` + logical CSS shell) |
| `lib/api.ts` | Fetch + Bearer + field errors |
| `components/ui` | Design system (CSS Modules + `--hcms-*`) |

Правила UI: form validation через `error.fields` + `FieldError` (см. `.cursor/rules/form-field-validation.mdc`).

---

## 25. SDK — `packages/sdk`

- `createClient({ baseUrl, token })` — list/get/create/update/remove/preview/openapi
- bin `hcms-types` — fetch OpenAPI → TS (опционально `openapi-typescript`)

---

## 26. Таблицы `cms_*` (ориентир)

Foundation + фичи по миграциям `001`…`021`:

| Группа | Таблицы (примеры) |
|---|---|
| Core meta | `cms_content_types`, `cms_fields`, `cms_resources`, `cms_schema_revisions`, `cms_settings` |
| Auth | `cms_users`, `cms_tokens`, `cms_token_grants`, `cms_token_policies`, `cms_user_identities`, ACL grants |
| Data extras | `cms_entry_revisions`, `cms_resource_apis` |
| Media | media + variants + refs + ACL |
| Ext | `cms_webhooks`, deliveries; hooks/inbound; feature_flags; locales/translations; key_values; uptime_* |
| Security | `cms_ip_blocks`, rate_limits, audit/api logs |

Динамика: `res_{slug}` (+ `res_{slug}_{m2mField}`).

---

## 27. Типовые потоки (для навигации по коду)

### Создать схему

`ResourceController` → `ResourceService` → `FieldController`/`FieldService` → `MigrationController`/`MigrationService` → `QueryEngine` / OpenAPI invalidate.

### Public CRUD

`PublicApiController` → authorize (public flags / API token grants) → SpamGuard (anon write) → `QueryEngine` → `EventBus` → Webhooks.

### Preview

`EntriesController::preview` → `PreviewTokenService` → site → `PreviewController::resolve` → `QueryEngine::find`.

### Workflow

`settings.workflow.enabled` + migrate `status` → admin `POST …/status` (`RolePolicy::entries.publish` для `published`) → public list/find только `published`.

### Localization (content)

`settings.localization.enabled` + migrate `locale`/`translation_group_id` → create/list с `?locale=` (hard filter; без sibling-fallback). Без `?locale` → только default из `cms_locales`.

---

## 28. Документация (куда смотреть дальше)

| Файл | Тема |
|---|---|
| [`architecture.md`](architecture.md) | Модель сущностей |
| [`api.md`](api.md) | Admin/public API, cache, preview, backups, Event Bus, ACL |
| [`schema.md`](schema.md) | Поля, миграции |
| [`resources.md`](resources.md) | Settings, custom APIs |
| [`authentication.md`](authentication.md) / [`permissions.md`](permissions.md) | Auth/RBAC + field/row ACL |
| [`anti-spam.md`](anti-spam.md) | Spam / IP |
| [`webhooks.md`](webhooks.md) / [`hooks.md`](hooks.md) | Outbound presets / inbound |
| [`recovery.md`](recovery.md) | Update restore + data backups |
| [`openapi.md`](openapi.md) | Генератор |
| [`deployment.md`](deployment.md) | Релиз, CDN proxy |
| [`improvements.md`](improvements.md) | Инженерный backlog |
| [`roadmap-product.md`](roadmap-product.md) / [`implementation-plan.md`](implementation-plan.md) | Product gaps / фазы |
| [`../AGENTS.md`](../AGENTS.md) | Runbook агента |

---

## 29. Правила при правках (кратко)

1. Settings — только через `ResourceService::normalizeSettings` (+ TS `ResourceSettings`).
2. Новые system cols на `res_*` — через `MigrationService` + opt-in settings; default public behaviour не ломать.
3. Новые field types — класс `FieldType` + `extensions/<name>/manifest.php` (или composer `extra.hcms.field-types`); UI подхватит через `GET /admin/api/field-types`.
4. Секреты только в `.env`; не трогать `install complete` на живой БД.
5. После PHP/TS — `composer qa` / `npm run qa` по затронутому контуру.
6. Релиз — только после зелёного CI ([pre-release-ci-loop](../.cursor/rules/pre-release-ci-loop.mdc)).
