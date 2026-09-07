import type { SchemaField } from '@/types/field'
import type { ResourceListColumn } from '@/types/resource'

/** Columns shown when a resource has no saved layout yet. */
export const DEFAULT_VISIBLE_COLUMNS = 6

export interface TableColumn {
  field: SchemaField
  /** Saved override, or the schema label. */
  label: string
  width: number | null
}

/** Schema fields the table can show at all, in schema order. */
export function listableFields(fields: SchemaField[]): SchemaField[] {
  return fields
    .filter((field) => field.readable && !field.hidden)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)
}

/**
 * Merge the saved layout with the current schema: saved order wins, fields added
 * to the schema afterwards land at the end and stay off until enabled.
 */
export function mergeColumns(
  fields: SchemaField[],
  saved: ResourceListColumn[] | undefined,
): ResourceListColumn[] {
  const available = listableFields(fields)
  if (!saved || saved.length === 0) {
    return available.map((field, index) => ({
      field: field.name,
      visible: index < DEFAULT_VISIBLE_COLUMNS,
      label: null,
      width: null,
    }))
  }

  const byName = new Map(available.map((field) => [field.name, field]))
  const ordered = saved
    .filter((column) => byName.has(column.field))
    .map((column) => ({
      field: column.field,
      visible: column.visible,
      label: column.label ?? null,
      width: column.width ?? null,
    }))
  const known = new Set(ordered.map((column) => column.field))

  for (const field of available) {
    if (known.has(field.name)) continue
    ordered.push({ field: field.name, visible: false, label: null, width: null })
  }

  return ordered
}

/** Visible columns paired with their schema field, ready to render. */
export function resolveColumns(
  fields: SchemaField[],
  saved: ResourceListColumn[] | undefined,
): TableColumn[] {
  const byName = new Map(fields.map((field) => [field.name, field]))
  const out: TableColumn[] = []

  for (const column of mergeColumns(fields, saved)) {
    const field = byName.get(column.field)
    if (!field || !column.visible) continue
    out.push({
      field,
      label: column.label || field.label || field.name,
      width: column.width ?? null,
    })
  }

  return out
}
