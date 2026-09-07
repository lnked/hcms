import '@testing-library/jest-dom/vitest'
import { createElement } from 'react'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

vi.mock('@lottiefiles/dotlottie-react', () => ({
  DotLottieReact: ({ className }: { className?: string }) =>
    createElement('div', { 'data-testid': 'dotlottie-mock', className, 'aria-hidden': true }),
}))

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
