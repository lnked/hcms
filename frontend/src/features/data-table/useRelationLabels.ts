import { useQueries } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { SchemaField } from '@/types/field'
import type { EntryRow } from './DataTable'

/** Related resource plus the labels of the ids currently on screen. */
export interface RelationTarget {
  resourceId: number
  labels: Record<number, string>
}

interface RelationLabelsResponse {
  resourceId: number
  slug: string
  labelField: string
  labels: Record<string, string>
}

export function isManyToOneRelation(field: SchemaField): boolean {
  return (
    field.type === 'relation' &&
    (field.config.cardinality ?? 'manyToOne') === 'manyToOne' &&
    Boolean(field.config.relatedSlug)
  )
}

/** Stored relation id, or `null` when the row has none. */
export function relationId(value: unknown): number | null {
  if (typeof value === 'number') return Number.isInteger(value) && value > 0 ? value : null
  if (typeof value === 'string' && /^\d+$/.test(value)) {
    const id = Number(value)
    return id > 0 ? id : null
  }
  if (value != null && typeof value === 'object') {
    return relationId((value as { id?: unknown }).id)
  }
  return null
}

/**
 * One request per relation column for the ids on the current page, so a table of
 * 20 rows never pulls the whole related resource just to render its labels.
 */
export function useRelationLabels(
  resourceId: number,
  fields: SchemaField[],
  rows: EntryRow[],
): Record<string, RelationTarget> {
  const columns = fields.filter(isManyToOneRelation).map((field) => {
    const ids = new Set<number>()
    for (const row of rows) {
      const id = relationId(row[field.name])
      if (id !== null) ids.add(id)
    }
    return { name: field.name, ids: [...ids].sort((a, b) => a - b) }
  })

  const results = useQueries({
    queries: columns.map((column) => ({
      queryKey: ['relation-labels', resourceId, column.name, column.ids] as const,
      queryFn: () =>
        api<RelationLabelsResponse>(
          `/admin/api/resources/${resourceId}/entries/relation-labels?field=${encodeURIComponent(
            column.name,
          )}&ids=${column.ids.join(',')}`,
        ),
      // No ids on screen means no cell needs a target.
      enabled: column.ids.length > 0,
      staleTime: 30_000,
    })),
  })

  const out: Record<string, RelationTarget> = {}
  results.forEach((result, index) => {
    const data = result.data
    const column = columns[index]
    if (!data || !column) return
    const labels: Record<number, string> = {}
    for (const [id, label] of Object.entries(data.labels)) {
      labels[Number(id)] = label
    }
    out[column.name] = { resourceId: data.resourceId, labels }
  })

  return out
}
