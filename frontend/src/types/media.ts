export interface MediaItem {
  id: number
  originalName: string
  mime: string
  size: number
  width: number | null
  height: number | null
  url: string
  createdAt: string
}
