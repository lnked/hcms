import {
  Activity,
  BookOpen,
  FileText,
  Image,
  KeyRound,
  LayoutDashboard,
  ScrollText,
  Settings,
} from 'lucide-react'
import { useEffect } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { isLocale, useI18n } from '@/i18n'
import type { SystemVersion } from '@/types/system'
import { cn } from '@/lib/utils'
import { WhatsNewDialog } from '@/features/changelog/WhatsNewDialog'

export function AppShell() {
  const { t, setLocale } = useI18n()
  const version = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
  })

  useEffect(() => {
    void api<{ language: string }>('/admin/api/settings/locale')
      .then((data) => {
        if (isLocale(data.language)) setLocale(data.language)
      })
      .catch(() => undefined)
  }, [setLocale])

  const nav = [
    { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, end: true },
    { to: '/resources', label: t('nav.resources'), icon: FileText },
    { to: '/media', label: t('nav.media'), icon: Image },
    { to: '/logs', label: t('nav.logs'), icon: Activity },
    { to: '/changelog', label: t('nav.changelog'), icon: ScrollText },
    { to: '/settings/tokens', label: t('nav.tokens'), icon: KeyRound },
    { to: '/settings/system', label: t('nav.system'), icon: Settings },
  ]

  return (
    <div className="flex min-h-svh">
      <aside className="flex w-60 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground">
        <div className="px-4 py-4 text-sm font-semibold tracking-tight">HCMS</div>
        <nav className="flex flex-1 flex-col gap-1 px-2">
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent',
                  isActive && 'bg-sidebar-accent font-medium',
                )
              }
            >
              <item.icon className="h-4 w-4" />
              {item.label}
            </NavLink>
          ))}
          <a
            href="/api/docs"
            className="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent"
          >
            <BookOpen className="h-4 w-4" />
            {t('nav.docs')}
          </a>
        </nav>
        <div className="flex items-center justify-between px-4 py-3 text-xs text-muted-foreground">
          <span>v{version.data?.current ?? '…'}</span>
          {version.data?.updateAvailable ? (
            <span
              className="h-2 w-2 rounded-full bg-emerald-500"
              title={t('common.updateAvailable')}
            />
          ) : null}
        </div>
      </aside>
      <main className="flex-1 p-8">
        <Outlet />
      </main>
      {version.data ? <WhatsNewDialog version={version.data} /> : null}
    </div>
  )
}
