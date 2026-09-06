import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '@/i18n'
import { emptyField } from '@/types/field'
import { emptyValues, FormRenderer } from './FormRenderer'

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
    render(
      <I18nProvider initialLocale="en">
        <Harness />
      </I18nProvider>,
    )
    expect(screen.getByLabelText(/Title/)).toBeInTheDocument()
    const checkbox = screen.getByRole('checkbox')
    expect(checkbox).not.toBeChecked()
    await user.click(checkbox)
    expect(checkbox).toBeChecked()
  })
})

describe('emptyValues', () => {
  it('seeds defaults', () => {
    const field = { ...emptyField('string'), name: 'x', default: 'hi' }
    expect(emptyValues([field]).x).toBe('hi')
  })
})
