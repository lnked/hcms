# @hcms/sdk

Thin public API client + OpenAPI typegen CLI for HCMS.

```bash
npm install ./packages/sdk
# or after publish: npm i @hcms/sdk
```

```ts
import { createClient } from '@hcms/sdk'

const hcms = createClient({
  baseUrl: 'http://127.0.0.1:8080',
  token: process.env.HCMS_TOKEN, // optional for public.read
})

const page = await hcms.list('articles', { limit: 10, locale: 'en' })
const one = await hcms.get('articles', 1)
```

## Typegen

```bash
npx hcms-types --url=http://127.0.0.1:8080 --out=src/hcms.d.ts
# optional: npm i -D openapi-typescript for full generation
```
