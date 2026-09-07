/**
 * URL-safe content slug (hyphenated), with basic Cyrillic transliteration.
 */
const CYRILLIC_TRANSLIT: Record<string, string> = {
  а: 'a',
  б: 'b',
  в: 'v',
  г: 'g',
  д: 'd',
  е: 'e',
  ё: 'e',
  ж: 'zh',
  з: 'z',
  и: 'i',
  й: 'y',
  к: 'k',
  л: 'l',
  м: 'm',
  н: 'n',
  о: 'o',
  п: 'p',
  р: 'r',
  с: 's',
  т: 't',
  у: 'u',
  ф: 'f',
  х: 'h',
  ц: 'ts',
  ч: 'ch',
  ш: 'sh',
  щ: 'sch',
  ъ: '',
  ы: 'y',
  ь: '',
  э: 'e',
  ю: 'yu',
  я: 'ya',
}

/**
 * Cyrillic letters that are visually identical to a Latin one. A Cyrillic
 * keyboard slip inside a Latin word ("сrop") should read as what it looks
 * like ("crop"), not as its transliteration ("srop").
 */
const CYRILLIC_HOMOGLYPHS: Record<string, string> = {
  а: 'a',
  в: 'b',
  е: 'e',
  ё: 'e',
  к: 'k',
  м: 'm',
  н: 'h',
  о: 'o',
  р: 'p',
  с: 'c',
  т: 't',
  у: 'y',
  х: 'x',
}

const WORD = /[a-zа-яё0-9]+/g

function transliterateWord(word: string): string {
  const mixed = /[a-z]/.test(word) && /[а-яё]/.test(word)
  return Array.from(word)
    .map((ch) => (mixed ? CYRILLIC_HOMOGLYPHS[ch] : undefined) ?? CYRILLIC_TRANSLIT[ch] ?? ch)
    .join('')
}

function transliterate(value: string): string {
  return value.toLowerCase().replace(WORD, transliterateWord)
}

export function slugifyUrl(value: string, maxLength = 255): string {
  return transliterate(value.trim())
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, maxLength)
}

/**
 * SQL/API identifier (snake_case, must start with a letter) — used for field
 * names and image size prefixes, which the backend validates as
 * `^[a-z][a-z0-9_]{0,N}$`. Whitespace becomes `_` (and trailing `_` is kept)
 * so that multi-word names stay typable.
 */
export function slugifyIdentifier(value: string, maxLength = 64): string {
  return transliterate(value)
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^[^a-z]+/, '')
    .slice(0, maxLength)
}
