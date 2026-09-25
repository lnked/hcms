import { lazy, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { TrailingSlashRedirect } from '@/app/TrailingSlashRedirect'
import { AppShell } from '@/components/AppShell'
import { AppToast } from '@/components/AppToast'
import { PageSkeleton } from '@/components/skeletons'
import { HomeLanding } from '@/features/auth/HomeLanding'
import { LoginPage } from '@/features/auth/LoginPage'
import { OAuthCompletePage } from '@/features/auth/OAuthCompletePage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { RequireSection } from '@/features/auth/RequireSection'
import { InstallPage } from '@/features/install/InstallPage'
import { useAuthMe } from '@/hooks/useAcl'
import { getAdminBasename } from '@/lib/adminBase'
import { homePath, type AdminRole, type AdminSection } from '@/lib/rbac'

/**
 * Login, OAuth and install stay eager: they are the first paint for a visitor
 * with no session and must not wait on a chunk. Everything behind RequireAuth
 * is split, which keeps recharts, React Aria and the editors out of that paint.
 * `AppShell` renders the Suspense boundary, so the sidebar survives navigation.
 */

// Named exports are dereferenced inline so a renamed page is a compile error,
// not a blank screen on that route.
const AccountPage = lazy(() =>
  import('@/pages/AccountPage').then((m) => ({ default: m.AccountPage })),
)
const ChangelogPage = lazy(() =>
  import('@/features/changelog/ChangelogPage').then((m) => ({ default: m.ChangelogPage })),
)
const CreateResourcePage = lazy(() =>
  import('@/features/resources/CreateResourcePage').then((m) => ({
    default: m.CreateResourcePage,
  })),
)
const DocsPage = lazy(() =>
  import('@/features/docs/DocsPage').then((m) => ({ default: m.DocsPage })),
)
const IntegrationsPage = lazy(() =>
  import('@/pages/IntegrationsPage').then((m) => ({ default: m.IntegrationsPage })),
)
const LogsPage = lazy(() =>
  import('@/features/logs/LogsPage').then((m) => ({ default: m.LogsPage })),
)
const MediaPage = lazy(() =>
  import('@/features/media/MediaPage').then((m) => ({ default: m.MediaPage })),
)
const ResourceDetailPage = lazy(() =>
  import('@/features/resources/ResourceDetailPage').then((m) => ({
    default: m.ResourceDetailPage,
  })),
)
const ResourcesPage = lazy(() =>
  import('@/features/resources/ResourcesPage').then((m) => ({ default: m.ResourcesPage })),
)
const SystemPage = lazy(() => import('@/pages/SystemPage').then((m) => ({ default: m.SystemPage })))
const BackupsPage = lazy(() =>
  import('@/pages/BackupsPage').then((m) => ({ default: m.BackupsPage })),
)
const TokensPage = lazy(() => import('@/pages/TokensPage').then((m) => ({ default: m.TokensPage })))
const UsersPage = lazy(() => import('@/pages/UsersPage').then((m) => ({ default: m.UsersPage })))
const WebhooksPage = lazy(() =>
  import('@/features/webhooks/WebhooksPage').then((m) => ({ default: m.WebhooksPage })),
)
const InboundEndpointsPage = lazy(() =>
  import('@/features/inbound/InboundEndpointsPage').then((m) => ({
    default: m.InboundEndpointsPage,
  })),
)
const UptimePage = lazy(() =>
  import('@/features/uptime/UptimePage').then((m) => ({ default: m.UptimePage })),
)
const FeatureFlagsPage = lazy(() =>
  import('@/pages/FeatureFlagsPage').then((m) => ({ default: m.FeatureFlagsPage })),
)
const KeyValuesPage = lazy(() =>
  import('@/pages/KeyValuesPage').then((m) => ({ default: m.KeyValuesPage })),
)
const TranslatesPage = lazy(() =>
  import('@/pages/TranslatesPage').then((m) => ({ default: m.TranslatesPage })),
)

function withSection(section: AdminSection, minRole: AdminRole | undefined, page: ReactNode) {
  return (
    <RequireSection section={section} minRole={minRole}>
      {page}
    </RequireSection>
  )
}

function CatchAllRedirect() {
  const me = useAuthMe()
  if (me.isLoading || !me.data) {
    return <PageSkeleton />
  }
  return <Navigate to={homePath(me.data)} replace />
}

export function AppRouter() {
  return (
    <BrowserRouter basename={getAdminBasename() || undefined}>
      <TrailingSlashRedirect />
      <AppToast />
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/oauth/complete" element={<OAuthCompletePage />} />
        <Route path="/install" element={<InstallPage />} />
        <Route element={<RequireAuth />}>
          <Route element={<AppShell />}>
            <Route index element={<HomeLanding />} />
            <Route
              path="resources"
              element={withSection('resources', undefined, <ResourcesPage />)}
            />
            <Route
              path="resources/new"
              element={withSection('resources', undefined, <CreateResourcePage />)}
            />
            <Route
              path="resources/:id/:tab?/:entryId?"
              element={withSection('resources', undefined, <ResourceDetailPage />)}
            />
            <Route path="media" element={withSection('media', 'editor', <MediaPage />)} />
            <Route path="logs" element={withSection('logs', 'admin', <LogsPage />)} />
            <Route path="docs/:chapter?" element={withSection('docs', undefined, <DocsPage />)} />
            <Route
              path="changelog"
              element={withSection('changelog', undefined, <ChangelogPage />)}
            />
            <Route
              path="settings/system"
              element={withSection('system', 'admin', <SystemPage />)}
            />
            <Route
              path="settings/backups"
              element={withSection('backups', 'admin', <BackupsPage />)}
            />
            <Route
              path="settings/integrations"
              element={withSection('integrations', 'admin', <IntegrationsPage />)}
            />
            <Route
              path="settings/tokens"
              element={withSection('tokens', 'admin', <TokensPage />)}
            />
            <Route
              path="settings/webhooks"
              element={withSection('webhooks', 'admin', <WebhooksPage />)}
            />
            <Route
              path="settings/inbound"
              element={withSection('inbound', 'admin', <InboundEndpointsPage />)}
            />
            <Route
              path="settings/uptime"
              element={withSection('uptime', 'admin', <UptimePage />)}
            />
            <Route
              path="settings/feature-flags"
              element={withSection('feature-flags', 'admin', <FeatureFlagsPage />)}
            />
            <Route
              path="settings/key-values"
              element={withSection('key-values', 'admin', <KeyValuesPage />)}
            />
            <Route
              path="settings/translates"
              element={withSection('translates', 'admin', <TranslatesPage />)}
            />
            <Route path="settings/users" element={withSection('users', 'admin', <UsersPage />)} />
            <Route
              path="settings/account"
              element={withSection('account', undefined, <AccountPage />)}
            />
            <Route path="*" element={<CatchAllRedirect />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
