import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

/** Keep client-side URLs without a trailing slash (except `/`). */
export function TrailingSlashRedirect() {
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    const { pathname, search, hash } = location
    if (pathname.length > 1 && pathname.endsWith('/')) {
      void navigate(
        { pathname: pathname.replace(/\/+$/, '') || '/', search, hash },
        { replace: true },
      )
    }
  }, [location, navigate])

  return null
}
