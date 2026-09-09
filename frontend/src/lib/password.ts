/** Suggested length for generated passwords. */
export const GENERATED_PASSWORD_LENGTH = 20

/** Server policy (Cms\Auth\Password): at least 8 chars with a letter and a digit. */
export const PASSWORD_MIN_LENGTH = 8

const LOWER = 'abcdefghijkmnopqrstuvwxyz'
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const DIGITS = '23456789'
const SYMBOLS = '!@#$%^&*-_=+?'
const ALPHABET = LOWER + UPPER + DIGITS + SYMBOLS

/** Uniform index in [0, max) — rejection sampling keeps the distribution unbiased. */
function randomIndex(max: number): number {
  const limit = Math.floor(0x100000000 / max) * max
  const buffer = new Uint32Array(1)
  do {
    crypto.getRandomValues(buffer)
  } while (buffer[0] >= limit)

  return buffer[0] % max
}

function pick(pool: string): string {
  return pool[randomIndex(pool.length)]
}

export function meetsPasswordPolicy(password: string): boolean {
  return password.length >= PASSWORD_MIN_LENGTH && /[A-Za-z]/.test(password) && /\d/.test(password)
}

/** Cryptographically random password guaranteed to satisfy the server policy. */
export function generatePassword(length = GENERATED_PASSWORD_LENGTH): string {
  const size = Math.max(PASSWORD_MIN_LENGTH, length)
  const chars = [pick(LOWER), pick(UPPER), pick(DIGITS), pick(SYMBOLS)]
  while (chars.length < size) {
    chars.push(pick(ALPHABET))
  }
  for (let i = chars.length - 1; i > 0; i -= 1) {
    const j = randomIndex(i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }

  return chars.join('')
}
