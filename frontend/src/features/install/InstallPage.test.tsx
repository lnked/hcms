import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '@/i18n'
import { InstallPage } from './InstallPage'

vi.mock('@/lib/api', () => ({
  installApi: vi.fn(async (action: string) => {
    if (action === 'status') {
      return {
        installed: false,
        srcReady: true,
        version: '0.1.0',
        requirements: {
          ok: true,
          phpVersion: '8.3.0',
          checks: { php: true, writable: true },
        },
      }
    }
    return {}
  }),
}))

describe('InstallPage', () => {
  it('skips files step when src is ready', async () => {
    render(
      <I18nProvider initialLocale="en">
        <InstallPage />
      </I18nProvider>,
    )
    expect(await screen.findByText('Install HCMS')).toBeInTheDocument()
    expect(await screen.findByText(/Step 2 \/ 4: Database/)).toBeInTheDocument()
    expect(await screen.findByText(/Files already present/)).toBeInTheDocument()
  })
})
