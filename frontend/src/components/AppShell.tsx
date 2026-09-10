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
import { clsx } from 'clsx'
import { PageSkeleton } from '@/components/skeletons'
import { api, clearToken } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { useAuthMe } from '@/hooks/useAcl'
import { isLocale, useI18n } from '@/i18n'
import type { SystemVersion } from '@/types/system'
import { canAccessNav, type AdminRole, type AdminSection } from '@/lib/rbac'
import { Button } from '@/components/ui/button'
import { WhatsNewDialog } from '@/features/changelog/WhatsNewDialog'
import { useTheme } from '@/theme'
import styles from './AppShell.module.css'

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
      className={clsx(
        styles.sidebarLabel,
        collapsed ? styles.sidebarLabelCollapsed : styles.sidebarLabelOpen,
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
    <nav className={styles.nav}>
      {nav.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          title={collapsed ? item.label : undefined}
          className={linkClass}
          onClick={onNavigate}
        >
          <item.icon className={styles.icon} />
          <SidebarLabel collapsed={collapsed} className={styles.sidebarLabelGap}>
            {item.label}
          </SidebarLabel>
        </NavLink>
      ))}
      <a
        href="/api/docs"
        target="_blank"
        rel="noopener noreferrer"
        title={collapsed ? t('nav.docs') : undefined}
        className={styles.docsLink}
        onClick={onNavigate}
      >
        <SwaggerIcon className={styles.icon} />
        <SidebarLabel collapsed={collapsed} className={styles.sidebarLabelGap}>
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
    <div className={styles.footer}>
      <button
        type="button"
        onClick={() => void logout()}
        disabled={loggingOut}
        title={t('nav.logout')}
        aria-label={t('nav.logout')}
        className={styles.footerButton}
      >
        <LogOut className={styles.icon} />
        <SidebarLabel collapsed={collapsed} className={styles.sidebarLabelGap}>
          {t('nav.logout')}
        </SidebarLabel>
      </button>
      <button
        type="button"
        onClick={toggleLightDark}
        title={t('nav.themeToggle')}
        aria-label={t('nav.themeToggle')}
        aria-pressed={isDark}
        className={styles.footerButton}
      >
        {isDark ? <Moon className={styles.icon} /> : <Sun className={styles.icon} />}
        <SidebarLabel collapsed={collapsed} className={styles.sidebarLabelGap}>
          {themeLabel}
        </SidebarLabel>
        <span
          className={clsx(
            styles.themeTrack,
            isDark ? styles.themeTrackOn : styles.themeTrackOff,
            collapsed ? styles.themeTrackCollapsed : styles.themeTrackOpen,
          )}
          aria-hidden={collapsed}
        >
          <span className={clsx(styles.themeThumb, isDark && styles.themeThumbOn)} />
        </span>
      </button>

      <Link
        to={
          version?.updateAvailable
            ? '/settings/system?section=update#system-release'
            : '/settings/system?section=version#system-release'
        }
        onClick={onNavigate}
        className={styles.versionLink}
        title={
          version?.updateAvailable ? t('common.updateAvailable') : `v${version?.current ?? '…'}`
        }
        aria-label={version?.updateAvailable ? t('common.updateAvailable') : t('system.version')}
      >
        <span className={styles.versionDotWrap} aria-hidden>
          <span
            className={clsx(
              styles.versionDot,
              version?.updateAvailable ? styles.versionDotUpdate : styles.versionDotOk,
            )}
          />
        </span>
        <SidebarLabel collapsed={collapsed} className={styles.sidebarLabelGap}>
          <span className={styles.versionNum}>v{version?.current ?? '…'}</span>
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
    queryKey: queryKeys.system.version,
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
    staleTime: 0,
    refetchOnMount: 'always',
  })
  const me = useAuthMe()

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
      {
        to: '/',
        label: t('nav.dashboard'),
        icon: LayoutDashboard,
        end: true,
        section: 'dashboard',
      },
      { to: '/resources', label: t('nav.resources'), icon: FileText, section: 'resources' },
      {
        to: '/media',
        label: t('nav.media'),
        icon: Image,
        section: 'media',
        minRole: 'editor' as const,
      },
      {
        to: '/logs',
        label: t('nav.logs'),
        icon: Activity,
        section: 'logs',
        minRole: 'admin' as const,
      },
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
    clsx(styles.navLink, isActive && styles.navLinkActive)

  const closeMobile = () => setMobileOpen(false)

  return (
    <div className={styles.root}>
      <header className={styles.mobileHeader}>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className={styles.btnIconMd}
          onClick={() => setMobileOpen(true)}
          aria-label={t('nav.openMenu')}
        >
          <Menu className={styles.iconMd} />
        </Button>
        <div className={styles.mobileBrand}>
          <img
            src="/admin/favicon.svg"
            alt=""
            width={20}
            height={20}
            className={styles.brandMark}
          />
          HCMS
        </div>
      </header>

      <aside
        className={clsx(styles.sidebar, collapsed ? styles.sidebarCollapsed : styles.sidebarOpen)}
      >
        <div className={styles.sidebarHeader}>
          <div
            className={clsx(
              styles.sidebarBrand,
              collapsed ? styles.sidebarBrandCollapsed : styles.sidebarBrandOpen,
            )}
            aria-hidden={collapsed}
          >
            <img
              src="/admin/favicon.svg"
              alt=""
              width={20}
              height={20}
              className={styles.brandMark}
            />
            HCMS
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={clsx(
              styles.collapseBtn,
              collapsed ? styles.collapseBtnCollapsed : styles.collapseBtnOpen,
            )}
            onClick={toggleCollapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            title={collapsed ? t('nav.expand') : t('nav.collapse')}
          >
            {collapsed ? (
              <PanelLeftOpen className={styles.icon} />
            ) : (
              <PanelLeftClose className={styles.icon} />
            )}
          </Button>
        </div>

        <SidebarNav nav={nav} collapsed={collapsed} linkClass={linkClass} />
        <SidebarFooter collapsed={collapsed} version={version.data} />
      </aside>

      <DialogPrimitive.Root open={mobileOpen} onOpenChange={setMobileOpen}>
        <DialogPrimitive.Portal>
          <DialogPrimitive.Overlay className={styles.overlay} />
          <DialogPrimitive.Content className={styles.drawer} aria-label={t('nav.menu')}>
            <DialogPrimitive.Title className={styles.srOnly}>{t('nav.menu')}</DialogPrimitive.Title>
            <DialogPrimitive.Description className={styles.srOnly}>
              {t('nav.menu')}
            </DialogPrimitive.Description>
            <div className={styles.drawerHeader}>
              <div className={styles.drawerBrand}>
                <img
                  src="/admin/favicon.svg"
                  alt=""
                  width={20}
                  height={20}
                  className={styles.brandMark}
                />
                HCMS
              </div>
              <DialogPrimitive.Close asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className={styles.closeBtn}
                  aria-label={t('nav.closeMenu')}
                >
                  <X className={styles.icon} />
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
        className={clsx(
          styles.main,
          collapsed ? styles.mainSidebarCollapsed : styles.mainSidebarOpen,
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
