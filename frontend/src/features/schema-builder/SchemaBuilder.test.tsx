import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { SchemaBuilder } from './SchemaBuilder'
import { useState } from 'react'
import type { SchemaField } from '@/types/field'
import { I18nProvider } from '@/i18n'

function Harness() {
  const [schema, setSchema] = useState<SchemaField[]>([])
  return <SchemaBuilder schema={schema} onChange={setSchema} />
}

describe('SchemaBuilder', () => {
  it('adds a field', async () => {
    const user = userEvent.setup()
    render(
      <I18nProvider initialLocale="en">
        <Harness />
      </I18nProvider>,
    )
    await user.click(screen.getByRole('button', { name: /Add field/i }))
    expect(screen.getByText('Untitled')).toBeInTheDocument()
  })
})
