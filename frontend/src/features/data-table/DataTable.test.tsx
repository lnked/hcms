import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { DataTable } from './DataTable'
import { emptyField } from '@/types/field'

describe('DataTable', () => {
  it('renders rows and fires edit', async () => {
    const user = userEvent.setup()
    const onEdit = vi.fn()
    const onDelete = vi.fn()
    const fields = [{ ...emptyField('string', 0), name: 'title', label: 'Title' }]
    render(
      <DataTable
        fields={fields}
        rows={[{ id: 1, title: 'Hello' }]}
        onEdit={onEdit}
        onDelete={onDelete}
      />,
    )
    expect(screen.getByText('Hello')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Edit' }))
    expect(onEdit).toHaveBeenCalledWith({ id: 1, title: 'Hello' })
  })
})
