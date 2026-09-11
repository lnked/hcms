import { randomId } from '@/lib/utils'

export type FieldTypeName =
  | 'string'
  | 'text'
  | 'richtext'
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
  | 'slug'
  | 'image'
  | 'file'
  | 'relation'

export interface SchemaField {
  id?: number
  /** Stable React list key for unsaved fields; not sent to the API. */
  clientKey?: string
  name: string
  type: FieldTypeName
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
  'richtext',
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
  'slug',
  'image',
  'file',
  'relation',
]

export const DEFAULT_DATE_FORMAT = 'DD.MM.YYYY'
export const DEFAULT_DATETIME_FORMAT = 'DD.MM.YYYY HH:mm'

function defaultConfig(type: FieldTypeName): Record<string, unknown> {
  switch (type) {
    case 'relation':
      return {
        cardinality: 'manyToOne',
        relatedSlug: '',
        labelField: 'id',
        foreignKey: '',
      }
    case 'enum':
      return { options: ['draft', 'published'] }
    case 'slug':
      return { associatedWith: '', maxLength: 255 }
    case 'string':
      return { maxLength: 255 }
    case 'image':
      return { multiple: false, formats: [], encodeFormat: null, sizes: [] }
    case 'file':
      return { multiple: false, formats: [] }
    case 'date':
      return { format: DEFAULT_DATE_FORMAT }
    case 'datetime':
      return { format: DEFAULT_DATETIME_FORMAT }
    default:
      return {}
  }
}

export function emptyField(type: FieldTypeName = 'string', sortOrder = 0): SchemaField {
  return {
    clientKey: randomId(),
    name: '',
    type,
    sortOrder,
    label: '',
    description: '',
    required: false,
    nullable: true,
    unique: type === 'slug',
    indexed: false,
    default: null,
    readonly: false,
    hidden: false,
    searchable:
      type === 'string' ||
      type === 'text' ||
      type === 'richtext' ||
      type === 'email' ||
      type === 'slug',
    sortable: type === 'string' || type === 'integer' || type === 'datetime' || type === 'slug',
    filterable: true,
    readable: true,
    writable: true,
    config: defaultConfig(type),
  }
}

export type ImageSizeConfig = {
  prefix: string
  width: number
  height: number
  mode: 'crop' | 'resize'
  position: string
}

/** Crop window as 0..1 fractions of the rotated (and flipped) source image. */
export type CropRect = {
  x: number
  y: number
  w: number
  h: number
}

/** Base edit baked into the master image by the crop editor. */
export type MediaEdit = {
  rotation: number
  flipH: boolean
  flipV: boolean
  crop: CropRect | null
}

export type MediaFieldValue = {
  /** Image the variants are cut from: the upload itself, or a master baked from `sourceId`. */
  id: number
  /** Untouched original, present once an edit has been applied. */
  sourceId?: number | null
  rotation: number
  edit?: MediaEdit | null
  positions: Record<string, string>
  /** Per-size crop overrides keyed by size prefix. */
  overrides?: Record<string, { crop: CropRect }>
  variants: Record<string, number | MediaItemRef>
  media?: MediaItemRef
}

export type MediaItemRef = {
  id: number
  url?: string
  fullUrl?: string
  originalName?: string
  mime?: string
  width?: number | null
  height?: number | null
}
