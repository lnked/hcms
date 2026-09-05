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

export interface AuthUser {
  id: number
  name: string
  email: string
  changelogSeenVersion: string | null
}

export interface InstallStatus {
  installed: boolean
  srcReady: boolean
  version: string
  requirements: {
    ok: boolean
    phpVersion: string
    checks: Record<string, boolean>
  }
}
