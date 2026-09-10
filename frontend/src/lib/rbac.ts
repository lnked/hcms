import type { AuthUser } from '@/types/system'

export type AdminRole = 'owner' | 'admin' | 'editor' | 'viewer'

export type AdminSection =
  | 'dashboard'
  | 'resources'
  | 'media'
  | 'logs'
  | 'docs'
  | 'changelog'
  | 'tokens'
  | 'webhooks'
  | 'inbound'
  | 'feature-flags'
  | 'key-values'
  | 'translates'
  | 'users'
  | 'integrations'
  | 'system'
  | 'account'

export type ResourceTab = 'overview' | 'schema' | 'data' | 'settings' | 'api' | 'hooks' | 'export'

export const ADMIN_SECTIONS: AdminSection[] = [
  'dashboard',
  'resources',
  'media',
  'logs',
  'docs',
  'changelog',
  'tokens',
  'webhooks',
  'inbound',
  'feature-flags',
  'key-values',
  'translates',
  'users',
  'integrations',
  'system',
  'account',
]

export const RESOURCE_TABS: ResourceTab[] = [
  'overview',
  'schema',
  'data',
  'settings',
  'api',
  'hooks',
  'export',
]

export const ROLE_RANK: Record<string, number> = {
  viewer: 1,
  editor: 2,
  admin: 3,
  owner: 4,
}

export function roleAllows(userRole: string | undefined, minRole: AdminRole | undefined): boolean {
  if (!minRole) return true
  const rank = ROLE_RANK[userRole ?? 'admin'] ?? 3
  return rank >= (ROLE_RANK[minRole] ?? 0)
}

export function sectionAllows(user: AuthUser | undefined, section: AdminSection): boolean {
  if (!user) return false
  if (user.role === 'owner') return true
  if (section === 'account') return true
  if (!user.aclEnabled) return true
  return (user.sections ?? []).includes(section)
}

export function canAccessNav(
  user: AuthUser | undefined,
  section: AdminSection,
  minRole?: AdminRole,
): boolean {
  return roleAllows(user?.role, minRole) && sectionAllows(user, section)
}

export interface ResourceGrant {
  resourceId: number
  canRead: boolean
  canCreate: boolean
  canUpdate: boolean
  canDelete: boolean
  tabs: ResourceTab[]
}

export function resourceGrantFor(
  user: AuthUser | undefined,
  resourceId: number,
): ResourceGrant | null {
  if (!user || user.role === 'owner' || !user.aclEnabled) {
    return null
  }
  const grant = (user.resourceGrants ?? []).find((g) => g.resourceId === resourceId)
  return grant ?? null
}

export function allowsResourceTab(
  user: AuthUser | undefined,
  resourceId: number,
  tab: ResourceTab,
): boolean {
  const grant = resourceGrantFor(user, resourceId)
  if (grant === null) return true
  return grant.tabs.includes(tab)
}

export function allowsResourceAction(
  user: AuthUser | undefined,
  resourceId: number,
  action: 'read' | 'create' | 'update' | 'delete',
): boolean {
  const grant = resourceGrantFor(user, resourceId)
  if (grant === null) return true
  switch (action) {
    case 'read':
      return grant.canRead
    case 'create':
      return grant.canCreate
    case 'update':
      return grant.canUpdate
    case 'delete':
      return grant.canDelete
  }
}

/** Map frontend route path (under /admin) to ACL section. */
export function sectionForPath(pathname: string): AdminSection | null {
  const path = pathname.replace(/\/+$/, '') || '/'
  if (path === '/' || path === '') return 'dashboard'
  if (path.startsWith('/resources')) return 'resources'
  if (path.startsWith('/media')) return 'media'
  if (path.startsWith('/logs')) return 'logs'
  if (path.startsWith('/docs')) return 'docs'
  if (path.startsWith('/changelog')) return 'changelog'
  if (path.startsWith('/settings/tokens')) return 'tokens'
  if (path.startsWith('/settings/webhooks')) return 'webhooks'
  if (path.startsWith('/settings/inbound')) return 'inbound'
  if (path.startsWith('/settings/feature-flags')) return 'feature-flags'
  if (path.startsWith('/settings/key-values')) return 'key-values'
  if (path.startsWith('/settings/translates')) return 'translates'
  if (path.startsWith('/settings/users')) return 'users'
  if (path.startsWith('/settings/integrations')) return 'integrations'
  if (path.startsWith('/settings/system')) return 'system'
  if (path.startsWith('/settings/account')) return 'account'
  return null
}
