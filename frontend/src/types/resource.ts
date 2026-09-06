export type ResourceStatus = 'draft' | 'published' | 'archived'

export interface ResourceSettings {
  apiEnabled: boolean
  public: {
    read: boolean
    create: boolean
    update: boolean
    delete: boolean
  }
  pagination: boolean
  search: boolean
  sorting: boolean
  filtering: boolean
  deleteStrategy: 'hard' | 'soft'
  softDelete: boolean
  spam?: {
    honeypotField: string
    minSubmitMs: number
    rateLimitPerMinute: number
    requireCaptcha: boolean
    maxLinks: number
    blocklist: string[]
    rejectDuplicates: boolean
  }
}

export interface Resource {
  id: number
  contentTypeId: number
  slug: string
  endpoint: string
  apiVersion: string
  status: ResourceStatus
  schemaVersion: number
  settings: ResourceSettings
  label: string
  contentTypeSlug: string
  isSystem: boolean
  createdAt: string
  updatedAt: string
}

export interface CreateResourceInput {
  name?: string
  label: string
  slug?: string
  endpoint?: string
  description?: string
}
