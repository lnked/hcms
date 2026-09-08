import { render, screen, within } from '@testing-library/react'
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

  it('filters a boolean column with a tri-state checkbox', async () => {
    const user = userEvent.setup()
    const onFilterChange = vi.fn()
    const fields = [
      { ...emptyField('boolean', 0), name: 'isPublished', label: 'Published', filterable: true },
    ]
    const { rerender } = renderTable(
      <DataTable
        fields={fields}
        rows={[{ id: 1, isPublished: true }]}
        editHref={editHref}
        onDelete={vi.fn()}
        filters={{}}
        onFilterChange={onFilterChange}
      />,
    )
    const checkbox = screen.getByLabelText('Filter Published') as HTMLInputElement
    expect(checkbox.indeterminate).toBe(true)
    await user.click(checkbox)
    expect(onFilterChange).toHaveBeenLastCalledWith('isPublished', '1')

    rerender(
      <I18nProvider initialLocale="en">
        <MemoryRouter>
          <DataTable
            fields={fields}
            rows={[{ id: 1, isPublished: true }]}
            editHref={editHref}
            onDelete={vi.fn()}
            filters={{ isPublished: '1' }}
            onFilterChange={onFilterChange}
          />
        </MemoryRouter>
      </I18nProvider>,
    )
    await user.click(screen.getByLabelText('Filter Published'))
    expect(onFilterChange).toHaveBeenLastCalledWith('isPublished', '0')
  })

  it('filters a date column from the calendar popover', async () => {
    const user = userEvent.setup()
    const onFilterChange = vi.fn()
    const fields = [
      {
        ...emptyField('date', 0),
        name: 'publishedAt',
        label: 'Published at',
        filterable: true,
        config: { format: 'DD.MM.YYYY' },
      },
    ]
    renderTable(
      <DataTable
        fields={fields}
        rows={[{ id: 1, publishedAt: '2026-09-08' }]}
        editHref={editHref}
        onDelete={vi.fn()}
        filters={{ publishedAt: '2026-09-08' }}
        onFilterChange={onFilterChange}
      />,
    )
    const group = screen.getByRole('group', { name: 'Filter Published at' })
    const calendarButton = within(group).getAllByRole('button').at(-1) as HTMLElement
    await user.click(calendarButton)
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /15/ }))

    expect(onFilterChange).toHaveBeenLastCalledWith('publishedAt', '2026-09-15')
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

  it('applies the saved column layout', () => {
    const fields = [
      { ...emptyField('string', 0), name: 'title', label: 'Title' },
      { ...emptyField('string', 1), name: 'author', label: 'Author' },
    ]
    renderTable(
      <DataTable
        fields={fields}
        columns={[
          { field: 'author', visible: true, label: 'Written by' },
          { field: 'title', visible: false },
        ]}
        rows={[{ id: 1, title: 'Hello', author: 'Ann' }]}
        editHref={editHref}
        onDelete={vi.fn()}
      />,
    )
    expect(screen.getByText('Written by')).toBeInTheDocument()
    expect(screen.queryByText('Title')).not.toBeInTheDocument()
    expect(screen.queryByText('Hello')).not.toBeInTheDocument()
    expect(screen.getByText('Ann')).toBeInTheDocument()
  })

  it('links a relation to the related entry in a new tab', () => {
    const fields = [
      {
        ...emptyField('relation', 0),
        name: 'author',
        label: 'Author',
        config: { cardinality: 'manyToOne', relatedSlug: 'authors', labelField: 'name' },
      },
    ]
    renderTable(
      <DataTable
        fields={fields}
        relations={{ author: { resourceId: 3, labels: { 7: 'Ann' } } }}
        rows={[
          { id: 1, author: 7 },
          { id: 2, author: null },
        ]}
        editHref={editHref}
        onDelete={vi.fn()}
      />,
    )
    const link = screen.getByRole('link', { name: /Ann/ })
    expect(link).toHaveAttribute('href', '/resources/3/data/7')
    expect(link).toHaveAttribute('target', '_blank')
    expect(screen.getByText('#7')).toBeInTheDocument()
    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('renders image thumbnails instead of raw json', () => {
    const fields = [{ ...emptyField('image', 0), name: 'cover', label: 'Cover' }]
    renderTable(
      <DataTable
        fields={fields}
        rows={[
          {
            id: 1,
            cover: {
              id: 7,
              rotation: 0,
              positions: {},
              variants: { thumb: { id: 8, url: '/media/8', width: 100 } },
              media: { id: 7, url: '/media/7', mime: 'image/png', originalName: 'cover.png' },
            },
          },
        ]}
        editHref={editHref}
        onDelete={vi.fn()}
      />,
    )
    expect(screen.getByRole('link', { name: 'cover.png' })).toHaveAttribute('href', '/media/7')
    expect(document.querySelector('img[src="/media/8"]')).not.toBeNull()
    expect(screen.queryByText(/"variants"/)).not.toBeInTheDocument()
  })
})
