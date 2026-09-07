import { useState, type FormEvent } from 'react'
import { ApiError, apiRequest, getApiToken } from '../api/client'
import { JsonBlock } from '../components/JsonBlock'

type Preset = {
  label: string
  method: string
  path: string
  query: string
}

const PRESETS: Preset[] = [
  {
    label: 'List articles',
    method: 'GET',
    path: '/api/demo_articles',
    query: 'limit=5&sort=-published_at',
  },
  {
    label: 'Published only',
    method: 'GET',
    path: '/api/demo_articles',
    query: 'filter[status]=published&limit=5',
  },
  {
    label: 'Article #1',
    method: 'GET',
    path: '/api/demo_articles/1',
    query: '',
  },
  {
    label: 'Filter by slug',
    method: 'GET',
    path: '/api/demo_articles',
    query: 'filter[slug]=article-1-example',
  },
  {
    label: 'Categories',
    method: 'GET',
    path: '/api/demo_categories',
    query: 'limit=10',
  },
  {
    label: 'Events',
    method: 'GET',
    path: '/api/demo_events',
    query: 'limit=5&sort=starts_at',
  },
]

export function PlaygroundPage() {
  const [method, setMethod] = useState('GET')
  const [path, setPath] = useState('/api/demo_articles')
  const [query, setQuery] = useState('limit=5')
  const [body, setBody] = useState('')
  const [status, setStatus] = useState<number | null>(null)
  const [result, setResult] = useState<unknown>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  function applyPreset(preset: Preset) {
    setMethod(preset.method)
    setPath(preset.path)
    setQuery(preset.query)
    setBody('')
  }

  async function onRun(e: FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError(null)
    setStatus(null)
    setResult(null)

    let parsedBody: unknown
    if (body.trim() && method !== 'GET' && method !== 'DELETE') {
      try {
        parsedBody = JSON.parse(body)
      } catch {
        setLoading(false)
        setError('Body must be valid JSON')
        return
      }
    }

    try {
      const res = await apiRequest({
        method,
        path,
        query,
        body: parsedBody,
        token: getApiToken(),
      })
      setStatus(res.status)
      setResult(res.data)
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        setStatus(err.status)
        setError(`${err.message}${err.code ? ` [${err.code}]` : ''}`)
      } else {
        setError(err instanceof Error ? err.message : String(err))
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="playground">
      <div className="page-head">
        <div>
          <h1>Playground</h1>
          <p>Manual requests against the Content API</p>
        </div>
      </div>

      <div className="presets">
        {PRESETS.map((preset) => (
          <button
            key={preset.label}
            className="btn secondary"
            type="button"
            onClick={() => applyPreset(preset)}
          >
            {preset.label}
          </button>
        ))}
      </div>

      <form className="playground" onSubmit={onRun}>
        <div className="playground-row">
          <div className="field">
            <label htmlFor="method">Method</label>
            <select
              id="method"
              value={method}
              onChange={(e) => setMethod(e.target.value)}
            >
              <option>GET</option>
              <option>POST</option>
              <option>PATCH</option>
              <option>PUT</option>
              <option>DELETE</option>
            </select>
          </div>
          <div className="field">
            <label htmlFor="path">Path</label>
            <input
              id="path"
              value={path}
              onChange={(e) => setPath(e.target.value)}
              placeholder="/api/demo_articles"
            />
          </div>
        </div>

        <div className="field">
          <label htmlFor="query">Query</label>
          <input
            id="query"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="limit=5&sort=-id"
          />
        </div>

        {method !== 'GET' && method !== 'DELETE' ? (
          <div className="field">
            <label htmlFor="body">JSON body</label>
            <textarea
              id="body"
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder='{"title":"Hello"}'
            />
          </div>
        ) : null}

        <div>
          <button className="btn" type="submit" disabled={loading}>
            {loading ? 'Running…' : 'Run'}
          </button>
        </div>
      </form>

      {status != null ? (
        <span className={`status-pill${status >= 400 ? ' bad' : ''}`}>
          HTTP {status}
        </span>
      ) : null}
      {error ? <div className="error">{error}</div> : null}
      {result !== null ? <JsonBlock value={result} /> : null}
    </section>
  )
}
