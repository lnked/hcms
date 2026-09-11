import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/api'
import {
  apiFieldErrors,
  clearFieldError,
  firstFieldError,
  hasFieldError,
  normalizeFieldErrors,
} from '@/lib/formErrors'

describe('formErrors', () => {
  it('normalizes string and string[] field maps', () => {
    expect(
      normalizeFieldErrors({
        slug: 'required',
        label: ['too long', 'invalid'],
        empty: '',
        blank: [],
      }),
    ).toEqual({
      slug: ['required'],
      label: ['too long', 'invalid'],
    })
  })

  it('reads fields from ApiError', () => {
    const err = new ApiError(422, 'VALIDATION_ERROR', 'Validation failed', {
      targetUrl: ['must be a valid URL'],
    })
    expect(apiFieldErrors(err)).toEqual({ targetUrl: ['must be a valid URL'] })
    expect(apiFieldErrors(new Error('nope'))).toEqual({})
  })

  it('exposes helpers for aria-invalid', () => {
    const errors = { slug: ['taken'] }
    expect(hasFieldError(errors, 'slug')).toBe(true)
    expect(hasFieldError(errors, 'label')).toBe(false)
    expect(firstFieldError(errors, 'slug')).toBe('taken')
  })

  it('clearFieldError drops one key and preserves reference when absent', () => {
    const errors = { slug: ['taken'], label: ['required'] }
    expect(clearFieldError(errors, 'missing')).toBe(errors)
    expect(clearFieldError(errors, 'slug')).toEqual({ label: ['required'] })
    expect(clearFieldError(errors, 'slug')).not.toBe(errors)
  })
})
