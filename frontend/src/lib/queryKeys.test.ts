import { describe, expect, it } from 'vitest'
import { queryKeys } from './queryKeys'

describe('queryKeys', () => {
  it('builds stable auth and resource keys', () => {
    expect(queryKeys.auth.root).toEqual(['auth-me'])
    expect(queryKeys.resources.entries(12)).toEqual(['resource-entries', 12])
    expect(queryKeys.system.version).toEqual(['system-version'])
    expect(queryKeys.fieldTypes).toEqual(['field-types'])
    expect(queryKeys.integrations.email).toEqual(['integrations-email'])
    expect(queryKeys.integrations.emailApis).toEqual(['integrations-email-apis'])
    expect(queryKeys.webhooks.list).toEqual(['webhooks'])
    expect(queryKeys.webhooks.deliveries(3)).toEqual(['webhooks', 3, 'deliveries'])
  })
})
