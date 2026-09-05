import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
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

describe('ResourcesPage', () => {
  it('lists resources', async () => {
    const client = new QueryClient()
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <ResourcesPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Articles')).toBeInTheDocument()
    expect(screen.getByText('/api/articles')).toBeInTheDocument()
  })
})
