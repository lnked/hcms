import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, clearToken, getToken } from '@/lib/api'
import type { AuthUser } from '@/types/system'

export function RequireAuth() {
  const location = useLocation()
  const token = getToken()
  const me = useQuery({
    queryKey: ['auth-me', token],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
    enabled: Boolean(token),
    retry: false,
    staleTime: 30_000,
  })

  useEffect(() => {
    if (me.isError) {
      clearToken()
    }
  }, [me.isError])

  if (!token || me.isError) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (me.isLoading || me.isFetching) {
    return (
      <div className="flex min-h-svh items-center justify-center text-sm text-muted-foreground">
        Loading…
      </div>
    )
  }

  return <Outlet />
}
