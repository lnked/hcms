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
import { useEffect, useState, type ReactNode } from 'react'
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
const SIDEBAR_EASE = 'duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]'

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

function SidebarLabel({ collapsed, children }: { collapsed: boolean; children: ReactNode }) {
  return (
    <span
      className={cn(
        'overflow-hidden whitespace-nowrap transition-[opacity,max-width] duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]',
        collapsed ? 'max-w-0 opacity-0' : 'max-w-40 opacity-100',
      )}
      aria-hidden={collapsed}
    >
      {children}
    </span>
  )
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
      'flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent',
      isActive && 'bg-sidebar-accent font-medium',
    )

  const isDark = resolved === 'dark'
  const themeLabel = isDark ? t('nav.themeDark') : t('nav.themeLight')

  return (
    <div className="min-h-svh">
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 flex flex-col overflow-hidden border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width]',
          SIDEBAR_EASE,
          collapsed ? 'w-14' : 'w-60',
        )}
      >
        <div className="flex h-14 shrink-0 items-center gap-1 px-3">
          <div
            className={cn(
              'overflow-hidden whitespace-nowrap text-sm font-semibold tracking-tight transition-[opacity,max-width]',
              SIDEBAR_EASE,
              collapsed ? 'max-w-0 opacity-0' : 'max-w-24 flex-1 opacity-100',
            )}
            aria-hidden={collapsed}
          >
            HCMS
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              'h-8 w-8 shrink-0 text-sidebar-foreground',
              collapsed && 'mx-auto',
            )}
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

        <nav className="flex flex-1 flex-col gap-1 px-2">
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              title={collapsed ? item.label : undefined}
              className={linkClass}
            >
              <item.icon className="h-4 w-4 shrink-0" />
              <SidebarLabel collapsed={collapsed}>{item.label}</SidebarLabel>
            </NavLink>
          ))}
          <a
            href="/api/docs"
            title={collapsed ? t('nav.docs') : undefined}
            className="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent"
          >
            <BookOpen className="h-4 w-4 shrink-0" />
            <SidebarLabel collapsed={collapsed}>{t('nav.docs')}</SidebarLabel>
          </a>
        </nav>

        <div className="space-y-2 border-t border-sidebar-border p-2">
          <button
            type="button"
            onClick={toggleLightDark}
            title={t('nav.themeToggle')}
            aria-label={t('nav.themeToggle')}
            aria-pressed={isDark}
            className="flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent"
          >
            {isDark ? (
              <Moon className="h-4 w-4 shrink-0" />
            ) : (
              <Sun className="h-4 w-4 shrink-0" />
            )}
            <SidebarLabel collapsed={collapsed}>{themeLabel}</SidebarLabel>
            <span
              className={cn(
                'relative ml-auto h-5 w-9 shrink-0 rounded-full transition-[opacity,colors]',
                SIDEBAR_EASE,
                isDark ? 'bg-primary' : 'bg-muted-foreground/30',
                collapsed ? 'max-w-0 opacity-0' : 'max-w-9 opacity-100',
              )}
              aria-hidden={collapsed}
            >
              <span
                className={cn(
                  'absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-background shadow transition-transform',
                  SIDEBAR_EASE,
                  isDark && 'translate-x-4',
                )}
              />
            </span>
          </button>

          <div
            className={cn(
              'flex items-center gap-2 px-2 py-1 text-xs text-muted-foreground transition-[justify-content]',
              SIDEBAR_EASE,
              collapsed ? 'justify-center' : 'justify-between',
            )}
            title={collapsed ? `v${version.data?.current ?? '…'}` : undefined}
          >
            <span
              className={cn(
                'overflow-hidden whitespace-nowrap transition-[opacity,max-width]',
                SIDEBAR_EASE,
                collapsed ? 'max-w-0 opacity-0' : 'max-w-16 opacity-100',
              )}
            >
              v{version.data?.current ?? '…'}
            </span>
            {version.data?.updateAvailable ? (
              <span
                className="h-2 w-2 shrink-0 rounded-full bg-emerald-500"
                title={t('common.updateAvailable')}
              />
            ) : null}
          </div>
        </div>
      </aside>

      <main
        className={cn(
          'min-h-svh p-8 transition-[margin-left]',
          SIDEBAR_EASE,
          collapsed ? 'ml-14' : 'ml-60',
        )}
      >
        <Outlet />
      </main>
      {version.data ? <WhatsNewDialog version={version.data} /> : null}
    </div>
  )
}
