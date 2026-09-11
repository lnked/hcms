import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '@/i18n'
import { emptyField } from '@/types/field'
import { emptyValues, FormRenderer } from './FormRenderer'

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

function Harness() {
  const fields = [
    { ...emptyField('string', 0), name: 'title', label: 'Title', required: true },
    { ...emptyField('boolean', 1), name: 'active', label: 'Active' },
  ]
  const [values, setValues] = useState(emptyValues(fields))
  return <FormRenderer fields={fields} values={values} onChange={setValues} />
}

describe('FormRenderer', () => {
  it('renders writable fields and toggles boolean', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness />))
    expect(screen.getByLabelText(/Title/)).toBeInTheDocument()
    const checkbox = screen.getByRole('checkbox')
    expect(checkbox).not.toBeChecked()
    await user.click(checkbox)
    expect(checkbox).toBeChecked()
  })

  it('renders richtext editor with edit/preview tabs', async () => {
    const user = userEvent.setup()
    const fields = [{ ...emptyField('richtext', 0), name: 'body', label: 'Body' }]

    function RichtextHarness() {
      const [values, setValues] = useState<Record<string, unknown>>({ body: '**Hello**' })
      return <FormRenderer fields={fields} values={values} onChange={setValues} />
    }

    render(wrap(<RichtextHarness />))
    expect(screen.getByLabelText(/^Body/)).toHaveValue('**Hello**')
    await user.click(screen.getByRole('button', { name: /Preview/i }))
    expect(screen.getByText('Hello')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /^Edit$/i }))
    expect(screen.getByLabelText(/^Body/)).toBeInTheDocument()
  })
})

describe('emptyValues', () => {
  it('seeds defaults', () => {
    const field = { ...emptyField('string'), name: 'x', default: 'hi' }
    expect(emptyValues([field]).x).toBe('hi')
  })
})

describe('slug auto-fill', () => {
  it('fills slug from associated field in realtime even when editing existing values', () => {
    const fields = [
      { ...emptyField('string', 0), name: 'title', label: 'Title' },
      {
        ...emptyField('slug', 1),
        name: 'slug',
        label: 'Slug',
        config: { associatedWith: 'title', maxLength: 255 },
      },
    ]

    function SlugHarness() {
      const [values, setValues] = useState<Record<string, unknown>>({
        title: 'Old Title',
        slug: 'old-title',
      })
      return <FormRenderer fields={fields} values={values} onChange={setValues} />
    }

    render(wrap(<SlugHarness />))

    fireEvent.change(screen.getByLabelText(/^Title/), { target: { value: 'Hello World' } })
    expect(screen.getByLabelText(/^Slug/)).toHaveValue('hello-world')

    fireEvent.change(screen.getByLabelText(/^Slug/), { target: { value: 'custom-slug' } })
    fireEvent.change(screen.getByLabelText(/^Title/), { target: { value: 'Hello World Extra' } })
    expect(screen.getByLabelText(/^Slug/)).toHaveValue('custom-slug')
  })
})
