import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

vi.stubGlobal(
  'IntersectionObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
    takeRecords = vi.fn(() => [])
  },
)

afterEach(() => {
  cleanup()
})
