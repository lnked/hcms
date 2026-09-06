import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { emptyField } from '@/types/field'
import { ResourceEntriesPanel } from './ResourceEntriesPanel'

vi.mock('@/lib/api', () => ({
  api: vi.fn(),
  apiPage: vi.fn(async () => ({
    data: [],
    meta: { page: 1, limit: 20, total: 0, totalPages: 1 },
  })),
  getToken: vi.fn(() => null),
  handleUnauthorized: vi.fn(),
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      public code: string,
      message: string,
    ) {
      super(message)
    }
  },
}))

describe('ResourceEntriesPanel import/export', () => {
  it('opens export and import dialogs', async () => {
    const user = userEvent.setup()
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const titleField = { ...emptyField('string'), name: 'title', label: 'Title' }

    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <ResourceEntriesPanel
            resourceId={1}
            resourceSlug="articles"
            fields={[titleField]}
            published
          />
        </QueryClientProvider>
      </I18nProvider>,
    )

    await user.click(await screen.findByRole('button', { name: 'Export' }))
    expect(await screen.findByText('Export entries')).toBeInTheDocument()
    expect(screen.getByLabelText('Format')).toBeInTheDocument()
    expect(screen.getByText('title')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    await user.click(screen.getByRole('button', { name: 'Import' }))
    expect(await screen.findByText('Import entries')).toBeInTheDocument()
    expect(screen.getByLabelText('Or paste content')).toBeInTheDocument()
  })
})
