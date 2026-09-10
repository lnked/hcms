import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { ChangelogPage } from './ChangelogPage'

vi.mock('@/lib/api', () => ({
  apiPage: vi.fn(async () => ({
    data: [
      {
        version: '0.1.0',
        date: '2026-09-05',
        channel: 'stable',
        title: 'Foundation',
        changes: [{ type: 'added', text: 'Installer' }],
      },
    ],
    meta: { page: 1, limit: 20, total: 1, totalPages: 1 },
  })),
}))

describe('ChangelogPage', () => {
  it('renders releases', async () => {
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <ChangelogPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByText('v0.1.0')).toBeInTheDocument()
    expect(screen.getByText('Installer')).toBeInTheDocument()
  })
})
