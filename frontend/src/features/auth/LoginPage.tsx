import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { useI18n } from '@/i18n'
import { api, ApiError, clearToken, setToken } from '@/lib/api'
import { showError } from '@/lib/toast'
import type { AuthUser } from '@/types/system'

interface CaptchaConfig {
  enabled: boolean
  provider: 'turnstile' | 'hcaptcha' | null
  siteKey: string
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
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    clearToken()
  }, [])

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
      clearToken()
      const body: Record<string, string> = { email, password }
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
          <form onSubmit={onSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">{t('common.email')}</Label>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">{t('common.password')}</Label>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </div>
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
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <Button type="submit" className="w-full" disabled={pending}>
              {pending ? t('login.submitting') : t('login.submit')}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
