import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { useI18n } from '@/i18n'
import { api, ApiError, getToken, setToken } from '@/lib/api'
import { showError } from '@/lib/toast'
import type { AuthUser } from '@/types/system'
import { TelegramLoginButton, type TelegramAuthPayload } from '@/features/auth/TelegramLoginButton'

interface CaptchaConfig {
  enabled: boolean
  provider: 'turnstile' | 'hcaptcha' | null
  siteKey: string
}

interface AuthProviders {
  google: { enabled: boolean; clientId: string }
  telegram: { enabled: boolean; botUsername: string }
}

export function LoginPage() {
  const { t, locale, setLocale } = useI18n()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [totpCode, setTotpCode] = useState('')
  const [needTotp, setNeedTotp] = useState(false)
  const [needCaptcha, setNeedCaptcha] = useState(false)
  const [captchaToken, setCaptchaToken] = useState('')
  const [captcha, setCaptcha] = useState<CaptchaConfig | null>(null)
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const [checkingSession, setCheckingSession] = useState(() => Boolean(getToken()))
  const [telegramPayload, setTelegramPayload] = useState<TelegramAuthPayload | null>(null)

  const providers = useQuery({
    queryKey: ['auth-providers'],
    queryFn: () => api<AuthProviders>('/admin/api/auth/providers'),
    retry: false,
    staleTime: 60_000,
  })

  useEffect(() => {
    const token = getToken()
    if (!token) {
      return
    }
    void api<AuthUser>('/admin/api/auth/me')
      .then(() => navigate('/', { replace: true }))
      .catch(() => setCheckingSession(false))
  }, [navigate])

  useEffect(() => {
    void api<CaptchaConfig>('/admin/api/auth/captcha')
      .then(setCaptcha)
      .catch(() => setCaptcha(null))
  }, [])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setError(null)
    try {
      const body: Record<string, unknown> = { email, password, remember }
      if (needTotp && totpCode) body.totpCode = totpCode
      if ((needCaptcha || captcha?.enabled) && captchaToken) body.captchaToken = captchaToken
      const data = await api<{ token: string; user: AuthUser }>('/admin/api/auth/login', {
        method: 'POST',
        body: JSON.stringify(body),
      })
      setToken(data.token)
      navigate('/', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.code === 'TOTP_REQUIRED') {
        setNeedTotp(true)
        setError(t('login.totpRequired'))
      } else if (err instanceof ApiError && err.code === 'CAPTCHA_REQUIRED') {
        setNeedCaptcha(true)
        setError(t('login.captchaRequired'))
      } else {
        const message = err instanceof Error ? err.message : t('login.failed')
        setError(message)
        showError(message)
      }
    } finally {
      setPending(false)
    }
  }

  async function finishTelegram(payload: TelegramAuthPayload, code?: string) {
    setPending(true)
    setError(null)
    try {
      const data = await api<{ token: string; user: AuthUser }>('/admin/api/auth/telegram', {
        method: 'POST',
        body: JSON.stringify({ ...payload, totpCode: code, remember }),
      })
      setToken(data.token)
      navigate('/', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.code === 'TOTP_REQUIRED') {
        setTelegramPayload(payload)
        setNeedTotp(true)
        setError(t('login.totpRequired'))
      } else if (err instanceof ApiError && err.code === 'ACCOUNT_NOT_LINKED') {
        setError(t('login.oauth.accountNotLinked'))
        showError(t('login.oauth.accountNotLinked'))
      } else {
        const message = err instanceof Error ? err.message : t('login.failed')
        setError(message)
        showError(message)
      }
    } finally {
      setPending(false)
    }
  }

  if (checkingSession) {
    return (
      <div className="flex min-h-svh items-center justify-center p-6 text-sm text-muted-foreground">
        {t('common.loading')}
      </div>
    )
  }

  const googleEnabled = providers.data?.google.enabled === true
  const telegramEnabled = Boolean(
    providers.data?.telegram.enabled && providers.data.telegram.botUsername,
  )

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <div className="flex items-start justify-between gap-3">
            <div className="space-y-1.5">
              <CardTitle>{t('login.title')}</CardTitle>
              <CardDescription>{t('login.description')}</CardDescription>
            </div>
            <div className="w-28 shrink-0">
              <LanguageSelect value={locale} onChange={setLocale} />
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(event) => {
              if (telegramPayload && needTotp) {
                event.preventDefault()
                void finishTelegram(telegramPayload, totpCode)
                return
              }
              void onSubmit(event)
            }}
            className="space-y-4"
          >
            {!telegramPayload ? (
              <>
                <div className="space-y-2">
                  <Label htmlFor="email">{t('common.email')}</Label>
                  <Input
                    id="email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required={!telegramPayload}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="password">{t('common.password')}</Label>
                  <Input
                    id="password"
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required={!telegramPayload}
                  />
                </div>
              </>
            ) : null}
            {needTotp ? (
              <div className="space-y-2">
                <Label htmlFor="totp">{t('login.totp')}</Label>
                <Input
                  id="totp"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={totpCode}
                  onChange={(e) => setTotpCode(e.target.value)}
                  required
                />
              </div>
            ) : null}
            {(needCaptcha || captcha?.enabled) && captcha?.siteKey ? (
              <div className="space-y-2">
                <Label htmlFor="captcha">{t('login.captchaToken')}</Label>
                <Input
                  id="captcha"
                  value={captchaToken}
                  onChange={(e) => setCaptchaToken(e.target.value)}
                  placeholder={t('login.captchaPlaceholder')}
                />
                <p className="text-xs text-muted-foreground">
                  {t('login.captchaHint', { provider: captcha.provider ?? 'captcha' })}
                </p>
              </div>
            ) : null}
            <label className="flex items-center gap-2 text-sm">
              <input
                id="remember"
                type="checkbox"
                className="size-4"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
              />
              <span>{t('login.remember')}</span>
            </label>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <Button type="submit" className="w-full" disabled={pending}>
              {pending ? t('login.submitting') : t('login.submit')}
            </Button>
          </form>
          {googleEnabled || telegramEnabled ? (
            <div className="mt-6 space-y-3">
              <div className="relative text-center text-xs text-muted-foreground">
                <span className="bg-card px-2">{t('login.oauth.or')}</span>
                <div className="absolute inset-x-0 top-1/2 -z-10 h-px bg-border" />
              </div>
              {googleEnabled ? (
                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  disabled={pending}
                  onClick={() => {
                    window.location.assign('/admin/api/auth/google/start')
                  }}
                >
                  {t('login.oauth.google')}
                </Button>
              ) : null}
              {telegramEnabled ? (
                <TelegramLoginButton
                  botUsername={providers.data?.telegram.botUsername ?? ''}
                  onAuth={(user) => void finishTelegram(user)}
                />
              ) : null}
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
