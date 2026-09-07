import { describe, expect, it } from 'vitest'
import { emptyField } from '@/types/field'
import { mergeColumns, resolveColumns } from './columns'

function schema(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    ...emptyField('string', i),
    name: `f${i}`,
    label: `Field ${i}`,
  }))
}

describe('mergeColumns', () => {
  it('shows the first six fields when nothing is saved', () => {
    const merged = mergeColumns(schema(8), undefined)
    expect(merged.map((c) => c.visible)).toEqual([true, true, true, true, true, true, false, false])
  })

  it('keeps the saved order and hides fields added to the schema later', () => {
    const merged = mergeColumns(schema(3), [
      { field: 'f2', visible: true },
      { field: 'f0', visible: false },
    ])
    expect(merged).toEqual([
      { field: 'f2', visible: true, label: null, width: null },
      { field: 'f0', visible: false, label: null, width: null },
      { field: 'f1', visible: false, label: null, width: null },
    ])
  })

  it('drops saved columns for fields removed from the schema', () => {
    const merged = mergeColumns(schema(1), [
      { field: 'gone', visible: true },
      { field: 'f0', visible: true },
    ])
    expect(merged.map((c) => c.field)).toEqual(['f0'])
  })
})

describe('resolveColumns', () => {
  it('returns visible columns with label and width overrides', () => {
    const resolved = resolveColumns(schema(2), [
      { field: 'f1', visible: true, label: 'Custom', width: 320 },
      { field: 'f0', visible: false },
    ])
    expect(resolved).toHaveLength(1)
    expect(resolved[0].label).toBe('Custom')
    expect(resolved[0].width).toBe(320)
  })

  it('skips fields the API never returns', () => {
    const fields = [
      { ...emptyField('string', 0), name: 'visible' },
      { ...emptyField('string', 1), name: 'secret', hidden: true },
      { ...emptyField('string', 2), name: 'writeOnly', readable: false },
    ]
    expect(mergeColumns(fields, undefined).map((c) => c.field)).toEqual(['visible'])
  })
})
