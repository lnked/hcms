import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AppRouter } from '@/app/router'
import './index.css'

// Shared hosting often exposes /public/admin — SPA expects /admin.
if (
  window.location.pathname === '/public/admin' ||
  window.location.pathname.startsWith('/public/admin/')
) {
  const next =
    window.location.pathname.replace(/^\/public/, '') +
    window.location.search +
    window.location.hash
  window.location.replace(next)
} else {
  const queryClient = new QueryClient()

  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <AppRouter />
      </QueryClientProvider>
    </StrictMode>,
  )
}
