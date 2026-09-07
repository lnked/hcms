import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { DataTable } from './DataTable'
import { emptyField } from '@/types/field'

describe('DataTable', () => {
  it('renders rows and fires edit', async () => {
    const user = userEvent.setup()
    const onEdit = vi.fn()
    const onDelete = vi.fn()
    const fields = [{ ...emptyField('string', 0), name: 'title', label: 'Title' }]
    render(
      <I18nProvider initialLocale="en">
        <DataTable
          fields={fields}
          rows={[{ id: 1, title: 'Hello' }]}
          onEdit={onEdit}
          onDelete={onDelete}
        />
      </I18nProvider>,
    )
    expect(screen.getByText('Hello')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Edit' }))
    expect(onEdit).toHaveBeenCalledWith({ id: 1, title: 'Hello' })
  })

  it('shows column filter inputs for filterable fields', async () => {
    const user = userEvent.setup()
    const onFilterChange = vi.fn()
    const fields = [
      { ...emptyField('string', 0), name: 'title', label: 'Title', filterable: true },
      { ...emptyField('integer', 1), name: 'views', label: 'Views', filterable: false },
    ]
    render(
      <I18nProvider initialLocale="en">
        <DataTable
          fields={fields}
          rows={[{ id: 1, title: 'Hello', views: 3 }]}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
          filters={{ title: '' }}
          onFilterChange={onFilterChange}
        />
      </I18nProvider>,
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
    render(
      <I18nProvider initialLocale="en">
        <DataTable
          fields={fields}
          rows={[
            { id: 1, title: 'A' },
            { id: 2, title: 'B' },
          ]}
          onEdit={vi.fn()}
          onDelete={vi.fn()}
          selectedIds={[1]}
          onSelectionChange={onSelectionChange}
        />
      </I18nProvider>,
    )
    await user.click(screen.getByLabelText('Select entry #2'))
    expect(onSelectionChange).toHaveBeenCalledWith([1, 2])
  })
})
