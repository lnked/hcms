import { api, apiPage, getToken } from '@/lib/api'
import type { Resource } from '@/types/resource'

export type RelatedRow = Record<string, unknown> & { id: number }

/**
 * Loads rows of a related resource through the public API, falling back to the
 * admin API when the public one is closed for that resource.
 */
export async function fetchRelatedList(
  relatedSlug: string,
  extraQuery = '',
): Promise<RelatedRow[]> {
  const headers = new Headers({ Accept: 'application/json' })
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const qs = extraQuery ? `&${extraQuery.replace(/^\?/, '').replace(/^&/, '')}` : ''
  const publicPath = `/api/${encodeURIComponent(relatedSlug)}?limit=100${qs}`
  const publicRes = await fetch(publicPath, { headers })
  if (publicRes.ok) {
    const payload = (await publicRes.json()) as { data?: RelatedRow[] }
    return payload.data ?? []
  }

  if (publicRes.status !== 403 && publicRes.status !== 401) {
    throw new Error(`Failed to load related (${publicRes.status})`)
  }

  const resources = await api<Resource[]>('/admin/api/resources')
  const related = resources.find((r) => r.slug === relatedSlug || r.contentTypeSlug === relatedSlug)
  if (!related) {
    throw new Error(`Related resource not found: ${relatedSlug}`)
  }

  const adminQs = new URLSearchParams({ limit: '100' })
  if (extraQuery) {
    for (const part of extraQuery.split('&')) {
      const [k, v] = part.split('=')
      if (k) adminQs.set(decodeURIComponent(k), decodeURIComponent(v ?? ''))
    }
  }
  const page = await apiPage<RelatedRow>(
    `/admin/api/resources/${related.id}/entries?${adminQs.toString()}`,
  )
  return page.data
}

export function entryLabel(row: RelatedRow, labelField: string): string {
  const raw = row[labelField]
  if (raw == null || raw === '') return String(row.id)
  return String(raw)
}
