import { isManyToOneRelation } from './useRelationLabels'
import type { SchemaField } from '@/types/field'

/** Control the filter row renders for a column, picked from the field type. */
export type FilterControlKind =
  'text' | 'number' | 'boolean' | 'enum' | 'date' | 'relation' | 'none'

/** Operator sent to the API; `contains` for free text, exact match for typed values. */
type FilterOperator = 'eq' | 'contains' | 'startsWith'

export function filterControlKind(field: SchemaField): FilterControlKind {
  if (!field.filterable) return 'none'
  switch (field.type) {
    case 'boolean':
      return 'boolean'
    case 'enum':
      return 'enum'
    case 'integer':
    case 'float':
      return 'number'
    case 'date':
    case 'datetime':
      return 'date'
    case 'relation':
      return isManyToOneRelation(field) ? 'relation' : 'none'
    // Media columns hold JSON blobs of ids, so a text match over them is noise.
    case 'image':
    case 'file':
      return 'none'
    default:
      return 'text'
  }
}

export function isFilterable(field: SchemaField): boolean {
  return filterControlKind(field) !== 'none'
}

function filterOperator(field: SchemaField): FilterOperator {
  switch (filterControlKind(field)) {
    case 'text':
      return 'contains'
    // A datetime column stores `YYYY-MM-DD HH:MM:SS`, the picker gives a day.
    case 'date':
      return field.type === 'datetime' ? 'startsWith' : 'eq'
    default:
      return 'eq'
  }
}

/** Query param pair for one active filter, e.g. `filter[title][contains]` → `foo`. */
export function filterParam(field: SchemaField, value: string): [string, string] {
  const op = filterOperator(field)
  const key = op === 'eq' ? `filter[${field.name}]` : `filter[${field.name}][${op}]`
  return [key, value]
}
