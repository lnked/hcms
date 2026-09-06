import {
  createContext,
  createElement,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from 'react'
import {
  applyResolvedTheme,
  readStoredTheme,
  writeStoredTheme,
  type ResolvedTheme,
  type ThemePreference,
} from './theme'

interface ThemeContextValue {
  preference: ThemePreference
  resolved: ResolvedTheme
  setPreference: (preference: ThemePreference) => void
  toggleLightDark: () => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function subscribeSystemTheme(onStoreChange: () => void): () => void {
  const mq = window.matchMedia('(prefers-color-scheme: dark)')
  mq.addEventListener('change', onStoreChange)
  return () => mq.removeEventListener('change', onStoreChange)
}

function getSystemSnapshot(): ResolvedTheme {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function getServerSnapshot(): ResolvedTheme {
  return 'light'
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, setPreferenceState] = useState<ThemePreference>(() => readStoredTheme())
  const systemTheme = useSyncExternalStore(
    subscribeSystemTheme,
    getSystemSnapshot,
    getServerSnapshot,
  )
  const resolved: ResolvedTheme = preference === 'system' ? systemTheme : preference

  const setPreference = useCallback((next: ThemePreference) => {
    writeStoredTheme(next)
    setPreferenceState(next)
  }, [])

  const toggleLightDark = useCallback(() => {
    const next: ThemePreference = resolved === 'dark' ? 'light' : 'dark'
    writeStoredTheme(next)
    setPreferenceState(next)
  }, [resolved])

  useEffect(() => {
    applyResolvedTheme(resolved)
  }, [resolved])

  const value = useMemo(
    () => ({ preference, resolved, setPreference, toggleLightDark }),
    [preference, resolved, setPreference, toggleLightDark],
  )

  return createElement(ThemeContext.Provider, { value }, children)
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) {
    throw new Error('useTheme must be used within ThemeProvider')
  }
  return ctx
}
