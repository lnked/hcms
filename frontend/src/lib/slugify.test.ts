import { describe, expect, it } from 'vitest'
import { slugifyIdentifier, slugifyUrl } from './slugify'

describe('slugifyUrl', () => {
  it('slugifies latin text', () => {
    expect(slugifyUrl('Hello World! 123')).toBe('hello-world-123')
  })

  it('transliterates cyrillic', () => {
    expect(slugifyUrl('Привет мир')).toBe('privet-mir')
  })

  it('maps cyrillic look-alikes inside a latin word', () => {
    expect(slugifyUrl('Мой сover')).toBe('moy-cover')
  })
})

describe('slugifyIdentifier', () => {
  it('produces snake_case', () => {
    expect(slugifyIdentifier('Contact Email')).toBe('contact_email')
  })

  it('maps cyrillic look-alikes inside a latin word', () => {
    expect(slugifyIdentifier('сrop')).toBe('crop')
    expect(slugifyIdentifier('thumbнail')).toBe('thumbhail')
  })

  it('still transliterates fully cyrillic words', () => {
    expect(slugifyIdentifier('Превью карточки')).toBe('prevyu_kartochki')
  })

  it('drops leading non-letters and honours maxLength', () => {
    expect(slugifyIdentifier('_1thumb')).toBe('thumb')
    expect(slugifyIdentifier('averyverylongprefix', 8)).toBe('averyver')
  })

  it('keeps a trailing separator so multi-word names stay typable', () => {
    expect(slugifyIdentifier('my_')).toBe('my_')
    expect(slugifyIdentifier('my ')).toBe('my_')
  })
})
