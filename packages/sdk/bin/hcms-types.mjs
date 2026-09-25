#!/usr/bin/env node
/**
 * Fetch HCMS OpenAPI and write TypeScript types via openapi-typescript if available,
 * otherwise dump a stub pointing at the JSON schema.
 *
 * Usage: hcms-types --url=http://127.0.0.1:8080 [--out=hcms.d.ts] [--token=...]
 */
import { writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

function arg(name) {
  const prefix = `--${name}=`
  const hit = process.argv.find((a) => a.startsWith(prefix))
  return hit ? hit.slice(prefix.length) : undefined
}

const url = (arg('url') || process.env.HCMS_URL || '').replace(/\/$/, '')
const out = arg('out') || 'hcms.d.ts'
const token = arg('token') || process.env.HCMS_TOKEN

if (!url) {
  console.error('Usage: hcms-types --url=http://host [--out=hcms.d.ts] [--token=...]')
  process.exit(1)
}

const headers = {}
if (token) headers.Authorization = `Bearer ${token}`

const res = await fetch(`${url}/api/openapi.json`, { headers })
if (!res.ok) {
  console.error(`Failed to fetch OpenAPI: ${res.status} ${res.statusText}`)
  process.exit(1)
}
const spec = await res.json()

let emitted = false
try {
  const mod = await import('openapi-typescript')
  const openapiTS = mod.default || mod
  const ast = await openapiTS(spec)
  writeFileSync(out, typeof ast === 'string' ? ast : String(ast))
  emitted = true
} catch {
  // optional peer
}

if (!emitted) {
  writeFileSync(
    out,
    [
      '/** Generated stub — install openapi-typescript for full types: npm i -D openapi-typescript */',
      `export type HcmsOpenApiInfo = ${JSON.stringify({ title: spec.info?.title, version: spec.info?.version }, null, 2)}`,
      'export type paths = Record<string, unknown>',
      '',
    ].join('\n'),
  )
}

console.log(`Wrote ${out} from ${url}/api/openapi.json`)
