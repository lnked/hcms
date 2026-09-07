import { useEffect, useState } from 'react'
import { ApiError, getList } from '../api/client'
import type { Article, ListMeta } from '../api/types'
import { ArticleCard } from '../components/ArticleCard'
import { EmptyState } from '../components/EmptyState'

const LIMIT = 12

export function HomePage() {
  const [page, setPage] = useState(1)
  const [items, setItems] = useState<Article[]>([])
  const [meta, setMeta] = useState<ListMeta | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    const query = new URLSearchParams({
      page: String(page),
      limit: String(LIMIT),
      sort: '-published_at',
      'filter[status]': 'published',
    }).toString()

    getList<Article>('demo_articles', query)
      .then((res) => {
        if (cancelled) return
        setItems(res.data)
        setMeta(res.meta)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setItems([])
        setMeta(null)
        setError(err instanceof ApiError ? `${err.message} (${err.status})` : String(err))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [page])

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>Articles</h1>
          <p>GET /api/demo_articles?filter[status]=published</p>
        </div>
        {meta ? (
          <span className="meta">
            {meta.total} total · page {meta.page}/{meta.totalPages || 1}
          </span>
        ) : null}
      </div>

      {loading ? <div className="loading">Loading…</div> : null}
      {error ? <div className="error">{error}</div> : null}
      {!loading && !error && items.length === 0 ? (
        <EmptyState title="No published articles">
          Run php scripts/seed-demo.php against the CMS.
        </EmptyState>
      ) : null}

      {!loading && !error && items.length > 0 ? (
        <>
          <div className="grid">
            {items.map((article) => (
              <ArticleCard key={article.id} article={article} />
            ))}
          </div>
          {meta && meta.totalPages > 1 ? (
            <div className="pager">
              <button
                className="btn secondary"
                type="button"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Prev
              </button>
              <span className="meta">
                {page} / {meta.totalPages}
              </span>
              <button
                className="btn secondary"
                type="button"
                disabled={page >= meta.totalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </section>
  )
}
