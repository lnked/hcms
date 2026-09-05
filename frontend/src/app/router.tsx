import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from '@/components/AppShell'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { ChangelogPage } from '@/features/changelog/ChangelogPage'
import { InstallPage } from '@/features/install/InstallPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { SystemPage } from '@/pages/SystemPage'
import { TokensPage } from '@/pages/TokensPage'

export function AppRouter() {
  return (
    <BrowserRouter basename="/admin">
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/install" element={<InstallPage />} />
        <Route element={<RequireAuth />}>
          <Route element={<AppShell />}>
            <Route index element={<DashboardPage />} />
            <Route path="changelog" element={<ChangelogPage />} />
            <Route path="settings/system" element={<SystemPage />} />
            <Route path="settings/tokens" element={<TokensPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
