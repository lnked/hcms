import { describe, expect, it } from 'vitest'
import { queryKeys } from './queryKeys'

describe('queryKeys', () => {
  it('builds stable auth and resource keys', () => {
    expect(queryKeys.auth.root).toEqual(['auth-me'])
    expect(queryKeys.resources.entries(12)).toEqual(['resource-entries', 12])
    expect(queryKeys.system.version).toEqual(['system-version'])
  })
})
