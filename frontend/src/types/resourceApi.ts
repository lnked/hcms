export interface ResourceApiJoin {
  as: string
  relatedSlug: string
  localField: string
  foreignField: string
  fields: string[] | null
  type: 'manyToOne'
}

export const RESOURCE_API_METHODS = ['GET', 'POST', 'PATCH', 'DELETE'] as const

export type ResourceApiMethod = (typeof RESOURCE_API_METHODS)[number]

/** Access is tri-state: `null` inherits the resource-level flag. */
export interface ResourceApiPublicAccess {
  read: boolean | null
  create: boolean | null
  update: boolean | null
  delete: boolean | null
}

export interface ResourceApiSettings {
  pagination: boolean
  search: boolean
  sorting: boolean
  filtering: boolean
  public: ResourceApiPublicAccess
}

export interface ResourceCustomApi {
  id: number
  resourceId: number
  slug: string
  label: string
  enabled: boolean
  methods: ResourceApiMethod[]
  fields: string[] | null
  joins: ResourceApiJoin[]
  settings: ResourceApiSettings
  path: string
  createdAt: string
  updatedAt: string
}

export interface ResourceCustomApiInput {
  slug: string
  label: string
  enabled: boolean
  methods: ResourceApiMethod[]
  fields: string[] | null
  joins: ResourceApiJoin[]
  settings: ResourceApiSettings
}

export function isWriteMethod(method: ResourceApiMethod): boolean {
  return method !== 'GET'
}

export function methodAction(method: ResourceApiMethod): keyof ResourceApiPublicAccess {
  switch (method) {
    case 'POST':
      return 'create'
    case 'PATCH':
      return 'update'
    case 'DELETE':
      return 'delete'
    default:
      return 'read'
  }
}

export function emptyJoin(): ResourceApiJoin {
  return {
    as: '',
    relatedSlug: '',
    localField: '',
    foreignField: 'id',
    fields: null,
    type: 'manyToOne',
  }
}
