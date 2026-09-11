import type { Resource } from '@/types/resource'

function resolveApiOrigin(origin?: string): string {
  const value = (origin ?? (typeof window !== 'undefined' ? window.location.origin : '')).trim()
  return value.replace(/\/$/, '')
}

function buildResourceFetchUrl(
  endpoint: string,
  options?: { origin?: string; query?: string },
): string {
  const origin = resolveApiOrigin(options?.origin)
  const path = endpoint.startsWith('/') ? endpoint : `/${endpoint}`
  const query = options?.query?.replace(/^\?/, '').trim()
  const base = `${origin}${path}`
  return query ? `${base}?${query}` : base
}

export function buildResourceFetchExample(
  resource: Pick<Resource, 'endpoint' | 'settings'>,
  options?: { origin?: string },
): string {
  const query = resource.settings.pagination ? 'limit=20' : undefined
  const url = buildResourceFetchUrl(resource.endpoint, { origin: options?.origin, query })
  const needsAuth = !resource.settings.public.read

  const headers = needsAuth
    ? `  headers: {
    Accept: 'application/json',
    Authorization: 'Bearer YOUR_TOKEN',
  },`
    : `  headers: {
    Accept: 'application/json',
  },`

  return `const res = await fetch('${url}', {
${headers}
})
const data = await res.json()`
}
