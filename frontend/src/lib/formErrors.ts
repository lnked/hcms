import { ApiError } from '@/lib/api'

export type FieldErrors = Record<string, string[]>

/** Normalize API / PHP field payloads (`string` or `string[]`) into `Record<string, string[]>`. */
export function normalizeFieldErrors(
  input: Record<string, string[] | string> | null | undefined,
): FieldErrors {
  if (!input) {
    return {}
  }
  const out: FieldErrors = {}
  for (const [key, value] of Object.entries(input)) {
    if (Array.isArray(value)) {
      const msgs = value.map(String).filter((m) => m !== '')
      if (msgs.length > 0) {
        out[key] = msgs
      }
    } else if (typeof value === 'string' && value !== '') {
      out[key] = [value]
    }
  }
  return out
}

export function apiFieldErrors(err: unknown): FieldErrors {
  if (err instanceof ApiError) {
    return normalizeFieldErrors(err.fields)
  }
  return {}
}

export function firstFieldError(errors: FieldErrors, key: string): string | undefined {
  return errors[key]?.[0]
}

export function hasFieldError(errors: FieldErrors, key: string): boolean {
  return (errors[key]?.length ?? 0) > 0
}
