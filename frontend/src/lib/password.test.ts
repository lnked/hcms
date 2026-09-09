import { describe, expect, it } from 'vitest'
import {
  GENERATED_PASSWORD_LENGTH,
  generatePassword,
  meetsPasswordPolicy,
  PASSWORD_MIN_LENGTH,
} from './password'

describe('generatePassword', () => {
  it('honours the requested length and never goes below the policy minimum', () => {
    expect(generatePassword()).toHaveLength(GENERATED_PASSWORD_LENGTH)
    expect(generatePassword(32)).toHaveLength(32)
    expect(generatePassword(3)).toHaveLength(PASSWORD_MIN_LENGTH)
  })

  it('always includes a letter, a digit and a symbol', () => {
    for (let i = 0; i < 50; i += 1) {
      const password = generatePassword()
      expect(password).toMatch(/[a-z]/)
      expect(password).toMatch(/[A-Z]/)
      expect(password).toMatch(/\d/)
      expect(password).toMatch(/[!@#$%^&*\-_=+?]/)
      expect(meetsPasswordPolicy(password)).toBe(true)
    }
  })

  it('avoids look-alike characters', () => {
    for (let i = 0; i < 50; i += 1) {
      expect(generatePassword()).not.toMatch(/[0O1lI]/)
    }
  })

  it('does not repeat itself', () => {
    const seen = new Set(Array.from({ length: 20 }, () => generatePassword()))
    expect(seen.size).toBe(20)
  })
})

describe('meetsPasswordPolicy', () => {
  it('requires length, a letter and a digit', () => {
    expect(meetsPasswordPolicy('abc1')).toBe(false)
    expect(meetsPasswordPolicy('abcdefghij')).toBe(false)
    expect(meetsPasswordPolicy('12345678')).toBe(false)
    expect(meetsPasswordPolicy('abcdefg1')).toBe(true)
  })
})
