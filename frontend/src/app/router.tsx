import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { TrailingSlashRedirect } from '@/app/TrailingSlashRedirect'
import { AppShell } from '@/components/AppShell'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { ChangelogPage } from '@/features/changelog/ChangelogPage'
import { DocsPage } from '@/features/docs/DocsPage'
import { InstallPage } from '@/features/install/InstallPage'
import { CreateResourcePage } from '@/features/resources/CreateResourcePage'
import { ResourceDetailPage } from '@/features/resources/ResourceDetailPage'
import { ResourcesPage } from '@/features/resources/ResourcesPage'
import { MediaPage } from '@/features/media/MediaPage'
import { LogsPage } from '@/features/logs/LogsPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { IntegrationsPage } from '@/pages/IntegrationsPage'
import { SystemPage } from '@/pages/SystemPage'
import { TokensPage } from '@/pages/TokensPage'
import { UsersPage } from '@/pages/UsersPage'

export function AppRouter() {
  return (
    <BrowserRouter basename="/admin">
      <TrailingSlashRedirect />
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/install" element={<InstallPage />} />
        <Route element={<RequireAuth />}>
          <Route element={<AppShell />}>
            <Route index element={<DashboardPage />} />
            <Route path="resources" element={<ResourcesPage />} />
            <Route path="resources/new" element={<CreateResourcePage />} />
            <Route path="resources/:id/:tab?" element={<ResourceDetailPage />} />
            <Route path="media" element={<MediaPage />} />
            <Route path="logs" element={<LogsPage />} />
            <Route path="docs/:chapter?" element={<DocsPage />} />
            <Route path="changelog" element={<ChangelogPage />} />
            <Route path="settings/system" element={<SystemPage />} />
            <Route path="settings/integrations" element={<IntegrationsPage />} />
            <Route path="settings/tokens" element={<TokensPage />} />
            <Route path="settings/users" element={<UsersPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
