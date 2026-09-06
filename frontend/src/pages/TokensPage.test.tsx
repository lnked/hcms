import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { I18nProvider } from '@/i18n'
import { TokensPage } from './TokensPage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/tokens') return []
    if (path === '/admin/api/resources') return []
    return null
  }),
}))

describe('TokensPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders empty state', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <TokensPage />
        </QueryClientProvider>
      </I18nProvider>,
    )
    expect(await screen.findByText(/No API tokens yet/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Create token/i })).toBeInTheDocument()
  })
})
