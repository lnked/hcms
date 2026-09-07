import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { DashboardPage } from './DashboardPage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/system/stats') {
      return { resources: 3, records: 10, apiRequests: 42, apiKeys: 2 }
    }
    if (path.includes('/system/stats/timeseries')) {
      // Keep charts on skeleton — Recharts needs layout in jsdom.
      await new Promise(() => {})
    }
    return null
  }),
  apiPage: vi.fn(async () => ({
    data: [],
    meta: { page: 1, limit: 5, total: 0, pages: 0 },
  })),
}))

describe('DashboardPage', () => {
  it('renders KPI labels', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <DashboardPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(screen.getByText('Dashboard')).toBeInTheDocument()
    expect(screen.getByText('Resources')).toBeInTheDocument()
    expect(screen.getByText('Records')).toBeInTheDocument()
    expect(screen.getByText('API requests')).toBeInTheDocument()
    expect(screen.getByText('API keys')).toBeInTheDocument()
    expect(await screen.findByText('3')).toBeInTheDocument()
  })
})
