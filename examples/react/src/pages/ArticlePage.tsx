import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError, getOne, mediaUrl } from '../api/client'
import type { Article } from '../api/types'

export function ArticlePage() {
  const { id } = useParams()
  const [article, setArticle] = useState<Article | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!id) return
    let cancelled = false
    setLoading(true)
    setError(null)

    getOne<Article>('demo_articles', id)
      .then((data) => {
        if (!cancelled) setArticle(data)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setArticle(null)
        setError(err instanceof ApiError ? `${err.message} (${err.status})` : String(err))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [id])

  const cover = mediaUrl(article?.cover)

  return (
    <section>
      <div className="page-head">
        <div>
          <p>
            <Link to="/">← Articles</Link>
          </p>
          <h1>Article</h1>
          <p>GET /api/demo_articles/{id}</p>
        </div>
      </div>

      {loading ? <div className="loading">Loading…</div> : null}
      {error ? <div className="error">{error}</div> : null}

      {article ? (
        <article className="article">
          {cover ? <img className="article-cover" src={cover} alt="" /> : null}
          <div className="article-body">
            <h1>{article.title}</h1>
            <div className="meta">
              {article.status}
              {article.published_at ? ` · ${article.published_at}` : ''}
              {article.slug ? ` · ${article.slug}` : ''}
            </div>
            {article.excerpt ? <p>{article.excerpt}</p> : null}
            {article.body ? <div className="article-content">{article.body}</div> : null}
          </div>
        </article>
      ) : null}
    </section>
  )
}
