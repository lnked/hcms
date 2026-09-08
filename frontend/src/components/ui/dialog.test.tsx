import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '@/i18n'
import { DatePickerField } from './date-picker'
import { Dialog, DialogContent, DialogTitle } from './dialog'

function Harness() {
  const [value, setValue] = useState<string | null>('2026-09-08')
  return (
    <I18nProvider initialLocale="en">
      <Dialog open>
        <DialogContent>
          <DialogTitle>Entry</DialogTitle>
          <DatePickerField
            value={value}
            format="DD.MM.YYYY"
            onChange={setValue}
            aria-label="Published at"
          />
          <output>{value ?? 'null'}</output>
        </DialogContent>
      </Dialog>
    </I18nProvider>
  )
}

describe('DialogContent', () => {
  /**
   * On document.body the popover sits under Radix' `pointer-events: none` and outside its
   * focus trap, so days are unclickable and the first interaction dismisses it.
   */
  it('hosts React Aria overlays inside the modal instead of document.body', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    const group = screen.getByRole('group', { name: 'Published at' })
    await user.click(within(group).getAllByRole('button').at(-1) as HTMLElement)

    const modal = screen.getByRole('dialog', { name: 'Entry' })
    expect(modal.contains(screen.getByRole('grid'))).toBe(true)

    await user.click(screen.getByRole('button', { name: /15/ }))
    expect(screen.getByText('2026-09-15')).toBeInTheDocument()
  })
})
