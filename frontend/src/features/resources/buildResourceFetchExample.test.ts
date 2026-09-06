import { describe, expect, it } from 'vitest'
import { buildResourceFetchExample } from './buildResourceFetchExample'

const baseSettings = {
  apiEnabled: true,
  public: { read: false, create: false, update: false, delete: false },
  pagination: true,
  search: true,
  sorting: true,
  filtering: true,
  deleteStrategy: 'hard' as const,
  softDelete: false,
}

describe('buildResourceFetchExample', () => {
  it('builds absolute fetch with auth when public.read is false', () => {
    const snippet = buildResourceFetchExample(
      { endpoint: '/api/articles', settings: baseSettings },
      { origin: 'https://api.2js.ru' },
    )

    expect(snippet).toContain("fetch('https://api.2js.ru/api/articles?limit=20'")
    expect(snippet).toContain("Authorization: 'Bearer YOUR_TOKEN'")
    expect(snippet).toContain('const data = await res.json()')
  })

  it('omits auth when public.read is true', () => {
    const snippet = buildResourceFetchExample(
      {
        endpoint: '/api/articles',
        settings: {
          ...baseSettings,
          public: { ...baseSettings.public, read: true },
        },
      },
      { origin: 'https://api.2js.ru' },
    )

    expect(snippet).toContain("fetch('https://api.2js.ru/api/articles?limit=20'")
    expect(snippet).not.toContain('Authorization')
  })

  it('skips limit when pagination is off', () => {
    const snippet = buildResourceFetchExample(
      {
        endpoint: '/api/articles',
        settings: { ...baseSettings, pagination: false, public: { ...baseSettings.public, read: true } },
      },
      { origin: 'https://api.2js.ru' },
    )

    expect(snippet).toContain("fetch('https://api.2js.ru/api/articles'")
    expect(snippet).not.toContain('limit=')
  })
})
