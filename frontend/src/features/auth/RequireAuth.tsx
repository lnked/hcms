import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getToken } from '@/lib/api'

export function RequireAuth() {
  const location = useLocation()
  if (!getToken()) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }
  return <Outlet />
}
