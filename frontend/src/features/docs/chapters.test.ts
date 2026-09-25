import { describe, expect, it } from 'vitest'
import { getChapter, getChapters } from './chapters'

describe('docs chapters role filter', () => {
  it('hides owner chapter from non-owners', () => {
    expect(getChapters('en', 'admin').some((c) => c.id === 'owner')).toBe(false)
    expect(getChapters('en', 'editor').some((c) => c.id === 'owner')).toBe(false)
    expect(getChapter('en', 'owner', 'admin')).toBeUndefined()
  })

  it('shows owner chapter to owners', () => {
    expect(getChapters('en', 'owner').some((c) => c.id === 'owner')).toBe(true)
    expect(getChapter('en', 'owner', 'owner')?.minRole).toBe('owner')
    expect(getChapters('ru', 'owner').some((c) => c.id === 'owner')).toBe(true)
  })

  it('hides owner chapter until role is known', () => {
    expect(getChapters('en', undefined).some((c) => c.id === 'owner')).toBe(false)
    expect(getChapters('en', null).some((c) => c.id === 'owner')).toBe(false)
  })
})
