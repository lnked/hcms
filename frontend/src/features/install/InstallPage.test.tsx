import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
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
  it('shows first wizard step', async () => {
    render(<InstallPage />)
    expect(await screen.findByText('Install HCMS')).toBeInTheDocument()
    expect(screen.getByText(/Step 1/)).toBeInTheDocument()
    expect(await screen.findByText(/php: ok/)).toBeInTheDocument()
  })
})
