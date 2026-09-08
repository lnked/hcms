import {
  CalendarDate,
  CalendarDateTime,
  type DateValue,
  toCalendarDateTime,
} from '@internationalized/date'
import { type DateGranularity, parseDateParts } from './dateFormat'

const pad = (value: number, length = 2) => String(value).padStart(length, '0')

/** Stored string → calendar value. Wall-clock semantics, matching MySQL `DATE`/`DATETIME`. */
export function toCalendarValue(
  value: unknown,
  granularity: DateGranularity,
): CalendarDate | CalendarDateTime | null {
  const parts = parseDateParts(value)
  if (!parts) return null
  if (granularity === 'day') return new CalendarDate(parts.year, parts.month, parts.day)
  return new CalendarDateTime(
    parts.year,
    parts.month,
    parts.day,
    parts.hour,
    parts.minute,
    granularity === 'second' ? parts.second : 0,
  )
}

/** Calendar value → stored string: `YYYY-MM-DD` for dates, `YYYY-MM-DD HH:mm:ss` otherwise. */
export function fromCalendarValue(
  value: DateValue | null,
  granularity: DateGranularity,
): string | null {
  if (!value) return null
  const date = `${pad(value.year, 4)}-${pad(value.month)}-${pad(value.day)}`
  if (granularity === 'day') return date
  const time = toCalendarDateTime(value)
  return `${date} ${pad(time.hour)}:${pad(time.minute)}:${pad(time.second)}`
}
