import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { filterParam } from '@/features/data-table/filters'
import { apiPage } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import type { EntryRow } from '@/features/data-table/DataTable'
import type { SchemaField } from '@/types/field'

interface UseResourceEntriesListArgs {
  resourceId: number
  published: boolean
  page: number
  search: string
  sort: string
  filters: Record<string, string>
  fields: SchemaField[]
}

export function useResourceEntriesList({
  resourceId,
  published,
  page,
  search,
  sort,
  filters,
  fields,
}: UseResourceEntriesListArgs) {
  const activeFilters = useMemo(() => {
    const out: Record<string, string> = {}
    for (const [key, value] of Object.entries(filters)) {
      const trimmed = value.trim()
      if (trimmed !== '') out[key] = trimmed
    }
    return out
  }, [filters])

  const queryKey = useMemo(
    () => queryKeys.resources.entries(resourceId, { page, search, sort, activeFilters }),
    [resourceId, page, search, sort, activeFilters],
  )

  const list = useQuery({
    queryKey,
    enabled: published,
    queryFn: () => {
      const params = new URLSearchParams({
        page: String(page),
        limit: '20',
        sort,
      })
      if (search) params.set('search', search)
      for (const [field, value] of Object.entries(activeFilters)) {
        const fieldMeta = fields.find((f) => f.name === field)
        if (!fieldMeta) continue
        params.set(...filterParam(fieldMeta, value))
      }
      return apiPage<EntryRow>(`/admin/api/resources/${resourceId}/entries?${params}`)
    },
  })

  return { list, activeFilters, queryKey }
}
