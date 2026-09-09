import { showError } from '@/lib/toast'

const TOKEN_KEY = 'hcms_token'

function readStorage(storage: Storage): string | null {
  try {
    return storage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

function writeStorage(storage: Storage, value: string | null): void {
  try {
    if (value === null) {
      storage.removeItem(TOKEN_KEY)
    } else {
      storage.setItem(TOKEN_KEY, value)
    }
  } catch {
    // private mode / quota
  }
}

/** Move a leftover sessionStorage token into localStorage once. */
function migrateSessionToken(): void {
  const fromSession = readStorage(sessionStorage)
  if (!fromSession) {
    return
  }
  if (!readStorage(localStorage)) {
    writeStorage(localStorage, fromSession)
  }
  writeStorage(sessionStorage, null)
}

migrateSessionToken()

export function getToken(): string | null {
  return readStorage(localStorage) ?? readStorage(sessionStorage)
}

export function setToken(token: string): void {
  writeStorage(localStorage, token)
  writeStorage(sessionStorage, null)
}

export function clearToken(): void {
  writeStorage(localStorage, null)
  writeStorage(sessionStorage, null)
}

let redirectingToLogin = false

function isAuthChallengePath(requestPath: string): boolean {
  return (
    requestPath.includes('/admin/api/auth/login') ||
    requestPath.includes('/admin/api/auth/telegram') ||
    requestPath.includes('/admin/api/auth/totp/complete')
  )
}

/** Drop stale session and bounce to login (after reinstall / revoked token). */
export function handleUnauthorized(requestPath: string): void {
  if (isAuthChallengePath(requestPath)) {
    return
  }
  clearToken()
  // Session probe — RequireAuth soft-navigates to /login.
  if (requestPath.includes('/admin/api/auth/me')) {
    return
  }
  const path = window.location.pathname
  if (path === '/admin/login' || path.endsWith('/login') || redirectingToLogin) {
    return
  }
  redirectingToLogin = true
  const next = `${path}${window.location.search}`
  window.location.assign(`/admin/login?from=${encodeURIComponent(next)}`)
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

function shouldToastApiError(path: string, status: number, code: string): boolean {
  if (isAuthChallengePath(path)) return false
  if (path.includes('/admin/api/auth/me')) return false
  if (status === 401) return false
  // Password form renders per-field errors inline.
  if (status === 422 && path.includes('/admin/api/auth/password')) return false
  if (code === 'TOTP_REQUIRED' || code === 'CAPTCHA_REQUIRED') return false
  return true
}

interface ApiEnvelope<T> {
  data?: T
  error?: { code?: string; message?: string; fields?: Record<string, string[]> }
}

/**
 * PHP fatals, nginx 413/504 and proxy pages answer with HTML, not JSON. Parsing them
 * blindly hides the status behind a syntax error, so fall back to a readable envelope.
 */
async function readEnvelope<T>(response: Response): Promise<ApiEnvelope<T> & { current?: string }> {
  const text = await response.text()
  if (text === '') {
    return response.ok ? {} : { error: { code: 'ERROR', message: `HTTP ${response.status}` } }
  }
  try {
    return JSON.parse(text) as ApiEnvelope<T> & { current?: string }
  } catch {
    return {
      error: {
        code: 'ERROR',
        message: `HTTP ${response.status}: ${text
          .replace(/<[^>]*>/g, ' ')
          .trim()
          .slice(0, 200)}`,
      },
    }
  }
}

function throwApiError(
  path: string,
  status: number,
  code: string,
  message: string,
  fields: Record<string, string[]> = {},
): never {
  if (shouldToastApiError(path, status, code)) {
    showError(message)
  }
  throw new ApiError(status, code, message, fields)
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  let response: Response
  try {
    response = await fetch(path, { ...init, headers })
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Request failed'
    showError(message)
    throw err instanceof Error ? err : new Error(message)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const payload = await readEnvelope<T>(response)

  if (!response.ok || payload.error) {
    if (response.status === 401) {
      handleUnauthorized(path)
    }
    throwApiError(
      path,
      response.status,
      payload.error?.code ?? 'ERROR',
      payload.error?.message ?? 'Request failed',
      payload.error?.fields ?? {},
    )
  }

  if (payload.data !== undefined) {
    return payload.data
  }

  return payload as T
}

export interface PageMeta {
  page: number
  limit: number
  total: number
  totalPages: number
}

export async function apiPage<T>(
  path: string,
  init: RequestInit = {},
): Promise<{ data: T[]; meta: PageMeta }> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  let response: Response
  try {
    response = await fetch(path, { ...init, headers })
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Request failed'
    showError(message)
    throw err instanceof Error ? err : new Error(message)
  }

  const payload = (await readEnvelope<T[]>(response)) as ApiEnvelope<T[]> & { meta?: PageMeta }

  if (!response.ok || payload.error) {
    if (response.status === 401) {
      handleUnauthorized(path)
    }
    throwApiError(
      path,
      response.status,
      payload.error?.code ?? 'ERROR',
      payload.error?.message ?? 'Request failed',
    )
  }

  return {
    data: payload.data ?? [],
    meta: payload.meta ?? { page: 1, limit: 20, total: 0, totalPages: 1 },
  }
}

export async function apiUpload<T>(
  path: string,
  file: File,
  fieldName = 'file',
  extraFields?: Record<string, string>,
): Promise<T> {
  const headers = new Headers()
  headers.set('Accept', 'application/json')
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }
  const body = new FormData()
  body.append(fieldName, file)
  if (extraFields) {
    for (const [key, value] of Object.entries(extraFields)) {
      body.append(key, value)
    }
  }

  let response: Response
  try {
    response = await fetch(path, { method: 'POST', headers, body })
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Upload failed'
    showError(message)
    throw err instanceof Error ? err : new Error(message)
  }

  const payload = await readEnvelope<T>(response)

  if (!response.ok || payload.error) {
    if (response.status === 401) {
      handleUnauthorized(path)
    }
    throwApiError(
      path,
      response.status,
      payload.error?.code ?? 'ERROR',
      payload.error?.message ?? 'Upload failed',
    )
  }

  if (payload.data !== undefined) {
    return payload.data
  }

  return payload as T
}

export async function installApi<T>(
  action: string,
  body: Record<string, unknown> = {},
): Promise<T> {
  let response: Response
  try {
    response = await fetch(`/install.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ action, ...body }),
    })
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Install request failed'
    showError(message)
    throw err instanceof Error ? err : new Error(message)
  }

  const payload = (await response.json()) as T & {
    error?: { code?: string; message?: string; fields?: Record<string, string[]> }
  }
  if (!response.ok) {
    const message = payload.error?.message ?? 'Install request failed'
    showError(message)
    throw new ApiError(
      response.status,
      payload.error?.code ?? 'ERROR',
      message,
      payload.error?.fields ?? {},
    )
  }
  return payload
}
