import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { api } from '@/lib/api'
import { UptimePage } from './UptimePage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(async (path: string, init?: { method?: string; body?: string }) => {
    if (path === '/admin/api/uptime/status') {
      return {
        summary: {
          up: 1,
          down: 0,
          unknown: 0,
          total: 1,
          uptimePercent24h: 99.5,
          uptimePercent7d: 99.9,
          openIncidents: 0,
        },
        targets: [
          {
            id: 1,
            name: 'Docs',
            url: 'https://example.com',
            kind: 'external',
            method: 'GET',
            expectedStatus: 200,
            timeoutMs: 5000,
            intervalSeconds: 60,
            enabled: true,
            lastCheckAt: '2026-01-01 12:00:00',
            lastOk: true,
            lastStatusCode: 200,
            lastLatencyMs: 42,
            lastError: null,
            lastHeartbeatAt: null,
            createdAt: '2026-01-01 00:00:00',
            updatedAt: '2026-01-01 12:00:00',
          },
        ],
      }
    }
    if (path === '/admin/api/uptime/targets' && init?.method === 'POST') {
      return {
        id: 2,
        name: 'API',
        url: 'https://api.example.com/health',
        kind: 'external',
        method: 'GET',
        expectedStatus: 200,
        timeoutMs: 5000,
        intervalSeconds: 60,
        enabled: true,
        lastCheckAt: null,
        lastOk: null,
        lastStatusCode: null,
        lastLatencyMs: null,
        lastError: null,
        lastHeartbeatAt: null,
        createdAt: '2026-01-01 00:00:00',
        updatedAt: '2026-01-01 00:00:00',
      }
    }
    if (path.includes('/incidents')) {
      return []
    }
    return null
  }),
}))

vi.mock('@/lib/toast', () => ({
  showSuccess: vi.fn(),
}))

describe('UptimePage', () => {
  it('lists targets from status payload', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <UptimePage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByText('Docs')).toBeInTheDocument()
    expect(screen.getByText('https://example.com')).toBeInTheDocument()
    expect(api).toHaveBeenCalledWith('/admin/api/uptime/status')
  })
})
