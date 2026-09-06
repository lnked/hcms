import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { LoginPage } from './LoginPage'

const setToken = vi.fn()

vi.mock('@/lib/api', () => ({
  api: vi.fn(async () => ({ token: 'abc123', user: { id: 1, name: 'A', email: 'a@b.c' } })),
  setToken: (...args: unknown[]) => setToken(...args),
  clearToken: vi.fn(),
}))

describe('LoginPage', () => {
  beforeEach(() => {
    setToken.mockClear()
  })

  it('submits credentials and stores token', async () => {
    const user = userEvent.setup()
    render(
      <I18nProvider initialLocale="en">
        <MemoryRouter>
          <LoginPage />
        </MemoryRouter>
      </I18nProvider>,
    )

    await user.type(screen.getByLabelText('Email'), 'admin@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret12')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(setToken).toHaveBeenCalledWith('abc123')
  })
})
