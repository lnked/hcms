import { CalendarDate, CalendarDateTime } from '@internationalized/date'
import { describe, expect, it } from 'vitest'
import { fromCalendarValue, toCalendarValue } from './dateValue'

describe('toCalendarValue', () => {
  it('parses a stored date', () => {
    expect(toCalendarValue('2026-09-08', 'day')).toEqual(new CalendarDate(2026, 9, 8))
  })

  it('parses a stored datetime with both separators', () => {
    const expected = new CalendarDateTime(2026, 9, 8, 21, 4, 0)
    expect(toCalendarValue('2026-09-08 21:04:07', 'minute')).toEqual(expected)
    expect(toCalendarValue('2026-09-08T21:04:07', 'minute')).toEqual(expected)
  })

  it('keeps seconds only at second granularity', () => {
    expect(toCalendarValue('2026-09-08 21:04:07', 'second')).toEqual(
      new CalendarDateTime(2026, 9, 8, 21, 4, 7),
    )
  })

  it('defaults missing time to midnight', () => {
    expect(toCalendarValue('2026-09-08', 'minute')).toEqual(new CalendarDateTime(2026, 9, 8, 0, 0))
  })

  it('returns null for unusable values', () => {
    expect(toCalendarValue('not a date', 'day')).toBeNull()
    expect(toCalendarValue(null, 'day')).toBeNull()
    expect(toCalendarValue(42, 'day')).toBeNull()
  })
})

describe('fromCalendarValue', () => {
  it('serializes to the stored column format', () => {
    expect(fromCalendarValue(new CalendarDate(2026, 9, 8), 'day')).toBe('2026-09-08')
    expect(fromCalendarValue(new CalendarDateTime(2026, 9, 8, 21, 4), 'minute')).toBe(
      '2026-09-08 21:04:00',
    )
    expect(fromCalendarValue(new CalendarDateTime(2026, 9, 8, 21, 4, 7), 'second')).toBe(
      '2026-09-08 21:04:07',
    )
  })

  it('drops the time part for day granularity', () => {
    expect(fromCalendarValue(new CalendarDateTime(2026, 9, 8, 21, 4), 'day')).toBe('2026-09-08')
  })

  it('round-trips through the calendar value', () => {
    const stored = '2026-01-31 09:05:00'
    expect(fromCalendarValue(toCalendarValue(stored, 'minute'), 'minute')).toBe(stored)
  })

  it('returns null when cleared', () => {
    expect(fromCalendarValue(null, 'day')).toBeNull()
  })
})
