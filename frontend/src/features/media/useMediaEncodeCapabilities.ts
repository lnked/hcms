import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'

export type MediaEncodeCapabilities = {
  webp: boolean
  avif: boolean
  jpeg: boolean
  png: boolean
}

const DEFAULT: MediaEncodeCapabilities = {
  webp: true,
  avif: false,
  jpeg: true,
  png: true,
}

export function useMediaEncodeCapabilities(enabled = true) {
  return useQuery({
    queryKey: queryKeys.media.capabilities,
    queryFn: () => api<MediaEncodeCapabilities>('/admin/api/media/capabilities'),
    staleTime: 300_000,
    enabled,
    placeholderData: DEFAULT,
  })
}
