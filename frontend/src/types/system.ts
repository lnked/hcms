export type ChangeType =
  'added' | 'changed' | 'deprecated' | 'removed' | 'fixed' | 'security' | 'breaking'

export interface ChangelogItem {
  type: ChangeType
  area?: string
  text: string
}

export interface Release {
  version: string
  date: string
  channel: string
  title?: string
  changes: ChangelogItem[]
}

export interface SystemVersion {
  current: string
  latest: string | null
  updateAvailable: boolean
  releasedAt: string | null
  channel: string
  changelogSeenVersion: string | null
}

export interface ResourceGrant {
  resourceId: number
  canRead: boolean
  canCreate: boolean
  canUpdate: boolean
  canDelete: boolean
  tabs: Array<'overview' | 'schema' | 'data' | 'settings' | 'api' | 'export'>
}

export interface AuthUser {
  id: number
  name: string
  email: string
  role?: 'owner' | 'admin' | 'editor' | 'viewer'
  totpEnabled?: boolean
  changelogSeenVersion: string | null
  aclEnabled?: boolean
  sections?: string[]
  resourceGrants?: ResourceGrant[]
}

export interface InstallStatus {
  installed: boolean
  srcReady: boolean
  version: string
  installRootName?: string
  insideWebRoot?: boolean
  suggestedPublicDir?: string
  requirements: {
    ok: boolean
    phpVersion: string
    checks: Record<string, boolean>
  }
}
