# Webhooks

Админка: **Settings → Webhooks** (`/admin/settings/webhooks`).  
Нужна роль с capability `settings.write` (обычно `admin`).

Исходящие HTTP POST на ваш endpoint при изменениях контента. Тело подписано **HMAC-SHA256** (`X-HCMS-Signature`). Доставка идёт **после** ответа клиенту (shutdown / `fastcgi_finish_request`).

## UI

1. **Создать** webhook: имя, URL, секрет (можно сгенерировать), события, опционально фильтр ресурса, статус.
2. Оставить `status=active`.
3. Нажать **Test** — синхронный POST с событием `webhook.test` (без ретраев).
4. Клик по имени → лог **Deliveries** (event, status, attempt, HTTP code, duration).
5. Enable/Disable без удаления; Delete — с confirm.

| поле | описание |
|------|----------|
| `name` | название в админке (≤120) |
| `url` | `http(s)://…` endpoint (≤2048) |
| `secret` | ключ HMAC; при create пустой → авто `64` hex; при edit пустой → не менять |
| `events` | один или несколько из whitelist |
| `resourceId` | `null` = все ресурсы; иначе только этот resource |
| `status` | `active` \| `disabled` |

## События

| event | когда |
|-------|--------|
| `entry.created` | create записи (admin или Content API) |
| `entry.updated` | update / restore revision |
| `entry.deleted` | delete / bulk delete |
| `resource.published` | publish ресурса (создание таблицы) |
| `webhook.test` | только кнопка Test / `POST …/test` (не выбирается в форме) |

Фильтр: webhook `active` + event ∈ `events` + (`resourceId` пуст или совпадает).

## Доставка

- Method: `POST`
- Timeout: **5s**
- Success: HTTP **2xx**
- Retries: до **3** попыток, backoff `0s / 1s / 2s` (test — 1 попытка)
- Body: JSON (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`)

### Заголовки

```http
Content-Type: application/json; charset=utf-8
X-HCMS-Event: entry.created
X-HCMS-Signature: sha256=<hmac_hex>
X-HCMS-Delivery-Id: 42
User-Agent: HCMS-Webhooks/1.0
```

Подпись: `sha256=` + `HMAC-SHA256(raw_body, secret)` в hex. Считать по **точному** сырому body, не по пересериализованному JSON.

## Payload

### `entry.created` / `entry.updated`

```json
{
  "resourceId": 12,
  "slug": "demo_articles",
  "entry": {
    "id": 42,
    "title": "Hello",
    "slug": "hello",
    "createdAt": "2026-09-07T12:00:00+00:00",
    "updatedAt": "2026-09-07T12:00:00+00:00"
  }
}
```

`entry` — полный объект записи из query layer (поля ресурса + системные).

### `entry.deleted`

```json
{
  "resourceId": 12,
  "slug": "demo_articles",
  "entryId": 42
}
```

### `resource.published`

```json
{
  "resourceId": 12,
  "resource": {
    "id": 12,
    "key": "demo_articles",
    "label": "Articles",
    "status": "published"
  }
}
```

### `webhook.test`

```json
{
  "test": true,
  "webhookId": 1,
  "resourceId": null,
  "sentAt": "2026-09-07T12:00:00+00:00"
}
```

## Admin API

Bearer admin-токен. Capability: `settings.write`.

```http
GET    /admin/api/webhooks
POST   /admin/api/webhooks
GET    /admin/api/webhooks/{id}
PATCH  /admin/api/webhooks/{id}
DELETE /admin/api/webhooks/{id}
GET    /admin/api/webhooks/{id}/deliveries
POST   /admin/api/webhooks/{id}/test
```

⚠️ В ответах CRUD **secret отдаётся целиком** (не masked) — храните аккуратно.

### Создать

```bash
curl -X POST https://api.2js.ru/admin/api/webhooks \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Production sync",
    "url": "https://example.com/hooks/hcms",
    "secret": "replace-me-with-long-secret",
    "events": ["entry.created", "entry.updated", "entry.deleted"],
    "resourceId": null,
    "status": "active"
  }'
```

```js
const res = await fetch('https://api.2js.ru/admin/api/webhooks', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
  body: JSON.stringify({
    name: 'Production sync',
    url: 'https://example.com/hooks/hcms',
    secret: 'replace-me-with-long-secret',
    events: ['entry.created', 'entry.updated', 'entry.deleted'],
    resourceId: null,
    status: 'active',
  }),
})
const { data } = await res.json()
// data.id, data.secret, …
```

Только один ресурс:

```json
{
  "name": "Articles only",
  "url": "https://example.com/hooks/articles",
  "events": ["entry.created", "entry.updated"],
  "resourceId": 12,
  "status": "active"
}
```

(`secret` можно не слать — сгенерируется.)

### Обновить / выключить

```bash
curl -X PATCH https://api.2js.ru/admin/api/webhooks/1 \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{ "status": "disabled" }'
```

Пустой `secret` в PATCH → секрет не меняется.

### Test

```bash
curl -X POST https://api.2js.ru/admin/api/webhooks/1/test \
  -H "Authorization: Bearer <admin_token>"
```

```js
const res = await fetch('https://api.2js.ru/admin/api/webhooks/1/test', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
})
const { data } = await res.json()
// { id, event: 'webhook.test', status: 'success'|'failed', responseCode, … }
```

### Deliveries

```bash
curl -s https://api.2js.ru/admin/api/webhooks/1/deliveries \
  -H "Authorization: Bearer <admin_token>"
```

Лимит по умолчанию ~50 (cap 200). Поля: `event`, `status` (`pending`/`success`/`failed`), `attempt`, `responseCode`, `durationMs`, `errorMessage`, `createdAt`.

## Приёмник: проверка подписи

Отвечайте **2xx быстро**. Тяжёлую работу — в очередь. Иначе CMS сделает до 3 ретраев.

### Node.js (Express)

```js
import express from 'express'
import crypto from 'node:crypto'

const SECRET = process.env.HCMS_WEBHOOK_SECRET
const app = express()

// нужен raw body — не parse JSON до проверки
app.post(
  '/hooks/hcms',
  express.raw({ type: 'application/json' }),
  (req, res) => {
    const body = req.body.toString('utf8')
    const expected =
      'sha256=' +
      crypto.createHmac('sha256', SECRET).update(body, 'utf8').digest('hex')
    const got = req.get('X-HCMS-Signature') || ''

    const a = Buffer.from(expected)
    const b = Buffer.from(got)
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
      return res.status(401).send('invalid signature')
    }

    const event = req.get('X-HCMS-Event')
    const deliveryId = req.get('X-HCMS-Delivery-Id')
    const payload = JSON.parse(body)

    // ack сразу
    res.status(200).json({ ok: true })

    switch (event) {
      case 'entry.created':
      case 'entry.updated':
        // payload.slug, payload.entry
        break
      case 'entry.deleted':
        // payload.entryId
        break
      case 'resource.published':
        // payload.resource
        break
      case 'webhook.test':
        break
    }

    console.log('delivery', deliveryId, event)
  },
)

app.listen(3000)
```

### PHP

```php
<?php
$secret = getenv('HCMS_WEBHOOK_SECRET');
$body = file_get_contents('php://input');
$got = $_SERVER['HTTP_X_HCMS_SIGNATURE'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

if (!hash_equals($expected, $got)) {
    http_response_code(401);
    exit('invalid signature');
}

$event = $_SERVER['HTTP_X_HCMS_EVENT'] ?? '';
$payload = json_decode($body, true);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

// дальше — очередь / обработка $event + $payload
```

### Локальный debug (webhook.site / ngrok)

1. Поднимите публичный URL на localhost (`ngrok http 3000`).
2. Создайте webhook с этим URL и нужными events.
3. **Test** в админке → смотрите headers + body.
4. Создайте/обновите entry в ресурсе → `entry.*`.

## Типичные сценарии

**Инвалидация кэша сайта** — `entry.updated` + `entry.deleted` на конкретный `resourceId`, в handler сбросить CDN/ISR по `slug`/`entry.id`.

**Поиск / индекс** — `entry.created|updated` upsert в Elasticsearch/Meilisearch; `entry.deleted` — delete by `entryId`.

**Синк в другую систему** — все `entry.*` без фильтра ресурса; идемпотентность по `X-HCMS-Delivery-Id` + `entry.id`.

## Ошибки Admin API

| код | HTTP | когда |
|-----|------|--------|
| `VALIDATION_ERROR` | 422 | пустой name/url/events, битый URL, неизвестный event/resource |
| `UNAUTHORIZED` | 401 | нет admin-токена |
| `FORBIDDEN` | 403 | нет `settings.write` |
| `NOT_FOUND` | 404 | неизвестный `{id}` |

## Audit

В audit log: `webhook.created` / `updated` / `deleted` / `tested`.
