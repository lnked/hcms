import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { I18nProvider } from '@/i18n'
import { TokensPage } from './TokensPage'

const tokens: unknown[] = []

vi.mock('@/lib/api', () => ({
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/tokens') return tokens
    if (path === '/admin/api/resources') return []
    return null
  }),
}))

function renderPage() {
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
}

const baseToken = {
  id: 1,
  name: 'CI deploy',
  prefix: 'abcd1234',
  expiresAt: null,
  revokedAt: null,
  lastUsedAt: null,
  createdAt: '2026-01-01 00:00:00',
  grants: [],
  integrationGrants: [],
  allowedOrigins: [],
  requireOrigin: false,
  allowedIps: [],
}

describe('TokensPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    tokens.length = 0
  })

  it('renders empty state', async () => {
    renderPage()
    expect(await screen.findByText(/No API tokens yet/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Create token/i })).toBeInTheDocument()
  })

  it('flags a token that is not locked to any domain or IP', async () => {
    tokens.push(baseToken)
    renderPage()
    expect(await screen.findByText(/any origin/i)).toBeInTheDocument()
  })

  it('summarizes per-token restrictions', async () => {
    tokens.push({
      ...baseToken,
      allowedOrigins: ['https://app.example.com', '*.example.com'],
      requireOrigin: true,
      allowedIps: ['10.0.0.0/8'],
    })
    renderPage()
    expect(await screen.findByText('2 domain(s)')).toBeInTheDocument()
    expect(screen.getByText(/browser only/i)).toBeInTheDocument()
    expect(screen.getByText('1 IP rule(s)')).toBeInTheDocument()
    expect(screen.queryByText(/any origin/i)).not.toBeInTheDocument()
  })
})
