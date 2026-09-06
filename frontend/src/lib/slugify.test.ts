import { describe, expect, it } from 'vitest'
import { slugifyUrl } from './slugify'

describe('slugifyUrl', () => {
  it('slugifies latin text', () => {
    expect(slugifyUrl('Hello World! 123')).toBe('hello-world-123')
  })

  it('transliterates cyrillic', () => {
    expect(slugifyUrl('Привет мир')).toBe('privet-mir')
  })
})
