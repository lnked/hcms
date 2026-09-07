import type { ApiErrorBody, DataResponse, ListResponse } from './types'

const STORAGE_BASE = 'hcms-demo-api-base'
const STORAGE_TOKEN = 'hcms-demo-api-token'

const DEFAULT_BASE =
  (import.meta.env.VITE_API_BASE as string | undefined)?.replace(/\/$/, '') ||
  'http://127.0.0.1:8080'

export class ApiError extends Error {
  status: number
  code?: string

  constructor(status: number, message: string, code?: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

export function getApiBase(): string {
  const stored = localStorage.getItem(STORAGE_BASE)?.trim()
  return (stored || DEFAULT_BASE).replace(/\/$/, '')
}

export function setApiBase(value: string): void {
  const next = value.trim().replace(/\/$/, '')
  localStorage.setItem(STORAGE_BASE, next || DEFAULT_BASE)
}

export function getApiToken(): string {
  return localStorage.getItem(STORAGE_TOKEN)?.trim() || ''
}

export function setApiToken(value: string): void {
  const next = value.trim()
  if (next) localStorage.setItem(STORAGE_TOKEN, next)
  else localStorage.removeItem(STORAGE_TOKEN)
}

export function mediaUrl(id: number | null | undefined): string | null {
  if (id == null) return null
  return `${getApiBase()}/media/${id}`
}

type RequestOptions = {
  method?: string
  path: string
  query?: string
  body?: unknown
  token?: string
}

function buildUrl(path: string, query?: string): string {
  const base = getApiBase()
  const normalized = path.startsWith('/') ? path : `/${path}`
  const url = `${base}${normalized}`
  const q = query?.replace(/^\?/, '').trim()
  return q ? `${url}?${q}` : url
}

export async function apiRequest<T = unknown>(
  options: RequestOptions,
): Promise<{ status: number; data: T }> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  const token = options.token ?? getApiToken()
  if (token) headers.Authorization = `Bearer ${token}`

  const init: RequestInit = {
    method: options.method ?? 'GET',
    headers,
  }

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(options.body)
  }

  const res = await fetch(buildUrl(options.path, options.query), init)
  const text = await res.text()
  let parsed: unknown = null
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = text
    }
  }

  if (!res.ok) {
    const err = parsed as ApiErrorBody | null
    const message =
      err && typeof err === 'object' && err.error?.message
        ? err.error.message
        : `HTTP ${res.status}`
    const code =
      err && typeof err === 'object' && err.error?.code ? err.error.code : undefined
    throw new ApiError(res.status, message, code)
  }

  return { status: res.status, data: parsed as T }
}

export async function getList<T>(
  resource: string,
  query?: string,
): Promise<ListResponse<T>> {
  const { data } = await apiRequest<ListResponse<T>>({
    path: `/api/${resource}`,
    query,
  })
  return data
}

export async function getOne<T>(resource: string, id: number | string): Promise<T> {
  const { data } = await apiRequest<DataResponse<T>>({
    path: `/api/${resource}/${id}`,
  })
  return data.data
}
