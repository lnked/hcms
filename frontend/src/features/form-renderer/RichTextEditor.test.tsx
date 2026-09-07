import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { RichTextEditor } from './RichTextEditor'

describe('RichTextEditor', () => {
  it('renders textarea and toolbar', () => {
    const onChange = vi.fn()
    render(
      <I18nProvider initialLocale="en">
        <RichTextEditor id="body" value="hello" onChange={onChange} />
      </I18nProvider>,
    )

    expect(screen.getByRole('textbox')).toHaveValue('hello')
    expect(screen.getByRole('button', { name: 'Bold' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Italic' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Preview' })).toBeInTheDocument()
  })
})
