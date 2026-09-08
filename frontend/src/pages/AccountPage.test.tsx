import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { AccountPage } from './AccountPage'

const api = vi.fn(async (path: string, init?: RequestInit) => {
  if (path === '/admin/api/auth/providers') {
    return {
      google: { enabled: true, clientId: 'cid' },
      telegram: { enabled: false, botUsername: '' },
    }
  }
  if (path === '/admin/api/auth/identities' && init?.method === 'DELETE') {
    return [
      { provider: 'google', linked: false, label: null },
      { provider: 'telegram', linked: false, label: null },
    ]
  }
  if (path === '/admin/api/auth/identities') {
    return [
      { provider: 'google', linked: true, label: 'ada@example.com' },
      { provider: 'telegram', linked: false, label: null },
    ]
  }
  return null
})

vi.mock('@/lib/api', () => ({
  api: (...args: unknown[]) => api(...(args as [string, RequestInit?])),
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      public code: string,
      message: string,
    ) {
      super(message)
    }
  },
}))

describe('AccountPage', () => {
  it('shows linked google and disconnects', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <AccountPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Account' })).toBeInTheDocument()
    expect(await screen.findByText('ada@example.com')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }))
    expect(api).toHaveBeenCalledWith('/admin/api/auth/identities/google', { method: 'DELETE' })
  })
})
