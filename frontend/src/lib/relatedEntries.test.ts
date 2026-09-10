import { describe, expect, it } from 'vitest'
import { entryLabel, type RelatedRow } from './relatedEntries'

describe('entryLabel', () => {
  it('uses the label field when present', () => {
    const row: RelatedRow = { id: 7, name: 'Ada' }
    expect(entryLabel(row, 'name')).toBe('Ada')
  })

  it('falls back to id for missing or object values', () => {
    expect(entryLabel({ id: 3 }, 'name')).toBe('3')
    expect(entryLabel({ id: 3, name: { nested: true } }, 'name')).toBe('3')
  })
})
