import { useEffect, useState } from 'react'
import { ApiError, getList } from '../api/client'
import type { Category } from '../api/types'
import { EmptyState } from '../components/EmptyState'

export function CategoriesPage() {
  const [items, setItems] = useState<Category[]>([])
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    getList<Category>('demo_categories', 'limit=50&sort=sort_order')
      .then((res) => {
        if (!cancelled) setItems(res.data)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setItems([])
        setError(err instanceof ApiError ? `${err.message} (${err.status})` : String(err))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>Categories</h1>
          <p>GET /api/demo_categories</p>
        </div>
      </div>

      {loading ? <div className="loading">Loading…</div> : null}
      {error ? <div className="error">{error}</div> : null}
      {!loading && !error && items.length === 0 ? (
        <EmptyState title="No categories" />
      ) : null}

      <div className="list">
        {items.map((category) => (
          <div className="list-item" key={category.id}>
            <h2>{category.title}</h2>
            {category.description ? <p className="meta">{category.description}</p> : null}
            <span className="meta">
              {[category.kind, category.is_featured ? 'featured' : null]
                .filter(Boolean)
                .join(' · ')}
            </span>
          </div>
        ))}
      </div>
    </section>
  )
}
