import { describe, expect, it } from 'vitest'
import {
  allowsResourceAction,
  allowsResourceTab,
  canAccessNav,
  roleAllows,
  sectionAllows,
  sectionForPath,
} from '@/lib/rbac'
import type { AuthUser } from '@/types/system'

const baseUser = (over: Partial<AuthUser> = {}): AuthUser => ({
  id: 2,
  name: 'Ed',
  email: 'ed@example.com',
  role: 'editor',
  changelogSeenVersion: null,
  aclEnabled: false,
  sections: [],
  resourceGrants: [],
  ...over,
})

describe('rbac', () => {
  it('roleAllows respects rank', () => {
    expect(roleAllows('editor', 'editor')).toBe(true)
    expect(roleAllows('viewer', 'editor')).toBe(false)
    expect(roleAllows('owner', 'admin')).toBe(true)
  })

  it('sectionAllows with ACL', () => {
    const user = baseUser({
      aclEnabled: true,
      sections: ['resources', 'media'],
    })
    expect(sectionAllows(user, 'media')).toBe(true)
    expect(sectionAllows(user, 'users')).toBe(false)
    expect(sectionAllows(user, 'account')).toBe(true)
    expect(canAccessNav(user, 'media', 'editor')).toBe(true)
    expect(canAccessNav(user, 'media', 'admin')).toBe(false)
  })

  it('resource grants', () => {
    const user = baseUser({
      aclEnabled: true,
      sections: ['resources'],
      resourceGrants: [
        {
          resourceId: 1,
          canRead: true,
          canCreate: true,
          canUpdate: false,
          canDelete: false,
          tabs: ['overview', 'data'],
        },
      ],
    })
    expect(allowsResourceAction(user, 1, 'create')).toBe(true)
    expect(allowsResourceAction(user, 1, 'delete')).toBe(false)
    expect(allowsResourceTab(user, 1, 'data')).toBe(true)
    expect(allowsResourceTab(user, 1, 'schema')).toBe(false)
  })

  it('sectionForPath', () => {
    expect(sectionForPath('/settings/users')).toBe('users')
    expect(sectionForPath('/settings/inbound')).toBe('inbound')
    expect(sectionForPath('/settings/uptime')).toBe('uptime')
    expect(sectionForPath('/settings/feature-flags')).toBe('feature-flags')
    expect(sectionForPath('/settings/key-values')).toBe('key-values')
    expect(sectionForPath('/settings/translates')).toBe('translates')
    expect(sectionForPath('/resources/3/data')).toBe('resources')
    expect(sectionForPath('/')).toBe('dashboard')
  })
})
