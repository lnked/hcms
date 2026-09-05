import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ChangelogPage } from './ChangelogPage'

vi.mock('@/lib/api', () => ({
  api: vi.fn(async () => [
    {
      version: '0.1.0',
      date: '2026-09-05',
      channel: 'stable',
      title: 'Foundation',
      changes: [{ type: 'added', text: 'Installer' }],
    },
  ]),
}))

describe('ChangelogPage', () => {
  it('renders releases', async () => {
    const client = new QueryClient()
    render(
      <QueryClientProvider client={client}>
        <ChangelogPage />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('v0.1.0')).toBeInTheDocument()
    expect(screen.getByText('Installer')).toBeInTheDocument()
  })
})
