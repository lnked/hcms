import * as DialogPrimitive from '@radix-ui/react-dialog'
import {
  Activity,
  BookOpen,
  FileText,
  Image,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Menu,
  Moon,
  PanelLeftClose,
  PanelLeftOpen,
  Plug,
  ScrollText,
  Settings,
  Sun,
  UserCircle,
  Users,
  Webhook,
  X,
} from 'lucide-react'
import { Suspense, useEffect, useState, type ReactNode, type SVGProps } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { PageSkeleton } from '@/components/skeletons'
import { api, clearToken, getToken } from '@/lib/api'
import { isLocale, useI18n } from '@/i18n'
import type { AuthUser, SystemVersion } from '@/types/system'
import { cn } from '@/lib/utils'
import { canAccessNav, type AdminRole, type AdminSection } from '@/lib/rbac'
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

/**
 * Collapsing label. Spacing lives on the label itself (not as a parent `gap`),
 * so it animates away together with the width instead of snapping.
 */
function SidebarLabel({
  collapsed,
  children,
  className,
}: {
  collapsed: boolean
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'min-w-0 overflow-hidden whitespace-nowrap transition-[opacity,max-width,margin] duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]',
        collapsed ? 'mx-0 max-w-0 opacity-0' : 'max-w-40 opacity-100',
        !collapsed && className,
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
  section: AdminSection
  /** Minimum role: viewer < editor < admin < owner */
  minRole?: AdminRole
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
    <nav className="flex flex-1 flex-col gap-1 px-2">
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
          <SidebarLabel collapsed={collapsed} className="ms-2">
            {item.label}
          </SidebarLabel>
        </NavLink>
      ))}
      <a
        href="/api/docs"
        target="_blank"
        rel="noopener noreferrer"
        title={collapsed ? t('nav.docs') : undefined}
        className="flex items-center rounded-md px-3 py-2 text-sm text-[#5C9E14] hover:bg-sidebar-accent dark:text-[#85EA2D]"
        onClick={onNavigate}
      >
        <SwaggerIcon className="h-4 w-4 shrink-0" />
        <SidebarLabel collapsed={collapsed} className="ms-2">
          {t('nav.docs')}
        </SidebarLabel>
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
  const [loggingOut, setLoggingOut] = useState(false)

  async function logout() {
    if (loggingOut) return
    setLoggingOut(true)
    try {
      await api('/admin/api/auth/logout', { method: 'POST' })
    } catch {
      // still drop the local session
    }
    clearToken()
    window.location.assign('/admin/login')
  }

  return (
    <div className="space-y-2 border-t border-sidebar-border p-2">
      <button
        type="button"
        onClick={() => void logout()}
        disabled={loggingOut}
        title={t('nav.logout')}
        aria-label={t('nav.logout')}
        className="flex w-full cursor-pointer items-center rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent"
      >
        <LogOut className="h-4 w-4 shrink-0" />
        <SidebarLabel collapsed={collapsed} className="ms-2">
          {t('nav.logout')}
        </SidebarLabel>
      </button>
      <button
        type="button"
        onClick={toggleLightDark}
        title={t('nav.themeToggle')}
        aria-label={t('nav.themeToggle')}
        aria-pressed={isDark}
        className="flex w-full cursor-pointer items-center rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent"
      >
        {isDark ? <Moon className="h-4 w-4 shrink-0" /> : <Sun className="h-4 w-4 shrink-0" />}
        <SidebarLabel collapsed={collapsed} className="ms-2">
          {themeLabel}
        </SidebarLabel>
        <span
          className={cn(
            'relative ml-auto h-5 w-9 min-w-0 shrink-0 rounded-full transition-[opacity,max-width,colors]',
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

      <Link
        to={
          version?.updateAvailable
            ? '/settings/system?section=update#system-release'
            : '/settings/system?section=version#system-release'
        }
        onClick={onNavigate}
        className="flex items-center px-3 py-1 text-xs text-muted-foreground hover:text-foreground"
        title={
          version?.updateAvailable ? t('common.updateAvailable') : `v${version?.current ?? '…'}`
        }
        aria-label={version?.updateAvailable ? t('common.updateAvailable') : t('system.version')}
      >
        <span className="flex h-4 w-4 shrink-0 items-center justify-center" aria-hidden>
          <span
            className={cn(
              'h-2 w-2 rounded-full',
              version?.updateAvailable ? 'bg-success' : 'bg-muted-foreground/40',
            )}
          />
        </span>
        <SidebarLabel collapsed={collapsed} className="ms-2">
          <span className="tabular-nums">v{version?.current ?? '…'}</span>
        </SidebarLabel>
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
      { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, end: true, section: 'dashboard' },
      { to: '/resources', label: t('nav.resources'), icon: FileText, section: 'resources' },
      {
        to: '/media',
        label: t('nav.media'),
        icon: Image,
        section: 'media',
        minRole: 'editor' as const,
      },
      { to: '/logs', label: t('nav.logs'), icon: Activity, section: 'logs', minRole: 'admin' as const },
      {
        to: '/settings/tokens',
        label: t('nav.tokens'),
        icon: KeyRound,
        section: 'tokens',
        minRole: 'admin' as const,
      },
      {
        to: '/settings/webhooks',
        label: t('nav.webhooks'),
        icon: Webhook,
        section: 'webhooks',
        minRole: 'admin' as const,
      },
      {
        to: '/settings/users',
        label: t('nav.users'),
        icon: Users,
        section: 'users',
        minRole: 'admin' as const,
      },
      { to: '/settings/account', label: t('nav.account'), icon: UserCircle, section: 'account' },
      {
        to: '/settings/integrations',
        label: t('nav.integrations'),
        icon: Plug,
        section: 'integrations',
        minRole: 'admin' as const,
      },
      {
        to: '/settings/system',
        label: t('nav.system'),
        icon: Settings,
        section: 'system',
        minRole: 'admin' as const,
      },
      { to: '/docs', label: t('nav.documentation'), icon: BookOpen, section: 'docs' },
      { to: '/changelog', label: t('nav.changelog'), icon: ScrollText, section: 'changelog' },
    ] satisfies NavItem[]
  ).filter((item) => canAccessNav(me.data, item.section, item.minRole))

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'flex items-center rounded-md px-3 py-2 text-sm hover:bg-sidebar-accent',
      isActive && 'bg-sidebar-accent font-medium text-primary',
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
        <div className="flex h-14 shrink-0 items-center px-3">
          <div
            className={cn(
              'flex min-w-0 flex-1 items-center gap-2 overflow-hidden whitespace-nowrap text-sm font-semibold tracking-tight transition-[opacity,max-width,margin]',
              SIDEBAR_EASE,
              // Logo lines up with the nav icons (nav px-2 + link px-3); the
              // margin has to collapse too, or it would keep width when hidden.
              collapsed ? 'ms-0 max-w-0 opacity-0' : 'ms-2 max-w-40 opacity-100',
            )}
            aria-hidden={collapsed}
          >
            <img
              src="/admin/favicon.svg"
              alt=""
              width={20}
              height={20}
              className="h-5 w-5 shrink-0"
            />
            HCMS
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              'h-8 w-8 shrink-0 text-sidebar-foreground transition-[margin]',
              SIDEBAR_EASE,
              // Open: flush with the right edge of the nav items. Collapsed: no
              // offset, so the button stays centered in the 3.5rem rail.
              collapsed ? 'me-0' : '-me-1',
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

        <SidebarNav nav={nav} collapsed={collapsed} linkClass={linkClass} />
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
              <div className="ms-2 flex min-w-0 flex-1 items-center gap-2 overflow-hidden whitespace-nowrap text-sm font-semibold tracking-tight">
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
                  className="-me-1 h-8 w-8 shrink-0 text-sidebar-foreground"
                  aria-label={t('nav.closeMenu')}
                >
                  <X className="h-4 w-4" />
                </Button>
              </DialogPrimitive.Close>
            </div>
            <SidebarNav
              nav={nav}
              collapsed={false}
              linkClass={linkClass}
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
        <Suspense fallback={<PageSkeleton />}>
          <Outlet />
        </Suspense>
      </main>
      {version.data ? <WhatsNewDialog version={version.data} /> : null}
    </div>
  )
}
