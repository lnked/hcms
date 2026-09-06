import {
  Activity,
  BookOpen,
  FileText,
  Image,
  KeyRound,
  LayoutDashboard,
  Moon,
  PanelLeftClose,
  PanelLeftOpen,
  ScrollText,
  Settings,
  Sun,
  Users,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { isLocale, useI18n } from '@/i18n'
import type { SystemVersion } from '@/types/system'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { WhatsNewDialog } from '@/features/changelog/WhatsNewDialog'
import { useTheme } from '@/theme'

const SIDEBAR_COLLAPSED_KEY = 'hcms.sidebar.collapsed'

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'
  } catch {
    return false
  }
}

function writeCollapsed(collapsed: boolean) {
  try {
    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0')
  } catch {
    // ignore
  }
}

export function AppShell() {
  const { t, setLocale } = useI18n()
  const { resolved, toggleLightDark } = useTheme()
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const version = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
    staleTime: 0,
    refetchOnMount: 'always',
  })

  useEffect(() => {
    void api<{ language: string }>('/admin/api/settings/locale')
      .then((data) => {
        if (isLocale(data.language)) setLocale(data.language)
      })
      .catch(() => undefined)
  }, [setLocale])

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev
      writeCollapsed(next)
      return next
    })
  }

  const nav = [
    { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, end: true },
    { to: '/resources', label: t('nav.resources'), icon: FileText },
    { to: '/media', label: t('nav.media'), icon: Image },
    { to: '/logs', label: t('nav.logs'), icon: Activity },
    { to: '/changelog', label: t('nav.changelog'), icon: ScrollText },
    { to: '/settings/tokens', label: t('nav.tokens'), icon: KeyRound },
    { to: '/settings/users', label: t('nav.users'), icon: Users },
    { to: '/settings/system', label: t('nav.system'), icon: Settings },
  ]

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'flex items-center gap-2 rounded-md py-2 text-sm hover:bg-sidebar-accent',
      collapsed ? 'justify-center px-0' : 'px-3',
      isActive && 'bg-sidebar-accent font-medium',
    )

  const isDark = resolved === 'dark'
  const themeLabel = isDark ? t('nav.themeDark') : t('nav.themeLight')

  return (
    <div className="min-h-svh">
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 flex flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] duration-200',
          collapsed ? 'w-14' : 'w-60',
        )}
      >
        <div
          className={cn(
            'flex h-14 shrink-0 items-center',
            collapsed ? 'justify-center px-1' : 'justify-between px-3',
          )}
        >
          {!collapsed ? (
            <div className="px-1 text-sm font-semibold tracking-tight">HCMS</div>
          ) : null}
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 shrink-0 text-sidebar-foreground"
            onClick={toggleCollapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            title={collapsed ? t('nav.expand') : t('nav.collapse')}
          >
            {collapsed ? (
              <PanelLeftOpen className="h-4 w-4" />
            ) : (
              <PanelLeftClose className="h-4 w-4" />
            )}
          </Button>
        </div>

        <nav className={cn('flex flex-1 flex-col gap-1', collapsed ? 'px-1.5' : 'px-2')}>
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              title={collapsed ? item.label : undefined}
              className={linkClass}
            >
              <item.icon className="h-4 w-4 shrink-0" />
              {!collapsed ? <span className="truncate">{item.label}</span> : null}
            </NavLink>
          ))}
          <a
            href="/api/docs"
            title={collapsed ? t('nav.docs') : undefined}
            className={cn(
              'flex items-center gap-2 rounded-md py-2 text-sm hover:bg-sidebar-accent',
              collapsed ? 'justify-center px-0' : 'px-3',
            )}
          >
            <BookOpen className="h-4 w-4 shrink-0" />
            {!collapsed ? <span className="truncate">{t('nav.docs')}</span> : null}
          </a>
        </nav>

        <div
          className={cn('space-y-2 border-t border-sidebar-border', collapsed ? 'p-1.5' : 'p-2')}
        >
          <button
            type="button"
            onClick={toggleLightDark}
            title={t('nav.themeToggle')}
            aria-label={t('nav.themeToggle')}
            aria-pressed={isDark}
            className={cn(
              'flex w-full items-center rounded-md text-sm hover:bg-sidebar-accent',
              collapsed ? 'justify-center px-0 py-2' : 'justify-between gap-2 px-3 py-2',
            )}
          >
            {!collapsed ? (
              <span className="flex items-center gap-2 truncate">
                {isDark ? (
                  <Moon className="h-4 w-4 shrink-0" />
                ) : (
                  <Sun className="h-4 w-4 shrink-0" />
                )}
                <span className="truncate">{themeLabel}</span>
              </span>
            ) : isDark ? (
              <Moon className="h-4 w-4 shrink-0" />
            ) : (
              <Sun className="h-4 w-4 shrink-0" />
            )}
            {!collapsed ? (
              <span
                className={cn(
                  'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                  isDark ? 'bg-primary' : 'bg-muted-foreground/30',
                )}
              >
                <span
                  className={cn(
                    'absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-background shadow transition-transform',
                    isDark && 'translate-x-4',
                  )}
                />
              </span>
            ) : null}
          </button>

          <div
            className={cn(
              'flex items-center gap-2 py-1 text-xs text-muted-foreground',
              collapsed ? 'flex-col justify-center' : 'justify-between px-2',
            )}
            title={collapsed ? `v${version.data?.current ?? '…'}` : undefined}
          >
            <span className={cn(collapsed && 'sr-only')}>v{version.data?.current ?? '…'}</span>
            {version.data?.updateAvailable ? (
              <span
                className="h-2 w-2 rounded-full bg-emerald-500"
                title={t('common.updateAvailable')}
              />
            ) : null}
          </div>
        </div>
      </aside>

      <main
        className={cn(
          'min-h-svh p-8 transition-[margin] duration-200',
          collapsed ? 'ml-14' : 'ml-60',
        )}
      >
        <Outlet />
      </main>
      {version.data ? <WhatsNewDialog version={version.data} /> : null}
    </div>
  )
}
