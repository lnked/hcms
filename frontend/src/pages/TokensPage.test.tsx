import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi, beforeEach } from 'vitest'
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
      <QueryClientProvider client={client}>
        <TokensPage />
      </QueryClientProvider>,
    )
    expect(await screen.findByText(/No API tokens yet/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Create token/i })).toBeInTheDocument()
  })
})
