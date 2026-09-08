import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { DatePickerField } from './date-picker'

/** Segments are wrapped in bidi isolates, which carry no meaning for assertions. */
function plainText(element: HTMLElement): string {
  return (element.textContent ?? '').replace(/[\u2066-\u2069]/g, '')
}

function wrap(ui: React.ReactNode) {
  return <I18nProvider>{ui}</I18nProvider>
}

function Harness({ initial = '' }: { initial?: string }) {
  const [value, setValue] = useState<string | null>(initial)
  return (
    <>
      <DatePickerField
        value={value}
        format="DD.MM.YYYY"
        onChange={setValue}
        aria-label="Published at"
      />
      <output>{value ?? 'null'}</output>
    </>
  )
}

describe('DatePickerField', () => {
  it('renders the stored value as day-first segments', () => {
    render(wrap(<Harness initial="2026-09-08" />))
    // Separators come from the locale, the segment order from the schema pattern.
    expect(plainText(screen.getByRole('group', { name: 'Published at' }))).toMatch(/^08\D09\D2026$/)
  })

  it('renders the stored datetime with time segments', () => {
    render(
      wrap(
        <DatePickerField
          value="2026-09-08 21:04:07"
          format="DD.MM.YYYY HH:mm"
          granularity="minute"
          onChange={vi.fn()}
          aria-label="Published at"
        />,
      ),
    )
    expect(plainText(screen.getByRole('group', { name: 'Published at' }))).toMatch(
      /^08\D09\D2026\D+21:04$/,
    )
  })

  it('serializes keyboard edits back to the stored format', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness />))

    await user.click(screen.getAllByRole('spinbutton')[0])
    await user.keyboard('08092026')

    expect(screen.getByText('2026-09-08')).toBeInTheDocument()
  })

  it('clears the value', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness initial="2026-09-08" />))

    await user.click(screen.getByRole('button', { name: 'Clear' }))

    expect(screen.getByText('null')).toBeInTheDocument()
  })

  it('picks a day from the calendar popover', async () => {
    const user = userEvent.setup()
    render(wrap(<Harness initial="2026-09-08" />))

    const buttons = screen.getAllByRole('button')
    await user.click(buttons[buttons.length - 1])
    await user.click(screen.getByRole('button', { name: /15/ }))

    expect(screen.getByText('2026-09-15')).toBeInTheDocument()
  })
})
