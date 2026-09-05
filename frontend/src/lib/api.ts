const TOKEN_KEY = 'hcms_token'

export function getToken(): string | null {
  return sessionStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  sessionStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  sessionStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message)
  }
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

  const response = await fetch(path, { ...init, headers })
  if (response.status === 204) {
    return undefined as T
  }

  const payload = (await response.json()) as {
    data?: T
    error?: { code?: string; message?: string }
    current?: string
  }

  if (!response.ok) {
    throw new ApiError(
      response.status,
      payload.error?.code ?? 'ERROR',
      payload.error?.message ?? 'Request failed',
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

  const response = await fetch(path, { ...init, headers })
  const payload = (await response.json()) as {
    data?: T[]
    meta?: PageMeta
    error?: { code?: string; message?: string }
  }

  if (!response.ok) {
    throw new ApiError(
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

export async function apiUpload<T>(path: string, file: File, fieldName = 'file'): Promise<T> {
  const headers = new Headers()
  headers.set('Accept', 'application/json')
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }
  const body = new FormData()
  body.append(fieldName, file)

  const response = await fetch(path, { method: 'POST', headers, body })
  const payload = (await response.json()) as {
    data?: T
    error?: { code?: string; message?: string }
  }

  if (!response.ok) {
    throw new ApiError(
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
  const response = await fetch(`/install.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ action, ...body }),
  })
  const payload = (await response.json()) as T & { error?: { message?: string } }
  if (!response.ok) {
    throw new Error(payload.error?.message ?? 'Install request failed')
  }
  return payload
}
