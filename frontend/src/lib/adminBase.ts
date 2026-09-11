/** Runtime admin URL config injected by PHP spa() (and defaults for Vite dev). */

export type HcmsRuntime = {
  adminBase: string
  uiBase: string
  apiPrefix: string
}

declare global {
  interface Window {
    __HCMS__?: Partial<HcmsRuntime>
  }
}

const INTERNAL_API = '/admin/api'

function readRuntime(): HcmsRuntime {
  const raw = window.__HCMS__ ?? {}
  const adminBase =
    typeof raw.adminBase === 'string'
      ? raw.adminBase.replace(/^\/+|\/+$/g, '')
      : typeof raw.uiBase === 'string'
        ? raw.uiBase.replace(/^\/+|\/+$/g, '')
        : 'admin'
  const uiBase = adminBase === '' ? '' : `/${adminBase}`
  const apiPrefix =
    typeof raw.apiPrefix === 'string' && raw.apiPrefix !== ''
      ? raw.apiPrefix.replace(/\/$/, '')
      : adminBase === ''
        ? INTERNAL_API
        : `${uiBase}/api`

  return { adminBase, uiBase, apiPrefix }
}

/** BrowserRouter basename: '' | '/panel' | '/admin' */
export function getAdminBasename(): string {
  return readRuntime().uiBase
}

export function getApiPrefix(): string {
  return readRuntime().apiPrefix
}

/** Absolute admin UI path, e.g. /panel/login or /login when root. */
export function adminPath(suffix = ''): string {
  const base = getAdminBasename()
  const path = suffix === '' ? '' : `/${suffix.replace(/^\/+/, '')}`
  if (base === '') {
    return path === '' ? '/' : path
  }
  return `${base}${path}`
}

/** Rewrite legacy /admin/api/... call sites onto the configured API prefix. */
export function resolveApiPath(path: string): string {
  if (path === INTERNAL_API || path.startsWith(`${INTERNAL_API}/`)) {
    return getApiPrefix() + path.slice(INTERNAL_API.length)
  }
  return path
}
