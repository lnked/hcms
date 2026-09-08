import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { OAuthCompletePage } from './OAuthCompletePage'

const setToken = vi.fn()
const getToken = vi.fn(() => null)

vi.mock('@/lib/api', () => ({
  api: vi.fn(),
  setToken: (...args: unknown[]) => setToken(...args),
  getToken: () => getToken(),
}))

describe('OAuthCompletePage', () => {
  afterEach(() => {
    window.location.hash = ''
    setToken.mockClear()
  })

  it('stores token from hash', async () => {
    window.location.hash = '#token=abc123'
    render(
      <I18nProvider initialLocale="en">
        <MemoryRouter>
          <OAuthCompletePage />
        </MemoryRouter>
      </I18nProvider>,
    )

    expect(setToken).toHaveBeenCalledWith('abc123')
  })

  it('shows oauth error', async () => {
    window.location.hash = '#error=ACCOUNT_NOT_FOUND'
    render(
      <I18nProvider initialLocale="en">
        <MemoryRouter>
          <OAuthCompletePage />
        </MemoryRouter>
      </I18nProvider>,
    )

    expect(await screen.findByText('No CMS user matches this Google account.')).toBeInTheDocument()
  })
})
