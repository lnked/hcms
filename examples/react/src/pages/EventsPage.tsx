import { useEffect, useState } from 'react'
import { ApiError, getList } from '../api/client'
import type { Event } from '../api/types'
import { EmptyState } from '../components/EmptyState'

export function EventsPage() {
  const [items, setItems] = useState<Event[]>([])
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    getList<Event>('demo_events', 'limit=20&sort=starts_at')
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
          <h1>Events</h1>
          <p>GET /api/demo_events</p>
        </div>
      </div>

      {loading ? <div className="loading">Loading…</div> : null}
      {error ? <div className="error">{error}</div> : null}
      {!loading && !error && items.length === 0 ? (
        <EmptyState title="No events" />
      ) : null}

      <div className="list">
        {items.map((event) => (
          <div className="list-item" key={event.id}>
            <h2>{event.name}</h2>
            {event.summary ? <p className="meta">{event.summary}</p> : null}
            <span className="meta">
              {[
                event.starts_at,
                event.level,
                event.is_online ? 'online' : null,
                event.ticket_price != null ? `$${event.ticket_price}` : null,
              ]
                .filter(Boolean)
                .join(' · ')}
            </span>
          </div>
        ))}
      </div>
    </section>
  )
}
