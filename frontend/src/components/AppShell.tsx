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
  Plug,
  ScrollText,
  Settings,
  Sun,
  Users,
} from 'lucide-react'
import { useEffect, useState, type ReactNode, type SVGProps } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
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
        'min-w-0 overflow-hidden whitespace-nowrap transition-[opacity,max-width] duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]',
        collapsed ? 'max-w-0 opacity-0' : 'max-w-40 opacity-100',
      )}
      aria-hidden={collapsed}
    >
      {children}
    </span>
  )
}

/** Official Swagger mark (Simple Icons), brand green #85EA2D */
function SwaggerIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...props}>
      <path
        fill="currentColor"
        d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm4.691 18.194a.793.793 0 0 1-.791.791H8.1a.793.793 0 0 1-.791-.791V5.806c0-.436.355-.791.791-.791h7.8c.436 0 .791.355.791.791v12.388zm-1.184-9.682h-5.014v1.38h5.014zm0 2.761h-5.014v1.379h5.014zm0 2.76h-5.014v1.38h5.014z"
      />
    </svg>
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
    { to: '/settings/tokens', label: t('nav.tokens'), icon: KeyRound },
    { to: '/settings/users', label: t('nav.users'), icon: Users },
    { to: '/settings/integrations', label: t('nav.integrations'), icon: Plug },
    { to: '/settings/system', label: t('nav.system'), icon: Settings },
    { to: '/docs', label: t('nav.documentation'), icon: BookOpen },
    { to: '/changelog', label: t('nav.changelog'), icon: ScrollText },
  ]

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'flex items-center rounded-md py-2 text-sm hover:bg-sidebar-accent',
      collapsed ? 'justify-center px-0' : 'gap-2 px-3',
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
        <div
          className={cn(
            'flex h-14 shrink-0 items-center',
            collapsed ? 'justify-center' : 'gap-1 px-3',
          )}
        >
          {!collapsed ? (
            <div className="flex min-w-0 flex-1 items-center gap-2 overflow-hidden whitespace-nowrap text-sm font-semibold tracking-tight">
              <img
                src="/admin/favicon.svg"
                alt=""
                width={20}
                height={20}
                className="h-5 w-5 shrink-0"
              />
              HCMS
            </div>
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

        <nav className={cn('flex flex-1 flex-col gap-1', collapsed ? 'px-1' : 'px-2')}>
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
            target="_blank"
            rel="noopener noreferrer"
            title={collapsed ? t('nav.docs') : undefined}
            className={cn(
              'flex items-center rounded-md py-2 text-sm text-[#5C9E14] hover:bg-sidebar-accent dark:text-[#85EA2D]',
              collapsed ? 'justify-center px-0' : 'gap-2 px-3',
            )}
          >
            <SwaggerIcon className="h-4 w-4 shrink-0" />
            <SidebarLabel collapsed={collapsed}>{t('nav.docs')}</SidebarLabel>
          </a>
        </nav>

        <div className={cn('space-y-2 border-t border-sidebar-border', collapsed ? 'p-1' : 'p-2')}>
          <button
            type="button"
            onClick={toggleLightDark}
            title={t('nav.themeToggle')}
            aria-label={t('nav.themeToggle')}
            aria-pressed={isDark}
            className={cn(
              'flex w-full cursor-pointer items-center rounded-md py-2 text-sm hover:bg-sidebar-accent',
              collapsed ? 'justify-center px-0' : 'gap-2 px-3',
            )}
          >
            {isDark ? <Moon className="h-4 w-4 shrink-0" /> : <Sun className="h-4 w-4 shrink-0" />}
            <SidebarLabel collapsed={collapsed}>{themeLabel}</SidebarLabel>
            <span
              className={cn(
                'relative h-5 w-9 min-w-0 shrink-0 rounded-full transition-[opacity,max-width,colors]',
                SIDEBAR_EASE,
                isDark ? 'bg-primary' : 'bg-muted-foreground/30',
                collapsed ? 'max-w-0 opacity-0' : 'ml-auto max-w-9 opacity-100',
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

          <Link
            to={
              version.data?.updateAvailable
                ? '/settings/system?section=update#system-release'
                : '/settings/system?section=version#system-release'
            }
            className={cn(
              'flex items-center py-1 text-xs text-muted-foreground hover:text-foreground',
              collapsed ? 'justify-center' : 'justify-between gap-2 px-2',
            )}
            title={
              version.data?.updateAvailable
                ? t('common.updateAvailable')
                : `v${version.data?.current ?? '…'}`
            }
            aria-label={
              version.data?.updateAvailable ? t('common.updateAvailable') : t('system.version')
            }
          >
            {collapsed ? (
              version.data?.updateAvailable ? (
                <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden />
              ) : (
                <span className="tabular-nums text-[10px] leading-none">
                  {version.data?.current ?? '…'}
                </span>
              )
            ) : (
              <>
                <span className="whitespace-nowrap">v{version.data?.current ?? '…'}</span>
                {version.data?.updateAvailable ? (
                  <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden />
                ) : null}
              </>
            )}
          </Link>
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
