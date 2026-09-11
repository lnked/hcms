import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '@/i18n'
import { SchemaBuilder } from './SchemaBuilder'
import type { SchemaField } from '@/types/field'

function Harness() {
  const [schema, setSchema] = useState<SchemaField[]>([])
  return <SchemaBuilder schema={schema} onChange={setSchema} />
}

function wrap(ui: React.ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return (
    <QueryClientProvider client={client}>
      <I18nProvider initialLocale="en">{ui}</I18nProvider>
    </QueryClientProvider>
  )
}

describe('SchemaBuilder', () => {
  it('adds a field', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness />))
    await user.click(screen.getByRole('button', { name: /Add field/i }))
    expect(screen.getByText('Untitled')).toBeInTheDocument()
  })

  it('keeps focus while typing the field name', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness />))
    await user.click(screen.getByRole('button', { name: /Add field/i }))

    const nameInput = screen.getByPlaceholderText('title')
    await user.click(nameInput)
    await user.keyboard('hello')

    expect(nameInput).toHaveFocus()
    expect(nameInput).toHaveValue('hello')
  })
})
