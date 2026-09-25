import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AppToast } from '@/components/AppToast'
import { I18nProvider } from '@/i18n'
import { requireInput } from '@/test/dom'
import { AccountPage } from './AccountPage'

const api = vi.fn(async (path: string, init?: RequestInit) => {
  if (path === '/admin/api/auth/providers') {
    return {
      google: { enabled: true, clientId: 'cid' },
      telegram: { enabled: false, botUsername: '' },
    }
  }
  if (path === '/admin/api/integrations/oauth') {
    return {
      google: {
        enabled: true,
        clientId: 'cid',
        clientSecretConfigured: true,
        clientSecretMasked: '****cid',
        redirectUri: 'http://localhost/admin/api/auth/google/callback',
      },
      telegram: {
        enabled: false,
        botUsername: '',
        botTokenConfigured: false,
        botTokenMasked: null,
      },
    }
  }
  if (path === '/admin/api/auth/identities' && init?.method === 'DELETE') {
    return [
      { provider: 'google', linked: false, label: null },
      { provider: 'telegram', linked: false, label: null },
    ]
  }
  if (path === '/admin/api/auth/identities') {
    return [
      { provider: 'google', linked: true, label: 'ada@example.com' },
      { provider: 'telegram', linked: false, label: null },
    ]
  }
  if (path === '/admin/api/auth/me') {
    return { id: 1, name: 'Ada', email: 'ada@example.com', role: 'owner', totpEnabled: false }
  }
  if (path === '/admin/api/auth/password') {
    return { ok: true, revokedSessions: 2 }
  }
  return null
})

vi.mock('@/lib/api', () => ({
  api: (...args: unknown[]) => api(...(args as [string, RequestInit?])),
  getToken: () => 'test-token',
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      public code: string,
      message: string,
      public fields: Record<string, string[]> = {},
    ) {
      super(message)
    }
  },
}))

describe('AccountPage', () => {
  beforeEach(() => {
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: vi.fn(async () => undefined),
      },
    })
  })

  it('shows linked google and disconnects', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <AccountPage />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Account' })).toBeInTheDocument()
    expect(await screen.findByText('ada@example.com')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }))
    expect(api).toHaveBeenCalledWith('/admin/api/auth/identities/google', { method: 'DELETE' })
  })

  it('changes the password in a dialog and closes it on success', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <AccountPage />
          <AppToast />
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Security' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Change password' }))

    const submit = screen.getByRole('button', { name: 'Save' })
    expect(submit).toBeDisabled()

    await userEvent.type(screen.getByLabelText('Current password'), 'old-secret1')
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }))
    const generated = requireInput(screen.getByLabelText('New password')).value
    expect(generated).toHaveLength(20)
    expect(requireInput(screen.getByLabelText('Repeat new password')).value).toBe(generated)
    expect(await screen.findByText('Value copied')).toBeInTheDocument()

    expect(submit).toBeEnabled()
    await userEvent.click(submit)

    expect(api).toHaveBeenCalledWith('/admin/api/auth/password', {
      method: 'POST',
      body: JSON.stringify({ currentPassword: 'old-secret1', newPassword: generated }),
    })

    const toast = await screen.findByText(/Password changed/)
    expect(toast.className).toMatch(/success/)
    await waitFor(() => expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument())
  })
})
