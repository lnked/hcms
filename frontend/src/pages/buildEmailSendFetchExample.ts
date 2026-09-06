function resolveApiOrigin(origin?: string): string {
  const value = (origin ?? (typeof window !== 'undefined' ? window.location.origin : '')).trim()
  return value.replace(/\/$/, '')
}

export function buildEmailSendFetchExample(
  path: string,
  options?: { origin?: string; withVars?: boolean },
): string {
  const origin = resolveApiOrigin(options?.origin)
  const normalized = path.startsWith('/') ? path : `/${path}`
  const url = `${origin}${normalized}`

  const body = options?.withVars
    ? `{
    to: 'user@example.com',
    vars: { name: 'Ada' },
  }`
    : `{
    to: 'user@example.com',
    subject: 'Hello from HCMS',
    html: '<p>Hello from HCMS</p>',
    text: 'Hello from HCMS',
  }`

  return `const res = await fetch('${url}', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: 'Bearer YOUR_TOKEN',
  },
  body: JSON.stringify(${body}),
})
const data = await res.json()`
}
