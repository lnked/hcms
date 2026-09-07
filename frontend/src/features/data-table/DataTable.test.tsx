import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { DataTable, type EntryRow } from './DataTable'
import { emptyField } from '@/types/field'

const editHref = (row: EntryRow) => `/resources/1/data/${row.id}`

function renderTable(ui: React.ReactElement) {
  return render(
    <I18nProvider initialLocale="en">
      <MemoryRouter>{ui}</MemoryRouter>
    </I18nProvider>,
  )
}

describe('DataTable', () => {
  it('renders rows and links to the entry editor', () => {
    const onDelete = vi.fn()
    const fields = [{ ...emptyField('string', 0), name: 'title', label: 'Title' }]
    renderTable(
      <DataTable
        fields={fields}
        rows={[{ id: 1, title: 'Hello' }]}
        editHref={editHref}
        onDelete={onDelete}
      />,
    )
    expect(screen.getByText('Hello')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Edit' })).toHaveAttribute(
      'href',
      '/resources/1/data/1',
    )
  })

  it('shows column filter inputs for filterable fields', async () => {
    const user = userEvent.setup()
    const onFilterChange = vi.fn()
    const fields = [
      { ...emptyField('string', 0), name: 'title', label: 'Title', filterable: true },
      { ...emptyField('integer', 1), name: 'views', label: 'Views', filterable: false },
    ]
    renderTable(
      <DataTable
        fields={fields}
        rows={[{ id: 1, title: 'Hello', views: 3 }]}
        editHref={editHref}
        onDelete={vi.fn()}
        filters={{ title: '' }}
        onFilterChange={onFilterChange}
      />,
    )
    const filter = screen.getByLabelText('Filter Title')
    await user.type(filter, 'a')
    expect(onFilterChange).toHaveBeenCalledWith('title', 'a')
    expect(screen.queryByLabelText('Filter Views')).not.toBeInTheDocument()
  })

  it('supports row selection', async () => {
    const user = userEvent.setup()
    const onSelectionChange = vi.fn()
    const fields = [{ ...emptyField('string', 0), name: 'title', label: 'Title' }]
    renderTable(
      <DataTable
        fields={fields}
        rows={[
          { id: 1, title: 'A' },
          { id: 2, title: 'B' },
        ]}
        editHref={editHref}
        onDelete={vi.fn()}
        selectedIds={[1]}
        onSelectionChange={onSelectionChange}
      />,
    )
    await user.click(screen.getByLabelText('Select entry #2'))
    expect(onSelectionChange).toHaveBeenCalledWith([1, 2])
  })
})
