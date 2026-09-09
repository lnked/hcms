import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AppToast } from '@/components/AppToast'
import { I18nProvider } from '@/i18n'
import { UsersPage } from './UsersPage'

vi.mock('@/lib/api', () => ({
  getToken: vi.fn(() => 'test-token'),
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/auth/me') {
      return {
        id: 1,
        name: 'Admin',
        email: 'admin@example.com',
        role: 'owner',
        totpEnabled: false,
      }
    }
    if (path === '/admin/api/users') return []
    return null
  }),
}))

vi.mock('@/lib/clipboard', async () => {
  const actual = await vi.importActual<typeof import('@/lib/clipboard')>('@/lib/clipboard')
  return {
    ...actual,
    copyToClipboard: vi.fn(async (text: string) => {
      await actual.copyToClipboard(text)
    }),
  }
})

describe('UsersPage', () => {
  beforeEach(() => {
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: vi.fn(async () => undefined),
      },
    })
  })

  it('renders title', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <UsersPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Users' })).toBeInTheDocument()
  })

  it('generates a password, copies it, and shows a toast', async () => {
    const { copyToClipboard } = await import('@/lib/clipboard')
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <UsersPage />
          <AppToast />
        </QueryClientProvider>
      </I18nProvider>,
    )

    await userEvent.click(await screen.findByRole('button', { name: 'Create user' }))
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }))

    const password = (screen.getByLabelText('Password') as HTMLInputElement).value
    expect(password).toHaveLength(20)
    expect(copyToClipboard).toHaveBeenCalledWith(password)
    const toast = await screen.findByText('Value copied')
    expect(toast).toHaveClass('bg-success')
  })
})
