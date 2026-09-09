import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { AppToast } from '@/components/AppToast'
import { I18nProvider } from '@/i18n'
import { ResourcesPage } from './ResourcesPage'

vi.mock('@/lib/api', () => ({
  getToken: vi.fn(() => 'test-token'),
  api: vi.fn(async (path: string) => {
    if (path === '/admin/api/auth/me') {
      return {
        id: 1,
        name: 'Admin',
        email: 'admin@example.com',
        role: 'owner',
        changelogSeenVersion: null,
        aclEnabled: false,
        sections: [],
        resourceGrants: [],
      }
    }
    return [
      {
        id: 1,
        contentTypeId: 1,
        slug: 'articles',
        endpoint: '/api/articles',
        apiVersion: 'v1',
        status: 'draft',
        schemaVersion: 0,
        settings: {
          apiEnabled: true,
          public: { read: false, create: false, update: false, delete: false },
          pagination: true,
          search: true,
          sorting: true,
          filtering: true,
          deleteStrategy: 'hard',
          softDelete: false,
        },
        label: 'Articles',
        contentTypeSlug: 'articles',
        isSystem: false,
        createdAt: '2026-09-05 00:00:00',
        updatedAt: '2026-09-05 00:00:00',
      },
    ]
  }),
}))

const toastListeners = new Set<(toast: { kind: string; message: string }) => void>()

vi.mock('@/lib/toast', () => ({
  onToast: (listener: (toast: { kind: string; message: string }) => void) => {
    toastListeners.add(listener)
    return () => {
      toastListeners.delete(listener)
    }
  },
  showSuccess: (message: string) => {
    for (const listener of toastListeners) {
      listener({ kind: 'success', message })
    }
  },
  showError: (message: string) => {
    for (const listener of toastListeners) {
      listener({ kind: 'error', message })
    }
  },
  showToast: (kind: string, message: string) => {
    for (const listener of toastListeners) {
      listener({ kind, message })
    }
  },
}))

vi.mock('@/lib/clipboard', async () => {
  const { showSuccess } = await import('@/lib/toast')
  return {
    isCopiedToastMessage: (message: string) => message === '__hcms_copied__',
    copyToClipboard: vi.fn(async () => {
      showSuccess('__hcms_copied__')
    }),
  }
})

describe('ResourcesPage', () => {
  it('lists resources', async () => {
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    expect(await screen.findByText('Articles')).toBeInTheDocument()
    const endpoint = screen.getByRole('button', { name: '/api/articles' })
    expect(endpoint).toBeInTheDocument()
    expect(endpoint.className).toContain('decoration-dashed')
    expect(screen.getByRole('button', { name: 'Usage example' })).toBeInTheDocument()
    expect(document.querySelector('.docs-code')).toBeNull()
  })

  it('expands fetch example on click', async () => {
    const user = userEvent.setup()
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    await user.click(await screen.findByRole('button', { name: 'Usage example' }))

    expect(document.querySelector('.docs-code')?.textContent).toMatch(/fetch\('/)
  })

  it('copies endpoint and shows toast', async () => {
    const { copyToClipboard } = await import('@/lib/clipboard')
    const user = userEvent.setup()
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
            <AppToast />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    await user.click(await screen.findByRole('button', { name: '/api/articles' }))

    expect(copyToClipboard).toHaveBeenCalledWith('/api/articles')
    expect(await screen.findByRole('status')).toHaveTextContent('Value copied')
  })

  it('copies fetch example', async () => {
    const { copyToClipboard } = await import('@/lib/clipboard')
    const user = userEvent.setup()
    const client = new QueryClient()
    render(
      <I18nProvider initialLocale="en">
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <ResourcesPage />
          </MemoryRouter>
        </QueryClientProvider>
      </I18nProvider>,
    )

    await user.click(await screen.findByRole('button', { name: 'Usage example' }))
    await user.click(await screen.findByRole('button', { name: 'Copy' }))

    expect(copyToClipboard).toHaveBeenCalledWith(expect.stringContaining("fetch('"))
    expect(copyToClipboard).toHaveBeenCalledWith(expect.stringContaining('/api/articles?limit=20'))
  })
})
