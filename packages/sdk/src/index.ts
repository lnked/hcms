export type HcmsClientOptions = {
  baseUrl: string
  token?: string
  fetch?: typeof fetch
}

export type ListMeta = {
  page: number
  limit: number
  total: number
  totalPages: number
}

export type ListResult<T> = {
  data: T[]
  meta: ListMeta
}

export class HcmsError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
  ) {
    super(message)
    this.name = 'HcmsError'
  }
}

export function createClient(options: HcmsClientOptions) {
  const baseUrl = options.baseUrl.replace(/\/$/, '')
  const doFetch = options.fetch ?? globalThis.fetch.bind(globalThis)

  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers)
    if (!headers.has('Content-Type') && init.body) {
      headers.set('Content-Type', 'application/json')
    }
    if (options.token) {
      headers.set('Authorization', `Bearer ${options.token}`)
    }
    const res = await doFetch(`${baseUrl}${path}`, { ...init, headers })
    if (res.status === 204) {
      return undefined as T
    }
    const json = (await res.json()) as {
      data?: T
      error?: { code?: string; message?: string }
      meta?: ListMeta
    }
    if (!res.ok) {
      throw new HcmsError(json.error?.message ?? res.statusText, res.status, json.error?.code)
    }
    if (json.meta && Array.isArray(json.data)) {
      return { data: json.data, meta: json.meta } as T
    }
    return (json.data ?? json) as T
  }

  function qs(params?: Record<string, string | number | undefined>): string {
    if (!params) return ''
    const sp = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === '') continue
      sp.set(k, String(v))
    }
    const s = sp.toString()
    return s ? `?${s}` : ''
  }

  return {
    list: <T = Record<string, unknown>>(
      slug: string,
      params?: Record<string, string | number | undefined>,
    ) => request<ListResult<T>>(`/api/${slug}${qs(params)}`),

    get: <T = Record<string, unknown>>(slug: string, id: number) =>
      request<T>(`/api/${slug}/${id}`),

    create: <T = Record<string, unknown>>(slug: string, body: Record<string, unknown>) =>
      request<T>(`/api/${slug}`, { method: 'POST', body: JSON.stringify(body) }),

    update: <T = Record<string, unknown>>(
      slug: string,
      id: number,
      body: Record<string, unknown>,
    ) => request<T>(`/api/${slug}/${id}`, { method: 'PATCH', body: JSON.stringify(body) }),

    remove: (slug: string, id: number) =>
      request<void>(`/api/${slug}/${id}`, { method: 'DELETE' }),

    preview: <T = Record<string, unknown>>(token: string) =>
      request<{ resourceId: number; slug: string; entry: T; expiresAt: number }>(
        `/api/preview/${encodeURIComponent(token)}`,
      ),

    openapi: () => request<unknown>('/api/openapi.json'),
  }
}

export type HcmsClient = ReturnType<typeof createClient>
