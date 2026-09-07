import * as DialogPrimitive from '@radix-ui/react-dialog'
import {
  Activity,
  BookOpen,
  FileText,
  Image,
  KeyRound,
  LayoutDashboard,
  Menu,
  Moon,
  PanelLeftClose,
  PanelLeftOpen,
  Plug,
  ScrollText,
  Settings,
  Sun,
  Users,
  Webhook,
  X,
} from 'lucide-react'
import { useEffect, useState, type ReactNode, type SVGProps } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, getToken } from '@/lib/api'
import { isLocale, useI18n } from '@/i18n'
import type { AuthUser, SystemVersion } from '@/types/system'
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

type NavItem = {
  to: string
  label: string
  icon: typeof LayoutDashboard
  end?: boolean
  /** Minimum role: viewer < editor < admin < owner */
  minRole?: 'viewer' | 'editor' | 'admin' | 'owner'
}

const ROLE_RANK: Record<string, number> = {
  viewer: 1,
  editor: 2,
  admin: 3,
  owner: 4,
}

function roleAllows(userRole: string | undefined, minRole: NavItem['minRole']): boolean {
  if (!minRole) return true
  const rank = ROLE_RANK[userRole ?? 'admin'] ?? 3
  return rank >= (ROLE_RANK[minRole] ?? 0)
}

function SidebarNav({
  nav,
  collapsed,
  linkClass,
  onNavigate,
}: {
  nav: NavItem[]
  collapsed: boolean
  linkClass: (args: { isActive: boolean }) => string
  onNavigate?: () => void
}) {
  const { t } = useI18n()

  return (
    <nav className={cn('flex flex-1 flex-col gap-1', collapsed ? 'px-1' : 'px-2')}>
      {nav.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          title={collapsed ? item.label : undefined}
          className={linkClass}
          onClick={onNavigate}
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
        onClick={onNavigate}
      >
        <SwaggerIcon className="h-4 w-4 shrink-0" />
        <SidebarLabel collapsed={collapsed}>{t('nav.docs')}</SidebarLabel>
      </a>
    </nav>
  )
}

function SidebarFooter({
  collapsed,
  version,
  onNavigate,
}: {
  collapsed: boolean
  version: SystemVersion | undefined
  onNavigate?: () => void
}) {
  const { t } = useI18n()
  const { resolved, toggleLightDark } = useTheme()
  const isDark = resolved === 'dark'
  const themeLabel = isDark ? t('nav.themeDark') : t('nav.themeLight')

  return (
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
          version?.updateAvailable
            ? '/settings/system?section=update#system-release'
            : '/settings/system?section=version#system-release'
        }
        onClick={onNavigate}
        className={cn(
          'flex items-center py-1 text-xs text-muted-foreground hover:text-foreground',
          collapsed ? 'justify-center' : 'justify-between gap-2 px-2',
        )}
        title={
          version?.updateAvailable ? t('common.updateAvailable') : `v${version?.current ?? '…'}`
        }
        aria-label={version?.updateAvailable ? t('common.updateAvailable') : t('system.version')}
      >
        {collapsed ? (
          version?.updateAvailable ? (
            <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden />
          ) : (
            <span className="tabular-nums text-[10px] leading-none">{version?.current ?? '…'}</span>
          )
        ) : (
          <>
            <span className="whitespace-nowrap">v{version?.current ?? '…'}</span>
            {version?.updateAvailable ? (
              <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden />
            ) : null}
          </>
        )}
      </Link>
    </div>
  )
}

export function AppShell() {
  const { t, setLocale } = useI18n()
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [mobileOpen, setMobileOpen] = useState(false)
  const version = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
    staleTime: 0,
    refetchOnMount: 'always',
  })
  const me = useQuery({
    queryKey: ['auth-me', getToken()],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
    staleTime: 30_000,
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

  const nav: NavItem[] = (
    [
      { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, end: true },
      { to: '/resources', label: t('nav.resources'), icon: FileText },
      { to: '/media', label: t('nav.media'), icon: Image, minRole: 'editor' as const },
      { to: '/logs', label: t('nav.logs'), icon: Activity, minRole: 'admin' as const },
      { to: '/settings/tokens', label: t('nav.tokens'), icon: KeyRound, minRole: 'admin' as const },
      {
        to: '/settings/webhooks',
        label: t('nav.webhooks'),
        icon: Webhook,
        minRole: 'admin' as const,
      },
      { to: '/settings/users', label: t('nav.users'), icon: Users, minRole: 'admin' as const },
      {
        to: '/settings/integrations',
        label: t('nav.integrations'),
        icon: Plug,
        minRole: 'admin' as const,
      },
      { to: '/settings/system', label: t('nav.system'), icon: Settings, minRole: 'admin' as const },
      { to: '/docs', label: t('nav.documentation'), icon: BookOpen },
      { to: '/changelog', label: t('nav.changelog'), icon: ScrollText },
    ] satisfies NavItem[]
  ).filter((item) => roleAllows(me.data?.role, item.minRole))

  const desktopLinkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'flex items-center rounded-md py-2 text-sm hover:bg-sidebar-accent',
      collapsed ? 'justify-center px-0' : 'gap-2 px-3',
      isActive && 'bg-sidebar-accent font-medium',
    )

  const mobileLinkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent',
      isActive && 'bg-sidebar-accent font-medium',
    )

  const closeMobile = () => setMobileOpen(false)

  return (
    <div className="min-h-svh">
      <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-background px-4 md:hidden">
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-9 w-9"
          onClick={() => setMobileOpen(true)}
          aria-label={t('nav.openMenu')}
        >
          <Menu className="h-5 w-5" />
        </Button>
        <div className="flex items-center gap-2 text-sm font-semibold tracking-tight">
          <img
            src="/admin/favicon.svg"
            alt=""
            width={20}
            height={20}
            className="h-5 w-5 shrink-0"
          />
          HCMS
        </div>
      </header>

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 hidden flex-col overflow-hidden border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] md:flex',
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

        <SidebarNav nav={nav} collapsed={collapsed} linkClass={desktopLinkClass} />
        <SidebarFooter collapsed={collapsed} version={version.data} />
      </aside>

      <DialogPrimitive.Root open={mobileOpen} onOpenChange={setMobileOpen}>
        <DialogPrimitive.Portal>
          <DialogPrimitive.Overlay className="fixed inset-0 z-40 bg-black/40 md:hidden" />
          <DialogPrimitive.Content
            className={cn(
              'fixed inset-y-0 left-0 z-50 flex w-[min(100vw-3rem,15rem)] flex-col overflow-hidden border-r border-sidebar-border bg-sidebar text-sidebar-foreground outline-none md:hidden',
              SIDEBAR_EASE,
            )}
            aria-label={t('nav.menu')}
          >
            <DialogPrimitive.Title className="sr-only">{t('nav.menu')}</DialogPrimitive.Title>
            <DialogPrimitive.Description className="sr-only">
              {t('nav.menu')}
            </DialogPrimitive.Description>
            <div className="flex h-14 shrink-0 items-center gap-1 px-3">
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
              <DialogPrimitive.Close asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 shrink-0 text-sidebar-foreground"
                  aria-label={t('nav.closeMenu')}
                >
                  <X className="h-4 w-4" />
                </Button>
              </DialogPrimitive.Close>
            </div>
            <SidebarNav
              nav={nav}
              collapsed={false}
              linkClass={mobileLinkClass}
              onNavigate={closeMobile}
            />
            <SidebarFooter collapsed={false} version={version.data} onNavigate={closeMobile} />
          </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
      </DialogPrimitive.Root>

      <main
        className={cn(
          'min-h-svh p-4 transition-[margin-left] md:p-8',
          SIDEBAR_EASE,
          'ml-0',
          collapsed ? 'md:ml-14' : 'md:ml-60',
        )}
      >
        <Outlet />
      </main>
      {version.data ? <WhatsNewDialog version={version.data} /> : null}
    </div>
  )
}
