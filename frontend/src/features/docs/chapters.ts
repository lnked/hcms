import type { Locale } from '@/i18n'

export const CHAPTER_IDS = [
  'overview',
  'connection',
  'tokens',
  'resources',
  'crud',
  'custom-apis',
  'webhooks',
  'limits',
  'quickstart',
] as const

export type ChapterId = (typeof CHAPTER_IDS)[number]

export const DEFAULT_CHAPTER: ChapterId = 'overview'

export function isChapterId(value: string | undefined): value is ChapterId {
  return value !== undefined && (CHAPTER_IDS as readonly string[]).includes(value)
}

export interface DocLink {
  label: string
  href: string
  external?: boolean
}

export interface DocCodeSample {
  language: 'bash' | 'js' | 'http'
  label?: string
  code: string
}

export interface DocSection {
  heading?: string
  paragraphs?: string[]
  samples?: DocCodeSample[]
  links?: DocLink[]
}

export interface Chapter {
  id: ChapterId
  title: string
  sections: DocSection[]
}

const en: Chapter[] = [
  {
    id: 'overview',
    title: 'Overview',
    sections: [
      {
        paragraphs: [
          'Headless CMS exposes two HTTP surfaces. The Admin API powers the control panel. The Content API is what your site or app calls to read and write published resources.',
          'Content routes under /api and /api/v1 are equivalent — pick one base path and stick to it.',
        ],
      },
      {
        heading: 'Surfaces',
        paragraphs: [
          'Admin API: /admin/api/* — requires an admin Bearer token from login.',
          'Content API: /api/{slug} or /api/v1/{slug} — requires an API token (or public flags on the resource).',
          'Interactive OpenAPI lives at /api/docs; the machine-readable schema is /api/openapi.json.',
        ],
        links: [
          { label: 'Open Swagger', href: '/api/docs', external: true },
          { label: 'OpenAPI JSON', href: '/api/openapi.json', external: true },
        ],
      },
    ],
  },
  {
    id: 'connection',
    title: 'Connecting to the API',
    sections: [
      {
        paragraphs: [
          'Base URL is your APP_URL from the server environment (for example https://cms.example.com). All Content API paths are relative to that origin.',
          'Authenticate with a single header on every request:',
        ],
        samples: [
          {
            language: 'http',
            label: 'Auth header',
            code: 'Authorization: Bearer <token>',
          },
        ],
      },
      {
        heading: 'CORS',
        paragraphs: [
          'Browser clients need an allowed Origin. Configure this under Settings → System (API access): unrestricted mode or an allowlist of origins.',
          'Server-to-server calls without an Origin header are not blocked by the CORS gate.',
          'Allowed request headers: Authorization, Content-Type, Accept.',
        ],
        links: [{ label: 'Settings → System', href: '/settings/system' }],
      },
      {
        heading: 'Apache note',
        paragraphs: [
          'On some Apache/CGI hosts the Authorization header is stripped before PHP. The installer writes an .htaccess rewrite into HTTP_AUTHORIZATION. If Bearer auth fails after deploy, confirm that rewrite is present.',
        ],
      },
      {
        heading: 'Minimal fetch',
        samples: [
          {
            language: 'js',
            label: 'JavaScript',
            code: `const base = 'https://cms.example.com'
const token = 'YOUR_API_TOKEN'

const res = await fetch(\`\${base}/api/demo_articles?page=1&limit=20\`, {
  headers: {
    Authorization: \`Bearer \${token}\`,
    Accept: 'application/json',
  },
})
const json = await res.json()`,
          },
        ],
      },
    ],
  },
  {
    id: 'tokens',
    title: 'Tokens and grants',
    sections: [
      {
        paragraphs: [
          'Create API tokens in Settings → Tokens. The plaintext secret is shown once — copy it immediately.',
          'Admin tokens (from login) bypass resource grants. API tokens only access what their grants allow.',
        ],
        links: [{ label: 'Settings → Tokens', href: '/settings/tokens' }],
      },
      {
        heading: 'Grants',
        paragraphs: [
          'Each grant targets a resource (or null for global) with canRead / canCreate / canUpdate / canDelete.',
          'Empty grants deny private methods. Public resource flags can still allow anonymous access without a token.',
          'Optional integration grants (for example email) control /api/integrations/* endpoints.',
        ],
        samples: [
          {
            language: 'js',
            label: 'Grant shape',
            code: `{
  "resourceId": null,
  "canRead": true,
  "canCreate": false,
  "canUpdate": false,
  "canDelete": false
}`,
          },
        ],
      },
    ],
  },
  {
    id: 'resources',
    title: 'Enabling a resource API',
    sections: [
      {
        paragraphs: [
          'A resource is reachable on the Content API only when it is published and API access is enabled.',
        ],
        links: [{ label: 'Resources', href: '/resources' }],
      },
      {
        heading: 'Checklist',
        paragraphs: [
          '1. Create fields and publish the resource (DB table exists).',
          '2. Turn on settings.apiEnabled.',
          '3. Optionally set settings.public.read / create / update / delete for anonymous access.',
          '4. Call GET /api/{slug} where slug is the resource key (for example demo_articles).',
        ],
      },
      {
        heading: 'Endpoint',
        samples: [
          {
            language: 'http',
            code: 'GET /api/{slug}\nGET /api/v1/{slug}',
          },
        ],
        paragraphs: [
          'Use the API playground on the resource detail page to try requests, or open Swagger for the full generated schema.',
        ],
        links: [{ label: 'Open Swagger', href: '/api/docs', external: true }],
      },
    ],
  },
  {
    id: 'crud',
    title: 'CRUD and queries',
    sections: [
      {
        paragraphs: ['Standard REST over published, API-enabled resources. Responses are JSON.'],
        samples: [
          {
            language: 'http',
            label: 'Endpoints',
            code: `GET    /api/{slug}?page=1&limit=20&sort=-created_at&search=foo
GET    /api/{slug}/{id}
POST   /api/{slug}
PATCH  /api/{slug}/{id}
DELETE /api/{slug}/{id}`,
          },
        ],
      },
      {
        heading: 'Query parameters',
        paragraphs: [
          'page, limit — pagination.',
          'sort — field name; prefix with - for descending (e.g. -created_at).',
          'search — tokenized search across searchable fields (stop words skipped; ranked by matched word count).',
          'filter[field]=value or filter[field][op]=value with ops: eq, neq, gt, gte, lt, lte, contains, startsWith, endsWith, in.',
        ],
      },
      {
        heading: 'Examples',
        samples: [
          {
            language: 'bash',
            label: 'curl',
            code: `curl -s "$BASE/api/demo_articles?page=1&limit=20" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Accept: application/json"`,
          },
          {
            language: 'js',
            label: 'fetch',
            code: `const res = await fetch(\`\${base}/api/demo_articles?page=1&limit=20\`, {
  headers: {
    Authorization: \`Bearer \${token}\`,
    Accept: 'application/json',
  },
})
const { data, meta } = await res.json()`,
          },
          {
            language: 'bash',
            label: 'Create',
            code: `curl -s -X POST "$BASE/api/demo_articles" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"title":"Hello","slug":"hello"}'`,
          },
        ],
      },
    ],
  },
  {
    id: 'custom-apis',
    title: 'Custom APIs',
    sections: [
      {
        paragraphs: [
          'Each resource can define named APIs with field projection, nested manyToOne embeds and their own set of HTTP methods.',
          'Manage them under the resource → APIs tab. The apiSlug must start with a letter so it does not collide with numeric entry ids.',
          'A projection doubles as the write mask: POST and PATCH accept only the projected fields, and POST requires every required field to be projected. Writes are unavailable while the API has joins.',
          'Public access is tri-state per method: inherit follows the resource, otherwise the API overrides it. Token access still uses the resource grants.',
        ],
        samples: [
          {
            language: 'http',
            code: `GET /api/{slug}/{apiSlug}
GET /api/{slug}/{apiSlug}/{id}
POST /api/{slug}/{apiSlug}
PATCH /api/{slug}/{apiSlug}/{id}
DELETE /api/{slug}/{apiSlug}/{id}`,
          },
        ],
      },
      {
        heading: 'Example',
        samples: [
          {
            language: 'bash',
            code: `curl -s "$BASE/api/demo_articles/card?limit=10" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Accept: application/json"`,
          },
        ],
      },
    ],
  },
  {
    id: 'webhooks',
    title: 'Webhooks',
    sections: [
      {
        paragraphs: [
          'Outbound HMAC-signed POSTs when content changes. Configure under Settings → Webhooks (admin / settings.write). Delivery runs after the HTTP response (shutdown / FastCGI).',
          'Events: entry.created, entry.updated, entry.deleted, resource.published. Optional resourceId filter. Test button sends webhook.test (one attempt, no retries).',
          'A write through a custom API adds apiSlug to the payload, and entry then holds only the projected fields.',
        ],
        links: [{ label: 'Settings → Webhooks', href: '/settings/webhooks' }],
      },
      {
        heading: 'Delivery',
        paragraphs: [
          'POST JSON, timeout 5s, success = 2xx, up to 3 attempts with 0s/1s/2s backoff.',
          'Headers: X-HCMS-Event, X-HCMS-Signature (sha256=<hmac_hex>), X-HCMS-Delivery-Id, User-Agent: HCMS-Webhooks/1.0.',
          'Verify HMAC-SHA256 over the raw body with the webhook secret. Respond 2xx quickly; do heavy work async.',
        ],
        samples: [
          {
            language: 'http',
            label: 'Headers',
            code: `Content-Type: application/json; charset=utf-8
X-HCMS-Event: entry.created
X-HCMS-Signature: sha256=<hmac_hex>
X-HCMS-Delivery-Id: 42
User-Agent: HCMS-Webhooks/1.0`,
          },
        ],
      },
      {
        heading: 'Payload examples',
        samples: [
          {
            language: 'js',
            label: 'entry.created / entry.updated',
            code: `{
  "resourceId": 12,
  "slug": "demo_articles",
  "entry": { "id": 42, "title": "Hello", "slug": "hello" }
}`,
          },
          {
            language: 'js',
            label: 'entry.deleted',
            code: `{
  "resourceId": 12,
  "slug": "demo_articles",
  "entryId": 42
}`,
          },
          {
            language: 'js',
            label: 'resource.published',
            code: `{
  "resourceId": 12,
  "resource": { "id": 12, "key": "demo_articles", "status": "published" }
}`,
          },
        ],
      },
      {
        heading: 'Admin API',
        samples: [
          {
            language: 'http',
            code: `GET    /admin/api/webhooks
POST   /admin/api/webhooks
PATCH  /admin/api/webhooks/{id}
DELETE /admin/api/webhooks/{id}
GET    /admin/api/webhooks/{id}/deliveries
POST   /admin/api/webhooks/{id}/test`,
          },
          {
            language: 'js',
            label: 'Create',
            code: `await fetch('https://cms.example.com/admin/api/webhooks', {
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
})`,
          },
          {
            language: 'js',
            label: 'Verify signature (Node)',
            code: `import crypto from 'node:crypto'

const expected =
  'sha256=' +
  crypto.createHmac('sha256', SECRET).update(rawBody, 'utf8').digest('hex')
const ok =
  expected.length === got.length &&
  crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(got))`,
          },
        ],
      },
    ],
  },
  {
    id: 'limits',
    title: 'Rate limits and errors',
    sections: [
      {
        paragraphs: [
          'Typical limits: about 120 requests/min per IP and 300/min per token (exact values come from server settings). Docs and health endpoints are excluded.',
          '429 responses include Retry-After and X-RateLimit-Limit.',
        ],
      },
      {
        heading: 'Status codes',
        paragraphs: [
          '401 — missing or invalid token.',
          '403 — token valid but grants / public flags deny the method.',
          '404 — unknown slug, unpublished resource, or missing entry.',
          '422 — validation error on create/update.',
          '429 — rate limited; respect Retry-After.',
        ],
      },
    ],
  },
  {
    id: 'quickstart',
    title: 'Quick start (demo)',
    sections: [
      {
        paragraphs: [
          'Seed demo collections with public read so you can hit the Content API without a token:',
        ],
        samples: [
          {
            language: 'bash',
            code: `php scripts/seed-demo.php \\
  --url=http://127.0.0.1:8080 \\
  --email=admin@example.com \\
  --password=secret`,
          },
        ],
      },
      {
        heading: 'Try it',
        paragraphs: [
          'Creates demo_authors, demo_categories, demo_articles, demo_events with public read enabled.',
        ],
        samples: [
          {
            language: 'bash',
            code: `curl -s "http://127.0.0.1:8080/api/demo_articles?page=1&limit=20" \\
  -H "Accept: application/json"`,
          },
          {
            language: 'js',
            code: `const res = await fetch('http://127.0.0.1:8080/api/demo_articles?page=1&limit=20', {
  headers: { Accept: 'application/json' },
})
console.log(await res.json())`,
          },
        ],
        links: [
          { label: 'Open Swagger', href: '/api/docs', external: true },
          { label: 'Resources', href: '/resources' },
        ],
      },
    ],
  },
]

const ru: Chapter[] = [
  {
    id: 'overview',
    title: 'Обзор',
    sections: [
      {
        paragraphs: [
          'У Headless CMS два HTTP-контура. Admin API обслуживает панель. Content API — то, к чему ходит сайт или приложение за опубликованными ресурсами.',
          'Пути /api и /api/v1 эквивалентны — выберите один префикс и используйте его везде.',
        ],
      },
      {
        heading: 'Поверхности',
        paragraphs: [
          'Admin API: /admin/api/* — Bearer-токен администратора после логина.',
          'Content API: /api/{slug} или /api/v1/{slug} — API-токен (или публичные флаги ресурса).',
          'Интерактивный OpenAPI: /api/docs; схема: /api/openapi.json.',
        ],
        links: [
          { label: 'Открыть Swagger', href: '/api/docs', external: true },
          { label: 'OpenAPI JSON', href: '/api/openapi.json', external: true },
        ],
      },
    ],
  },
  {
    id: 'connection',
    title: 'Подключение к API',
    sections: [
      {
        paragraphs: [
          'Base URL — это APP_URL из окружения сервера (например https://cms.example.com). Все пути Content API относительно этого origin.',
          'Авторизация — один заголовок на каждый запрос:',
        ],
        samples: [
          {
            language: 'http',
            label: 'Заголовок',
            code: 'Authorization: Bearer <token>',
          },
        ],
      },
      {
        heading: 'CORS',
        paragraphs: [
          'Браузерным клиентам нужен разрешённый Origin. Настройка: Система → доступ к API — unrestricted или allowlist.',
          'Сервер-сервер без Origin CORS-гейтом не режется.',
          'Разрешённые заголовки: Authorization, Content-Type, Accept.',
        ],
        links: [{ label: 'Настройки → Система', href: '/settings/system' }],
      },
      {
        heading: 'Заметка про Apache',
        paragraphs: [
          'На части Apache/CGI хостингов Authorization срезается до PHP. Инсталлятор пишет rewrite в HTTP_AUTHORIZATION через .htaccess. Если Bearer не проходит после деплоя — проверьте этот rewrite.',
        ],
      },
      {
        heading: 'Минимальный fetch',
        samples: [
          {
            language: 'js',
            label: 'JavaScript',
            code: `const base = 'https://cms.example.com'
const token = 'YOUR_API_TOKEN'

const res = await fetch(\`\${base}/api/demo_articles?page=1&limit=20\`, {
  headers: {
    Authorization: \`Bearer \${token}\`,
    Accept: 'application/json',
  },
})
const json = await res.json()`,
          },
        ],
      },
    ],
  },
  {
    id: 'tokens',
    title: 'Токены и права',
    sections: [
      {
        paragraphs: [
          'API-токены создаются в Настройки → Токены. Секрет показывается один раз — скопируйте сразу.',
          'Admin-токены (после логина) обходят grants. API-токены видят только то, что разрешено grants.',
        ],
        links: [{ label: 'Настройки → Токены', href: '/settings/tokens' }],
      },
      {
        heading: 'Grants',
        paragraphs: [
          'Каждый grant привязан к ресурсу (или null = глобально) с флагами canRead / canCreate / canUpdate / canDelete.',
          'Пустые grants запрещают приватные методы. Публичные флаги ресурса всё ещё могут открыть анонимный доступ.',
          'Опциональные integration grants (например email) управляют /api/integrations/*.',
        ],
        samples: [
          {
            language: 'js',
            label: 'Форма grant',
            code: `{
  "resourceId": null,
  "canRead": true,
  "canCreate": false,
  "canUpdate": false,
  "canDelete": false
}`,
          },
        ],
      },
    ],
  },
  {
    id: 'resources',
    title: 'Включение API у ресурса',
    sections: [
      {
        paragraphs: ['Ресурс доступен в Content API только после publish и включения API.'],
        links: [{ label: 'Ресурсы', href: '/resources' }],
      },
      {
        heading: 'Чеклист',
        paragraphs: [
          '1. Поля + publish (таблица в БД есть).',
          '2. Включить settings.apiEnabled.',
          '3. По желанию settings.public.read / create / update / delete для анонимов.',
          '4. Вызывать GET /api/{slug}, где slug — ключ ресурса (например demo_articles).',
        ],
      },
      {
        heading: 'Эндпоинт',
        samples: [
          {
            language: 'http',
            code: 'GET /api/{slug}\nGET /api/v1/{slug}',
          },
        ],
        paragraphs: ['Playground на карточке ресурса или Swagger — для проверки и полной схемы.'],
        links: [{ label: 'Открыть Swagger', href: '/api/docs', external: true }],
      },
    ],
  },
  {
    id: 'crud',
    title: 'CRUD и запросы',
    sections: [
      {
        paragraphs: ['Обычный REST по опубликованным ресурсам с включённым API. Ответы — JSON.'],
        samples: [
          {
            language: 'http',
            label: 'Эндпоинты',
            code: `GET    /api/{slug}?page=1&limit=20&sort=-created_at&search=foo
GET    /api/{slug}/{id}
POST   /api/{slug}
PATCH  /api/{slug}/{id}
DELETE /api/{slug}/{id}`,
          },
        ],
      },
      {
        heading: 'Query-параметры',
        paragraphs: [
          'page, limit — пагинация.',
          'sort — поле; префикс - для убывания (например -created_at).',
          'search — поиск по словам в searchable-полях (предлоги отбрасываются; выше в выдаче — больше совпавших слов).',
          'filter[field]=value или filter[field][op]=value; ops: eq, neq, gt, gte, lt, lte, contains, startsWith, endsWith, in.',
        ],
      },
      {
        heading: 'Примеры',
        samples: [
          {
            language: 'bash',
            label: 'curl',
            code: `curl -s "$BASE/api/demo_articles?page=1&limit=20" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Accept: application/json"`,
          },
          {
            language: 'js',
            label: 'fetch',
            code: `const res = await fetch(\`\${base}/api/demo_articles?page=1&limit=20\`, {
  headers: {
    Authorization: \`Bearer \${token}\`,
    Accept: 'application/json',
  },
})
const { data, meta } = await res.json()`,
          },
          {
            language: 'bash',
            label: 'Создание',
            code: `curl -s -X POST "$BASE/api/demo_articles" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"title":"Hello","slug":"hello"}'`,
          },
        ],
      },
    ],
  },
  {
    id: 'custom-apis',
    title: 'Custom API',
    sections: [
      {
        paragraphs: [
          'У ресурса можно завести именованные API с проекцией полей, вложенными manyToOne embeds и своим набором HTTP-методов.',
          'Управление: ресурс → вкладка APIs. apiSlug должен начинаться с буквы, чтобы не конфликтовать с id записей.',
          'Проекция работает и как маска записи: POST и PATCH принимают только выбранные поля, а POST требует, чтобы все обязательные поля были в проекции. При наличии joins запись недоступна.',
          'Публичный доступ задаётся по методу в трёх состояниях: «наследовать» берёт настройку ресурса, иначе API её переопределяет. Токены по-прежнему проверяются по грантам ресурса.',
        ],
        samples: [
          {
            language: 'http',
            code: `GET /api/{slug}/{apiSlug}
GET /api/{slug}/{apiSlug}/{id}
POST /api/{slug}/{apiSlug}
PATCH /api/{slug}/{apiSlug}/{id}
DELETE /api/{slug}/{apiSlug}/{id}`,
          },
        ],
      },
      {
        heading: 'Пример',
        samples: [
          {
            language: 'bash',
            code: `curl -s "$BASE/api/demo_articles/card?limit=10" \\
  -H "Authorization: Bearer $TOKEN" \\
  -H "Accept: application/json"`,
          },
        ],
      },
    ],
  },
  {
    id: 'webhooks',
    title: 'Webhooks',
    sections: [
      {
        paragraphs: [
          'Исходящие HMAC-подписанные POST при изменениях контента. Настройка: Настройки → Webhooks (admin / settings.write). Доставка после HTTP-ответа (shutdown / FastCGI).',
          'События: entry.created, entry.updated, entry.deleted, resource.published. Опциональный фильтр resourceId. Кнопка Test шлёт webhook.test (одна попытка, без ретраев).',
          'Запись через кастомный API добавляет в payload apiSlug, а entry содержит только поля проекции.',
        ],
        links: [{ label: 'Настройки → Webhooks', href: '/settings/webhooks' }],
      },
      {
        heading: 'Доставка',
        paragraphs: [
          'POST JSON, timeout 5s, success = 2xx, до 3 попыток с backoff 0s/1s/2s.',
          'Заголовки: X-HCMS-Event, X-HCMS-Signature (sha256=<hmac_hex>), X-HCMS-Delivery-Id, User-Agent: HCMS-Webhooks/1.0.',
          'Проверяйте HMAC-SHA256 по сырому body и секрету webhook. Отвечайте 2xx быстро; тяжёлую работу — асинхронно.',
        ],
        samples: [
          {
            language: 'http',
            label: 'Заголовки',
            code: `Content-Type: application/json; charset=utf-8
X-HCMS-Event: entry.created
X-HCMS-Signature: sha256=<hmac_hex>
X-HCMS-Delivery-Id: 42
User-Agent: HCMS-Webhooks/1.0`,
          },
        ],
      },
      {
        heading: 'Примеры payload',
        samples: [
          {
            language: 'js',
            label: 'entry.created / entry.updated',
            code: `{
  "resourceId": 12,
  "slug": "demo_articles",
  "entry": { "id": 42, "title": "Hello", "slug": "hello" }
}`,
          },
          {
            language: 'js',
            label: 'entry.deleted',
            code: `{
  "resourceId": 12,
  "slug": "demo_articles",
  "entryId": 42
}`,
          },
          {
            language: 'js',
            label: 'resource.published',
            code: `{
  "resourceId": 12,
  "resource": { "id": 12, "key": "demo_articles", "status": "published" }
}`,
          },
        ],
      },
      {
        heading: 'Admin API',
        samples: [
          {
            language: 'http',
            code: `GET    /admin/api/webhooks
POST   /admin/api/webhooks
PATCH  /admin/api/webhooks/{id}
DELETE /admin/api/webhooks/{id}
GET    /admin/api/webhooks/{id}/deliveries
POST   /admin/api/webhooks/{id}/test`,
          },
          {
            language: 'js',
            label: 'Создать',
            code: `await fetch('https://cms.example.com/admin/api/webhooks', {
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
})`,
          },
          {
            language: 'js',
            label: 'Проверка подписи (Node)',
            code: `import crypto from 'node:crypto'

const expected =
  'sha256=' +
  crypto.createHmac('sha256', SECRET).update(rawBody, 'utf8').digest('hex')
const ok =
  expected.length === got.length &&
  crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(got))`,
          },
        ],
      },
    ],
  },
  {
    id: 'limits',
    title: 'Лимиты и ошибки',
    sections: [
      {
        paragraphs: [
          'Типичные лимиты: ~120 req/min на IP и ~300/min на токен (точные значения — в настройках сервера). Docs и health исключены.',
          'Ответ 429 содержит Retry-After и X-RateLimit-Limit.',
        ],
      },
      {
        heading: 'Коды статуса',
        paragraphs: [
          '401 — нет или невалидный токен.',
          '403 — токен ок, но grants / public флаги запрещают метод.',
          '404 — неизвестный slug, неопубликованный ресурс или нет записи.',
          '422 — ошибка валидации при create/update.',
          '429 — rate limit; смотрите Retry-After.',
        ],
      },
    ],
  },
  {
    id: 'quickstart',
    title: 'Быстрый старт (demo)',
    sections: [
      {
        paragraphs: [
          'Засейте demo-коллекции с public read — Content API можно дергать без токена:',
        ],
        samples: [
          {
            language: 'bash',
            code: `php scripts/seed-demo.php \\
  --url=http://127.0.0.1:8080 \\
  --email=admin@example.com \\
  --password=secret`,
          },
        ],
      },
      {
        heading: 'Проверка',
        paragraphs: [
          'Создаёт demo_authors, demo_categories, demo_articles, demo_events с публичным чтением.',
        ],
        samples: [
          {
            language: 'bash',
            code: `curl -s "http://127.0.0.1:8080/api/demo_articles?page=1&limit=20" \\
  -H "Accept: application/json"`,
          },
          {
            language: 'js',
            code: `const res = await fetch('http://127.0.0.1:8080/api/demo_articles?page=1&limit=20', {
  headers: { Accept: 'application/json' },
})
console.log(await res.json())`,
          },
        ],
        links: [
          { label: 'Открыть Swagger', href: '/api/docs', external: true },
          { label: 'Ресурсы', href: '/resources' },
        ],
      },
    ],
  },
]

const byLocale: Record<Locale, Chapter[]> = { en, ru }

export function getChapters(locale: Locale): Chapter[] {
  return byLocale[locale] ?? byLocale.en
}

export function getChapter(locale: Locale, id: ChapterId): Chapter | undefined {
  return getChapters(locale).find((chapter) => chapter.id === id)
}
