import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { MediaPage } from './MediaPage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(),
  apiPage: vi.fn(async () => ({
    data: [],
    meta: { page: 1, limit: 48, total: 0, pages: 0 },
  })),
  apiUpload: vi.fn(),
}))

describe('MediaPage', () => {
  it('renders title and empty state', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MediaPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Media' })).toBeInTheDocument()
    expect(await screen.findByText('No media yet.')).toBeInTheDocument()
  })
})
