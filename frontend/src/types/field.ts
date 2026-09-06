export type FieldTypeName =
  | 'string'
  | 'text'
  | 'integer'
  | 'float'
  | 'boolean'
  | 'date'
  | 'datetime'
  | 'email'
  | 'url'
  | 'uuid'
  | 'json'
  | 'enum'
  | 'image'
  | 'file'
  | 'relation'

export interface SchemaField {
  id?: number
  name: string
  type: FieldTypeName | string
  sortOrder: number
  label: string
  description?: string | null
  required: boolean
  nullable: boolean
  unique: boolean
  indexed: boolean
  default?: unknown
  readonly: boolean
  hidden: boolean
  searchable: boolean
  sortable: boolean
  filterable: boolean
  readable: boolean
  writable: boolean
  config: Record<string, unknown>
}

export const FIELD_TYPES: FieldTypeName[] = [
  'string',
  'text',
  'integer',
  'float',
  'boolean',
  'date',
  'datetime',
  'email',
  'url',
  'uuid',
  'json',
  'enum',
  'image',
  'file',
  'relation',
]

export function emptyField(type: FieldTypeName = 'string', sortOrder = 0): SchemaField {
  const isRelation = type === 'relation'
  return {
    name: '',
    type,
    sortOrder,
    label: '',
    description: '',
    required: false,
    nullable: true,
    unique: false,
    indexed: false,
    default: null,
    readonly: false,
    hidden: false,
    searchable: type === 'string' || type === 'text' || type === 'email',
    sortable: type === 'string' || type === 'integer' || type === 'datetime',
    filterable: true,
    readable: true,
    writable: true,
    config: isRelation
      ? {
          cardinality: 'manyToOne',
          relatedSlug: '',
          labelField: 'id',
          foreignKey: '',
        }
      : type === 'enum'
        ? { options: ['draft', 'published'] }
        : type === 'string'
          ? { maxLength: 255 }
          : {},
  }
}
