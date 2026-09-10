# Hooks & Inbound endpoints

Админка:

- **Resource → Hooks** — sync HTTP до/после `create`
- **Settings → Inbound** — именованные `POST /api/inbound/{slug}`

Логика всегда на **внешнем URL**. В админке только конфиг (URL, secret, phase). Подпись та же, что у webhooks: `X-HCMS-Signature: sha256=<hmac>`.

## Resource hooks

| поле | описание |
|------|----------|
| `name` | название (≤120) |
| `phase` | `before_create` \| `after_create` |
| `url` | `http(s)://…` |
| `secret` | HMAC key |
| `timeoutMs` | 100…30000 (default 3000) |
| `onFailure` | `reject` \| `continue` |
| `status` | `active` \| `disabled` |

### `before_create` (sync, до INSERT)

HCMS → ваш handler:

```json
{
  "resourceId": 12,
  "slug": "leads",
  "phase": "before_create",
  "payload": { "email": "a@x.com", "name": "Ann" },
  "meta": {
    "ip": "1.2.3.4",
    "userAgent": "Mozilla/…",
    "origin": "https://site.example",
    "source": "public"
  }
}
```

Ответ:

```json
{ "accept": true, "payload": { "email": "a@x.com", "name": "Ann", "score": 12 } }
```

```json
{ "accept": false, "error": { "code": "DUPLICATE", "message": "Already submitted" } }
```

Reject → клиенту `422` с `error.code` / `error.message` из ответа (без URL handler’а).

Если HTTP 5xx/timeout и `onFailure=reject` → `422 HOOK_FAILED`. При `continue` — пишем исходный payload.

### `after_create` (sync, после INSERT, до ответа клиенту)

```json
{
  "resourceId": 12,
  "slug": "leads",
  "phase": "after_create",
  "entry": { "id": 42, "email": "a@x.com" },
  "payload": { "id": 42, "email": "a@x.com" },
  "meta": { "…": "…" }
}
```

Ответ:

```json
{ "accept": true, "response": { "ticketId": "T-9001" } }
```

Клиент получает:

```json
{ "data": { "id": 42, "…" }, "hook": { "ticketId": "T-9001" } }
```

Async side-effects (Telegram, CRM) по-прежнему через [Webhooks](webhooks.md) после ответа.

### Admin API

```http
GET    /admin/api/resources/{id}/hooks
POST   /admin/api/resources/{id}/hooks
GET    /admin/api/resources/{id}/hooks/{hookId}
PATCH  /admin/api/resources/{id}/hooks/{hookId}
DELETE /admin/api/resources/{id}/hooks/{hookId}
GET    /admin/api/resources/{id}/hooks/{hookId}/deliveries
POST   /admin/api/resources/{id}/hooks/{hookId}/test
```

Capability: `schema.write` (часть resource ACL, tab `hooks`).

## Inbound endpoints

Лёгкое «произвольное API»: slug в админке → публичный POST → sync forward на ваш `targetUrl` → опционально INSERT в ресурс.

| поле | описание |
|------|----------|
| `slug` | `^[a-z][a-z0-9_-]{0,62}$` → `POST /api/inbound/{slug}` |
| `label` | имя в UI |
| `targetUrl` | ваш handler |
| `secret` | HMAC |
| `persistResourceId` | `null` = только forward; иначе published resource |
| `fieldMap` | `{ "inKey": "resourceField" }` или `null` (as-is) |
| `timeoutMs` | default 5000 |
| `onFailure` | `reject` \| `continue` |
| `enabled` | bool |

### Flow

```text
POST /api/inbound/contact
  → (optional) SpamGuard от persist-ресурса
  → sync POST targetUrl (HMAC, phase=inbound)
  → accept? mutate payload
  → optional fieldMap + resource before_create hooks + INSERT
  → after_create hooks + async webhook entry.created (meta.source=inbound)
  → 200/201
```

Forward-only ответ:

```json
{ "data": { "accepted": true, "hook": { "…": "…" } } }
```

С persist — как обычный create (`data` = entry, опционально `hook`).

Handler request:

```json
{
  "endpointId": 1,
  "slug": "contact",
  "phase": "inbound",
  "payload": { "name": "Ann", "email": "a@x.com" },
  "meta": { "ip": "…", "userAgent": "…", "origin": "…", "source": "inbound" }
}
```

### Admin API

```http
GET    /admin/api/inbound-endpoints
POST   /admin/api/inbound-endpoints
GET    /admin/api/inbound-endpoints/{id}
PATCH  /admin/api/inbound-endpoints/{id}
DELETE /admin/api/inbound-endpoints/{id}
GET    /admin/api/inbound-endpoints/{id}/deliveries
POST   /admin/api/inbound-endpoints/{id}/test
```

ACL section: `inbound` (capability `settings.write`, роль `admin`).

## Verify signature (Node)

```js
import crypto from 'node:crypto'

export function verify(rawBody, secret, header) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex')
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(header))
}
```

Считать HMAC по **точному** сырому body, не по пересериализованному JSON.
