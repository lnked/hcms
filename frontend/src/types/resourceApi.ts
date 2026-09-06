export interface ResourceApiJoin {
  as: string
  relatedSlug: string
  localField: string
  foreignField: string
  fields: string[] | null
  type: 'manyToOne'
}

export interface ResourceApiSettings {
  pagination: boolean
  search: boolean
  sorting: boolean
  filtering: boolean
  public: {
    read: boolean | null
  }
}

export interface ResourceCustomApi {
  id: number
  resourceId: number
  slug: string
  label: string
  enabled: boolean
  methods: string[]
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
  fields: string[] | null
  joins: ResourceApiJoin[]
  settings?: Partial<ResourceApiSettings>
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
