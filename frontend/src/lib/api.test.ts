import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, clearToken, handleUnauthorized, setToken, getToken } from './api'

describe('auth session helpers', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(
            JSON.stringify({ error: { code: 'UNAUTHORIZED', message: 'Unauthorized' } }),
            {
              status: 401,
              headers: { 'Content-Type': 'application/json' },
            },
          ),
        ),
      ),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
    sessionStorage.clear()
  })

  it('stores token in localStorage', () => {
    setToken('abc')
    expect(localStorage.getItem('hcms_token')).toBe('abc')
    expect(sessionStorage.getItem('hcms_token')).toBeNull()
    expect(getToken()).toBe('abc')
  })

  it('reads leftover sessionStorage token', () => {
    sessionStorage.setItem('hcms_token', 'legacy')
    expect(getToken()).toBe('legacy')
  })

  it('clears stale token on 401 from protected API', async () => {
    setToken('stale-token')
    const assign = vi.fn()
    vi.stubGlobal('location', {
      pathname: '/admin/',
      search: '',
      assign,
    })

    await expect(api('/admin/api/system/version')).rejects.toMatchObject({ status: 401 })
    expect(localStorage.getItem('hcms_token')).toBeNull()
    expect(assign).toHaveBeenCalledWith('/admin/login?from=%2Fadmin%2F')
  })

  it('does not hard-redirect on auth/me probe', () => {
    setToken('stale-token')
    const assign = vi.fn()
    vi.stubGlobal('location', {
      pathname: '/admin/',
      search: '',
      assign,
    })

    handleUnauthorized('/admin/api/auth/me')
    expect(localStorage.getItem('hcms_token')).toBeNull()
    expect(assign).not.toHaveBeenCalled()
  })

  it('clearToken removes session key', () => {
    setToken('x')
    clearToken()
    expect(localStorage.getItem('hcms_token')).toBeNull()
    expect(getToken()).toBeNull()
  })
})
