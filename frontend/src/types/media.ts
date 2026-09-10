export interface MediaItem {
  id: number
  parentId?: number | null
  variantKey?: string | null
  originalName: string
  mime: string
  size: number
  width: number | null
  height: number | null
  url: string
  fullUrl: string
  createdAt: string
}
