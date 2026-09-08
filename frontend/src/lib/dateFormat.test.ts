import { describe, expect, it } from 'vitest'
import { formatDateValue } from './dateFormat'

describe('formatDateValue', () => {
  it('renders a date with the schema pattern', () => {
    expect(formatDateValue('2026-09-08', 'DD.MM.YYYY')).toBe('08.09.2026')
    expect(formatDateValue('2026-09-08', 'YYYY/MM/DD')).toBe('2026/09/08')
    expect(formatDateValue('2026-09-08', 'DD.MM.YY')).toBe('08.09.26')
  })

  it('renders time tokens from a datetime value', () => {
    expect(formatDateValue('2026-09-08 21:04:07', 'DD.MM.YYYY HH:mm')).toBe('08.09.2026 21:04')
    expect(formatDateValue('2026-09-08T21:04:07Z', 'HH:mm:ss')).toBe('21:04:07')
  })

  it('defaults missing time parts to zero', () => {
    expect(formatDateValue('2026-09-08', 'DD.MM.YYYY HH:mm')).toBe('08.09.2026 00:00')
  })

  it('returns null for unparsable values', () => {
    expect(formatDateValue('not a date', 'DD.MM.YYYY')).toBeNull()
    expect(formatDateValue(42, 'DD.MM.YYYY')).toBeNull()
    expect(formatDateValue(null, 'DD.MM.YYYY')).toBeNull()
  })
})
