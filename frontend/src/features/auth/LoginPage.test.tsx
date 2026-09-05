import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LoginPage } from './LoginPage'

const setToken = vi.fn()

vi.mock('@/lib/api', () => ({
  api: vi.fn(async () => ({ token: 'abc123', user: { id: 1, name: 'A', email: 'a@b.c' } })),
  setToken: (...args: unknown[]) => setToken(...args),
}))

describe('LoginPage', () => {
  beforeEach(() => {
    setToken.mockClear()
  })

  it('submits credentials and stores token', async () => {
    const user = userEvent.setup()
    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>,
    )

    await user.type(screen.getByLabelText('Email'), 'admin@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret12')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(setToken).toHaveBeenCalledWith('abc123')
  })
})
