# Email (настройки почты)

Админка: **Settings → Integrations** (`/admin/settings/integrations`).

Провайдеры: `resend` | `postmark` | `mailgun`.  
Ключи хранятся в `cms_settings` (`integrations.email`), в API отдаются только маски.

Перед отправкой нужно: `enabled=true`, API-ключ активного провайдера, `fromEmail`.  
Для Mailgun ещё `mailgunDomain` (`us` | `eu`).

## UI

1. Выбери провайдера и включи отправку.
2. Укажи `fromEmail` / `fromName`, вставь API-ключ (пустое поле = не менять).
3. Mailgun: домен + регион.
4. Сохрани → **Отправить тест** на свой адрес.
5. Создай API-токен с **Email grant** (`integrationGrants`).
6. Шли письма на публичные `POST /api/integrations/email/...`.

Playground на той же странице: path + Bearer API-токен + JSON body.

## Admin API

Bearer admin-токен.

```http
GET  /admin/api/integrations/email
PUT  /admin/api/integrations/email
POST /admin/api/integrations/email/test
```

### Сохранить настройки (Resend)

```bash
curl -X PUT https://api.2js.ru/admin/api/integrations/email \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "resend",
    "enabled": true,
    "fromEmail": "noreply@example.com",
    "fromName": "HCMS",
    "apiKey": "re_xxxxxxxx"
  }'
```

```js
await fetch('https://api.2js.ru/admin/api/integrations/email', {
  method: 'PUT',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
  body: JSON.stringify({
    provider: 'resend',
    enabled: true,
    fromEmail: 'noreply@example.com',
    fromName: 'HCMS',
    apiKey: 're_xxxxxxxx',
  }),
})
```

Ответ — публичный конфиг без сырого ключа: `apiKeyConfigured`, `apiKeyMasked`, `providers.{resend|postmark|mailgun}`.

### Mailgun

```json
{
  "provider": "mailgun",
  "enabled": true,
  "fromEmail": "noreply@mg.example.com",
  "fromName": "HCMS",
  "apiKey": "key-xxxxxxxx",
  "mailgunDomain": "mg.example.com",
  "mailgunRegion": "eu"
}
```

`apiKey` пустой / отсутствует → ключ текущего провайдера не трогается.

### Тестовое письмо

```bash
curl -X POST https://api.2js.ru/admin/api/integrations/email/test \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{ "to": "you@example.com" }'
```

```js
const res = await fetch('https://api.2js.ru/admin/api/integrations/email/test', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
  body: JSON.stringify({ to: 'you@example.com' }),
})
const { data } = await res.json()
// { ok: true, to: 'you@example.com' }
```

## Кастомные email API

Именованные эндпоинты с шаблонами `subject` / `html` / `text`.  
Slug: `^[a-z][a-z0-9_-]{0,62}$`, зарезервирован `send`.

```http
GET    /admin/api/integrations/email/apis
POST   /admin/api/integrations/email/apis
GET    /admin/api/integrations/email/apis/{id}
PATCH  /admin/api/integrations/email/apis/{id}
DELETE /admin/api/integrations/email/apis/{id}
```

### Создать шаблон welcome

```js
await fetch('https://api.2js.ru/admin/api/integrations/email/apis', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
  body: JSON.stringify({
    slug: 'welcome',
    label: 'Welcome letter',
    enabled: true,
    defaults: {
      subject: 'Welcome, {{name}}!',
      html: '<p>Hi {{name}}, welcome to {{app}}.</p>',
      text: 'Hi {{name}}, welcome to {{app}}.',
    },
    settings: { allowFromOverride: false },
  }),
})
```

Публичный path: `/api/integrations/email/welcome` (также `/api/v1/...`).

Плейсхолдеры `{{var}}` подставляются из `vars` в теле запроса.

## Публичный send API

Только с Bearer. Нужен **API-токен** с grant `email` (`canUse: true`) либо admin-токен.

```http
POST /api/integrations/email/send
POST /api/integrations/email/{slug}
```

Тело:

| поле | обязательность | описание |
|------|----------------|----------|
| `to` | да | получатель |
| `subject` | да* | тема (*или default у кастомного API) |
| `html` / `text` | да* | хотя бы одно (*или default) |
| `fromEmail` / `fromName` | нет | override, если разрешён |
| `vars` | нет | `{ name: "Ada" }` для `{{name}}` |

### Универсальная отправка

```js
const res = await fetch('https://api.2js.ru/api/integrations/email/send', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <api_token>',
  },
  body: JSON.stringify({
    to: 'user@example.com',
    subject: 'Hello',
    html: '<p>Hello from HCMS</p>',
    text: 'Hello from HCMS',
  }),
})
const { data } = await res.json()
// { ok: true, provider: 'resend', to: 'user@example.com' }
```

```bash
curl -X POST https://api.2js.ru/api/integrations/email/send \
  -H "Authorization: Bearer <api_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "user@example.com",
    "subject": "Hello",
    "html": "<p>Hello from HCMS</p>",
    "text": "Hello from HCMS"
  }'
```

### Кастомный API + vars

```js
await fetch('https://api.2js.ru/api/integrations/email/welcome', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <api_token>',
  },
  body: JSON.stringify({
    to: 'user@example.com',
    vars: { name: 'Ada', app: 'HCMS' },
  }),
})
```

Можно переопределить `subject` / `html` / `text` в запросе — они тоже рендерятся через `vars`.

## API-токен с Email grant

```http
POST /admin/api/tokens
```

```json
{
  "name": "mailer",
  "grants": [],
  "integrationGrants": [{ "integrationKey": "email", "canUse": true }]
}
```

```js
const res = await fetch('https://api.2js.ru/admin/api/tokens', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer <admin_token>',
  },
  body: JSON.stringify({
    name: 'mailer',
    grants: [],
    integrationGrants: [{ integrationKey: 'email', canUse: true }],
  }),
})
const { data } = await res.json()
// data.token — показать один раз
```

Без Email grant → `403 Forbidden`. Без Bearer → `401`.

## Ошибки

| код | HTTP | когда |
|-----|------|--------|
| `VALIDATION_ERROR` | 422 | выключено / нет ключа / нет from / битый email / нет subject|body |
| `UNAUTHORIZED` | 401 | нет токена |
| `FORBIDDEN` | 403 | нет Email grant |
| `PROVIDER_ERROR` | 502 | отказ Resend/Postmark/Mailgun |
| `NOT_FOUND` | 404 | неизвестный/выключенный `{slug}` |

## OpenAPI

Эндпоинты попадают в `/api/openapi.json` (tag **Integrations**) и в Swagger `/api/docs`.
