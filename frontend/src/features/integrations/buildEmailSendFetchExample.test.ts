import { describe, expect, it } from 'vitest'
import { buildEmailSendFetchExample } from './buildEmailSendFetchExample'

describe('buildEmailSendFetchExample', () => {
  it('builds POST fetch for send path', () => {
    const snippet = buildEmailSendFetchExample('/api/integrations/email/send', {
      origin: 'https://api.2js.ru',
    })

    expect(snippet).toContain("fetch('https://api.2js.ru/api/integrations/email/send'")
    expect(snippet).toContain("method: 'POST'")
    expect(snippet).toContain("Authorization: 'Bearer YOUR_TOKEN'")
    expect(snippet).toContain('subject:')
  })

  it('builds custom API example with vars', () => {
    const snippet = buildEmailSendFetchExample('/api/integrations/email/welcome', {
      origin: 'https://api.2js.ru',
      withVars: true,
    })

    expect(snippet).toContain('/api/integrations/email/welcome')
    expect(snippet).toContain('vars:')
    expect(snippet).not.toContain('subject:')
  })
})
