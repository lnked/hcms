import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { LoginPage } from './LoginPage'

const setToken = vi.fn()
const getToken = vi.fn(() => null)
const api = vi.fn(async (path: string) => {
  if (path.includes('/auth/captcha')) {
    return { enabled: false, provider: null, siteKey: '' }
  }
  if (path.includes('/auth/providers')) {
    return { google: { enabled: false, clientId: '' }, telegram: { enabled: false, botUsername: '' } }
  }
  return { token: 'abc123', user: { id: 1, name: 'A', email: 'a@b.c' } }
})

vi.mock('@/lib/api', () => ({
  api: (...args: unknown[]) => api(...(args as [string])),
  setToken: (...args: unknown[]) => setToken(...args),
  getToken: () => getToken(),
  clearToken: vi.fn(),
}))

function renderLogin() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <I18nProvider initialLocale="en">
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <LoginPage />
        </MemoryRouter>
      </QueryClientProvider>
    </I18nProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    setToken.mockClear()
    api.mockClear()
    getToken.mockReturnValue(null)
  })

  it('submits credentials and stores token', async () => {
    const user = userEvent.setup()
    renderLogin()

    await waitFor(() => expect(screen.getByLabelText('Email')).toBeInTheDocument())

    await user.type(screen.getByLabelText('Email'), 'admin@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret12')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(setToken).toHaveBeenCalledWith('abc123')
  })
})
