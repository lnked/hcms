import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, type RenderOptions } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { I18nProvider } from '@/i18n'
import type { Locale } from '@/i18n'
import type { ReactElement, ReactNode } from 'react'

export function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })
}

interface ProvidersProps {
  children: ReactNode
  client?: QueryClient
  locale?: Locale
  route?: string
}

export function TestProviders({
  children,
  client = createTestQueryClient(),
  locale = 'en',
  route = '/',
}: ProvidersProps) {
  return (
    <I18nProvider initialLocale={locale}>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[route]}>{children}</MemoryRouter>
      </QueryClientProvider>
    </I18nProvider>
  )
}

export function renderWithProviders(
  ui: ReactElement,
  options?: Omit<RenderOptions, 'wrapper'> & {
    client?: QueryClient
    locale?: Locale
    route?: string
  },
) {
  const { client, locale, route, ...renderOptions } = options ?? {}
  return render(ui, {
    wrapper: ({ children }) => (
      <TestProviders client={client} locale={locale} route={route}>
        {children}
      </TestProviders>
    ),
    ...renderOptions,
  })
}
