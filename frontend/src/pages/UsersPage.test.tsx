import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { UsersPage } from './UsersPage'

vi.mock('@/lib/api', () => ({
  getToken: vi.fn(() => 'test-token'),
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/auth/me') {
      return {
        id: 1,
        name: 'Admin',
        email: 'admin@example.com',
        role: 'owner',
        totpEnabled: false,
      }
    }
    if (path === '/admin/api/users') return []
    return null
  }),
}))

describe('UsersPage', () => {
  it('renders title', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <UsersPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Users' })).toBeInTheDocument()
  })
})
