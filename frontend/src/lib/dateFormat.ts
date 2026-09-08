/** Moment-style tokens supported in `date`/`datetime` field configs. */
const TOKEN_RE = /YYYY|YY|MM|DD|HH|mm|ss/g

const ISO_RE = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/

/** Smallest editable unit implied by a schema pattern. */
export type DateGranularity = 'day' | 'minute' | 'second'

export interface DateParts {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

/**
 * Splits a stored date/datetime (`YYYY-MM-DD[ HH:mm[:ss]]`) into calendar parts.
 * Parsed textually to keep the stored wall-clock time free of timezone shifts.
 */
export function parseDateParts(value: unknown): DateParts | null {
  if (typeof value !== 'string') return null
  const match = ISO_RE.exec(value.trim())
  if (!match) return null

  return {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4] ?? '0'),
    minute: Number(match[5] ?? '0'),
    second: Number(match[6] ?? '0'),
  }
}

/**
 * Renders a stored date/datetime with the schema pattern.
 * Returns `null` when the value is not a recognizable date.
 */
export function formatDateValue(value: unknown, format: string): string | null {
  const parts = parseDateParts(value)
  if (!parts) return null

  const pad = (n: number) => String(n).padStart(2, '0')
  const year = String(parts.year).padStart(4, '0')
  const tokens: Record<string, string> = {
    YYYY: year,
    YY: year.slice(-2),
    MM: pad(parts.month),
    DD: pad(parts.day),
    HH: pad(parts.hour),
    mm: pad(parts.minute),
    ss: pad(parts.second),
  }

  return format.replace(TOKEN_RE, (token) => tokens[token] ?? token)
}

/** `DD.MM.YYYY` → `day`, `… HH:mm` → `minute`, `… HH:mm:ss` → `second`. */
export function dateGranularity(format: string, fallback: DateGranularity): DateGranularity {
  if (format.includes('ss')) return 'second'
  if (format.includes('HH') || format.includes('mm')) return 'minute'
  if (format.includes('DD') || format.includes('MM') || format.includes('YY')) return 'day'
  return fallback
}

/**
 * Picks a BCP 47 locale whose segment order matches the schema pattern, so the
 * picker reads like the table column. Month- and year-first patterns have no
 * Russian equivalent, so they fall back to English month names.
 */
export function dateFieldLocale(format: string, language: string): string {
  const first = (format.match(/YYYY|YY|MM|DD/g) ?? [])[0]
  if (first === 'MM') return 'en-US'
  if (first === 'YYYY' || first === 'YY') return 'en-CA'
  return language === 'ru' ? 'ru-RU' : 'en-GB'
}
