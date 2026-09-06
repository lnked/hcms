import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AppRouter } from '@/app/router'
import { I18nProvider, LocaleBootstrap } from '@/i18n'
import './index.css'

// If docroot is the project root, people open /public/admin or /public_html/admin.
// Canonical URL is always /admin (web root folder is the document root).
const nested = window.location.pathname.match(/^\/(public|public_html|www|htdocs)(\/admin)(\/.*)?$/)
if (nested) {
  const next =
    (nested[2] ?? '/admin') + (nested[3] ?? '') + window.location.search + window.location.hash
  window.location.replace(next)
} else {
  const queryClient = new QueryClient()

  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <I18nProvider>
          <LocaleBootstrap>
            <AppRouter />
          </LocaleBootstrap>
        </I18nProvider>
      </QueryClientProvider>
    </StrictMode>,
  )
}
