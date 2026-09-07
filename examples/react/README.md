# HCMS React demo

Minimal Vite + React consumer for the public Content API (`demo_*` resources from `scripts/seed-demo.php`), plus a request playground.

## Prerequisites

1. CMS running:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

2. Demo data:

```bash
php scripts/seed-demo.php --email=admin@example.com --password=secret
```

3. CORS: Settings → System → `api.access` should allow the Vite origin (`http://127.0.0.1:5173`), or keep `unrestricted: true` (default after install). Seed resources have `public.read: true`.

## Run

```bash
cd examples/react
cp .env.example .env   # optional
npm install
npm run dev
```

Open `http://127.0.0.1:5173`. Override API base / Bearer token in the header strip (saved to `localStorage`).

## Pages

| Route | API |
|-------|-----|
| `/` | `GET /api/demo_articles?filter[status]=published` |
| `/articles/:id` | `GET /api/demo_articles/:id` |
| `/categories` | `GET /api/demo_categories` |
| `/events` | `GET /api/demo_events` |
| `/playground` | arbitrary method/path/query |

Media covers: `{API_BASE}/media/{id}`.
