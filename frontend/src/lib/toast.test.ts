import { describe, expect, it, vi } from 'vitest'
import { onToast, showError, showSuccess } from './toast'

describe('toast', () => {
  it('notifies listeners for success and error', () => {
    const listener = vi.fn()
    const unsubscribe = onToast(listener)

    showSuccess('ok')
    showError('boom')

    expect(listener).toHaveBeenNthCalledWith(1, { kind: 'success', message: 'ok' })
    expect(listener).toHaveBeenNthCalledWith(2, { kind: 'error', message: 'boom' })
    unsubscribe()
  })
})
