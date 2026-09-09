import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/toast', () => ({
  showSuccess: vi.fn(),
  showError: vi.fn(),
  showToast: vi.fn(),
  onToast: vi.fn(() => () => undefined),
}))

describe('copyToClipboard', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('uses Clipboard API when available', async () => {
    const { copyToClipboard } = await import('./clipboard')
    const { showSuccess } = await import('./toast')
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })

    await copyToClipboard('/api/articles')

    expect(writeText).toHaveBeenCalledWith('/api/articles')
    expect(showSuccess).toHaveBeenCalled()
  })

  it('falls back to execCommand when Clipboard API throws', async () => {
    const { copyToClipboard } = await import('./clipboard')
    const writeText = vi.fn().mockRejectedValue(new Error('NotAllowedError'))
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    Object.defineProperty(document, 'execCommand', {
      configurable: true,
      value: vi.fn().mockReturnValue(true),
    })

    const dialog = document.createElement('div')
    dialog.setAttribute('role', 'dialog')
    document.body.appendChild(dialog)
    const btn = document.createElement('button')
    dialog.appendChild(btn)
    btn.focus()

    await copyToClipboard('/api/articles')

    expect(document.execCommand).toHaveBeenCalledWith('copy')
    expect(dialog.contains(document.querySelector('textarea'))).toBe(false)
    dialog.remove()
  })

  it('throws when both Clipboard API and execCommand fail', async () => {
    const { copyToClipboard } = await import('./clipboard')
    const writeText = vi.fn().mockRejectedValue(new Error('NotAllowedError'))
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    Object.defineProperty(document, 'execCommand', {
      configurable: true,
      value: vi.fn().mockReturnValue(false),
    })

    await expect(copyToClipboard('/api/articles')).rejects.toThrow('Copy failed')
  })
})
