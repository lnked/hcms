import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { api, apiPage } from '@/lib/api'
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

const titleField = { ...emptyField('string'), name: 'title', label: 'Title' }

/** Mirrors the real route so the panel is driven by the URL, as in the app. */
function RoutedPanel() {
  const { entryId } = useParams()
  return (
    <ResourceEntriesPanel
      resourceId={1}
      resourceSlug="articles"
      fields={[titleField]}
      published
      entryParam={entryId ?? null}
      entryPath={(entry) => (entry === null ? '/resources/1/data' : `/resources/1/data/${entry}`)}
    />
  )
}

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <I18nProvider initialLocale="en">
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/resources/1/data']}>
          <Routes>
            <Route path="/resources/:id/data/:entryId?" element={<RoutedPanel />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </I18nProvider>,
  )
}

describe('ResourceEntriesPanel import/export', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('opens export and import dialogs', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.click(await screen.findByRole('button', { name: 'Export' }))
    expect(await screen.findByText('Export entries')).toBeInTheDocument()
    expect(screen.getByLabelText('Format')).toBeInTheDocument()
    expect(screen.getByText('title')).toBeInTheDocument()

    const allFieldsToggle = screen.getByRole('button', { name: 'All fields' })
    expect(allFieldsToggle).toHaveAttribute('aria-pressed', 'true')
    await user.click(allFieldsToggle)
    expect(allFieldsToggle).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('checkbox', { name: 'title' })).not.toBeChecked()
    await user.click(allFieldsToggle)
    expect(allFieldsToggle).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('checkbox', { name: 'title' })).toBeChecked()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    await user.click(screen.getByRole('button', { name: 'Import' }))
    expect(await screen.findByText('Import entries')).toBeInTheDocument()
    expect(screen.getByLabelText('Drop a file here or click')).toBeInTheDocument()
    expect(screen.getByLabelText('Or paste content')).toBeInTheDocument()
  })
})

describe('ResourceEntriesPanel entry card routing', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('opens the entry card from its own URL and reuses the listed row', async () => {
    const user = userEvent.setup()
    vi.mocked(apiPage).mockResolvedValueOnce({
      data: [{ id: 7, title: 'Hello' }],
      meta: { page: 1, limit: 20, total: 1, totalPages: 1 },
    })
    renderPanel()

    const editLink = await screen.findByRole('link', { name: 'Edit' })
    expect(editLink).toHaveAttribute('href', '/resources/1/data/7')

    await user.click(editLink)
    expect(await screen.findByText('Edit #7')).toBeInTheDocument()
    expect(screen.getByLabelText(/^Title/)).toHaveValue('Hello')
    expect(api).not.toHaveBeenCalled()
  })
})
