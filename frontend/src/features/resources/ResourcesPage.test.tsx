import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { ResourcesPage } from './ResourcesPage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(async () => [
    {
      id: 1,
      contentTypeId: 1,
      slug: 'articles',
      endpoint: '/api/articles',
      apiVersion: 'v1',
      status: 'draft',
      schemaVersion: 0,
      settings: {
        apiEnabled: true,
        public: { read: false, create: false, update: false, delete: false },
        pagination: true,
        search: true,
        sorting: true,
        filtering: true,
        deleteStrategy: 'hard',
        softDelete: false,
      },
      label: 'Articles',
      contentTypeSlug: 'articles',
      isSystem: false,
      createdAt: '2026-09-05 00:00:00',
      updatedAt: '2026-09-05 00:00:00',
    },
  ]),
}))

vi.mock('@/lib/clipboard', () => ({
  copyToClipboard: vi.fn(async () => undefined),
}))

describe('ResourcesPage', () => {
  it('lists resources', async () => {
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByText('Articles')).toBeInTheDocument()
    const endpoint = screen.getByRole('button', { name: '/api/articles' })
    expect(endpoint).toBeInTheDocument()
    expect(endpoint.className).toContain('decoration-dashed')
  })

  it('copies endpoint and shows toast', async () => {
    const { copyToClipboard } = await import('@/lib/clipboard')
    const user = userEvent.setup()
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    await user.click(await screen.findByRole('button', { name: '/api/articles' }))

    expect(copyToClipboard).toHaveBeenCalledWith('/api/articles')
    expect(await screen.findByRole('status')).toHaveTextContent('Value copied')
  })
})
