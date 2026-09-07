import { useState, type FormEvent } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { getApiBase, getApiToken, setApiBase, setApiToken } from '../api/client'

export function Layout() {
  const [base, setBase] = useState(getApiBase)
  const [token, setToken] = useState(getApiToken)

  function onSave(e: FormEvent) {
    e.preventDefault()
    setApiBase(base)
    setApiToken(token)
    setBase(getApiBase())
    setToken(getApiToken())
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="brand">
          <strong>HCMS Demo</strong>
          <span>Content API consumer</span>
        </div>
        <nav className="nav">
          <NavLink to="/" end>
            Articles
          </NavLink>
          <NavLink to="/categories">Categories</NavLink>
          <NavLink to="/events">Events</NavLink>
          <NavLink to="/playground">Playground</NavLink>
        </nav>
      </header>

      <form className="settings" onSubmit={onSave}>
        <div className="field">
          <label htmlFor="api-base">API base</label>
          <input
            id="api-base"
            value={base}
            onChange={(e) => setBase(e.target.value)}
            placeholder="http://127.0.0.1:8080"
            autoComplete="off"
          />
        </div>
        <div className="field">
          <label htmlFor="api-token">Bearer token (optional)</label>
          <input
            id="api-token"
            type="password"
            value={token}
            onChange={(e) => setToken(e.target.value)}
            placeholder="only if public.read is off"
            autoComplete="off"
          />
        </div>
        <button className="btn secondary" type="submit">
          Save
        </button>
      </form>

      <Outlet />
    </div>
  )
}
