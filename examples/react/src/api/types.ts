export type ListMeta = {
  page: number
  limit: number
  total: number
  totalPages: number
}

export type ListResponse<T> = {
  data: T[]
  meta: ListMeta
}

export type DataResponse<T> = {
  data: T
}

export type ApiErrorBody = {
  error: {
    code: string
    message: string
  }
}

export type Article = {
  id: number
  createdAt: string
  updatedAt: string
  title: string
  slug: string
  excerpt: string | null
  body: string | null
  status: string
  cover: number | null
  published_at: string | null
  author_id: number | null
  category_id: number | null
  views?: number
  rating?: number
}

export type Category = {
  id: number
  createdAt: string
  updatedAt: string
  title: string
  description: string | null
  kind: string | null
  sort_order: number | null
  is_featured: boolean | null
}

export type Event = {
  id: number
  createdAt: string
  updatedAt: string
  name: string
  summary: string | null
  starts_on: string | null
  starts_at: string | null
  ends_at: string | null
  capacity: number | null
  ticket_price: number | null
  level: string | null
  is_online: boolean | null
  cover: number | null
  organizer_id: number | null
}
