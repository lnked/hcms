import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { useI18n, type Locale } from '@/i18n'
import { ApiError, clearToken, installApi } from '@/lib/api'
import type { InstallStatus } from '@/types/system'

const stepKeys = [
  'install.step.files',
  'install.step.database',
  'install.step.application',
  'install.step.administrator',
] as const

type FieldErrors = Partial<Record<string, string>>

function firstMessage(value: string[] | undefined): string | undefined {
  return value?.[0]
}

function FieldError({ errors, id }: { errors: FieldErrors; id: string }) {
  const msg = errors[id]
  if (!msg) return null
  return <p className="text-xs font-medium text-destructive">{msg}</p>
}

export function InstallPage() {
  const { t, locale, setLocale } = useI18n()
  const [step, setStep] = useState(0)
  const [status, setStatus] = useState<InstallStatus | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [db, setDb] = useState({
    host: '127.0.0.1',
    port: 3306,
    name: 'hcms',
    user: 'root',
    password: '',
    charset: 'utf8mb4',
  })
  const [app, setApp] = useState({
    name: 'HCMS',
    url: window.location.origin,
    timezone: 'UTC',
    publicDir: 'public',
  })
  const [admin, setAdmin] = useState({
    name: '',
    email: '',
    password: '',
    passwordConfirm: '',
  })

  useEffect(() => {
    void installApi<InstallStatus>('status').then((s) => {
      setStatus(s)
      if (s.suggestedPublicDir) {
        setApp((prev) => ({ ...prev, publicDir: s.suggestedPublicDir ?? prev.publicDir }))
      }
      if (s.srcReady) {
        setMessage(t('install.filesPresent'))
        setStep(1)
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only on mount
  }, [])

  function onLanguageChange(next: Locale) {
    setLocale(next)
  }

  function clearError(key: string) {
    setFieldErrors((prev) => {
      if (!prev[key]) return prev
      const next = { ...prev }
      delete next[key]
      return next
    })
  }

  function validateDatabase(): boolean {
    const errors: FieldErrors = {}
    if (!db.host.trim()) errors.host = t('install.validation.hostRequired')
    if (!Number.isFinite(db.port) || db.port < 1 || db.port > 65535) {
      errors.port = t('install.validation.portInvalid')
    }
    if (!db.name.trim()) errors.name = t('install.validation.dbNameRequired')
    if (!db.user.trim()) errors.user = t('install.validation.userRequired')
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  function validateApplication(): boolean {
    const errors: FieldErrors = {}
    if (!app.name.trim()) errors.name = t('install.validation.appNameRequired')
    const url = app.url.trim()
    if (!url) {
      errors.url = t('install.validation.urlRequired')
    } else {
      try {
        const parsed = new URL(url)
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
          errors.url = t('install.validation.urlInvalid')
        }
      } catch {
        errors.url = t('install.validation.urlInvalid')
      }
    }
    if (!app.timezone.trim()) errors.timezone = t('install.validation.timezoneRequired')
    if (!app.publicDir.trim()) errors.publicDir = t('install.validation.publicDirRequired')
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  function validateAdministrator(): boolean {
    const errors: FieldErrors = {}
    if (!admin.name.trim()) errors.name = t('install.validation.nameRequired')
    if (!admin.email.trim()) {
      errors.email = t('install.validation.emailRequired')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(admin.email.trim())) {
      errors.email = t('install.validation.emailInvalid')
    }
    if (!admin.password) {
      errors.password = t('install.validation.passwordRequired')
    } else if (admin.password.length < 8) {
      errors.password = t('install.validation.passwordMin')
    }
    if (!admin.passwordConfirm) {
      errors.passwordConfirm = t('install.validation.passwordConfirmRequired')
    } else if (admin.password !== admin.passwordConfirm) {
      errors.passwordConfirm = t('install.validation.passwordMismatch')
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function download() {
    if (status?.srcReady) {
      setMessage(t('install.filesPresent'))
      setStep(1)
      return
    }
    setMessage(t('install.downloading'))
    try {
      const result = await installApi<{ skipped?: boolean; version?: string }>('download')
      setMessage(
        result.skipped
          ? t('install.filesPresentShort')
          : t('install.downloaded', { version: result.version ?? '' }),
      )
      setStep(1)
    } catch (err) {
      setMessage(err instanceof Error ? err.message : t('install.downloadFailed'))
    }
  }

  async function testConnection() {
    if (!validateDatabase()) return
    const result = await installApi<{ ok: boolean; error?: string }>('test-connection', {
      database: db,
    })
    setMessage(
      result.ok ? t('install.connectionOk') : (result.error ?? t('install.connectionFailed')),
    )
  }

  async function complete() {
    if (!validateAdministrator()) {
      setMessage(null)
      return
    }
    setMessage(t('install.installing'))
    try {
      await installApi('complete', {
        database: db,
        application: { ...app, language: locale },
        administrator: admin,
      })
      // Previous install's admin token is dead — don't carry it into /admin.
      clearToken()
      setDone(true)
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fields).length > 0) {
        const next: FieldErrors = {}
        for (const [key, messages] of Object.entries(err.fields)) {
          const msg = firstMessage(messages)
          if (msg) next[key] = msg
        }
        setFieldErrors(next)
        setMessage(null)
        return
      }
      setMessage(err instanceof Error ? err.message : t('install.failed'))
    }
  }

  if (done) {
    return (
      <div className="mx-auto max-w-lg py-16">
        <Card>
          <CardHeader>
            <CardTitle>{t('install.completedTitle')}</CardTitle>
            <CardDescription>{t('install.completedDescription')}</CardDescription>
          </CardHeader>
          <CardContent>
            <Button
              onClick={() => {
                window.location.href = '/admin'
              }}
            >
              {t('install.openAdmin')}
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const dbFields = [
    ['host', 'install.host'],
    ['port', 'install.port'],
    ['name', 'install.database'],
    ['user', 'install.user'],
    ['password', 'common.password'],
    ['charset', 'install.charset'],
  ] as const

  return (
    <div className="mx-auto max-w-lg py-16">
      <Card>
        <CardHeader className="space-y-4">
          <div className="flex items-start justify-between gap-4">
            <div className="space-y-1.5">
              <CardTitle>{t('install.title')}</CardTitle>
              <CardDescription>
                {t('install.stepOf', {
                  current: step + 1,
                  total: stepKeys.length,
                  name: t(stepKeys[step]),
                })}
              </CardDescription>
            </div>
            <div className="w-36 shrink-0 space-y-1">
              <Label htmlFor="install-lang" className="text-xs text-muted-foreground">
                {t('common.language')}
              </Label>
              <LanguageSelect id="install-lang" value={locale} onChange={onLanguageChange} />
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {step === 0 ? (
            <>
              {status ? (
                <ul className="space-y-1 text-sm">
                  {Object.entries(status.requirements.checks).map(([key, ok]) => (
                    <li key={key} className={ok ? 'text-success' : 'text-destructive'}>
                      {key}: {ok ? t('install.ok') : t('install.fail')}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">{t('install.checking')}</p>
              )}
              <Button onClick={() => void download()}>{t('install.downloadContinue')}</Button>
            </>
          ) : null}

          {step === 1 ? (
            <>
              {dbFields.map(([key, labelKey]) => (
                <div key={key} className="space-y-2">
                  <Label>{t(labelKey)}</Label>
                  <Input
                    type={key === 'password' ? 'password' : 'text'}
                    value={String(db[key])}
                    aria-invalid={Boolean(fieldErrors[key])}
                    onChange={(e) => {
                      clearError(key)
                      setDb((prev) => ({
                        ...prev,
                        [key]: key === 'port' ? Number(e.target.value) : e.target.value,
                      }))
                    }}
                  />
                  <FieldError errors={fieldErrors} id={key} />
                </div>
              ))}
              <div className="flex gap-2">
                <Button type="button" variant="outline" onClick={() => void testConnection()}>
                  {t('install.testConnection')}
                </Button>
                <Button
                  type="button"
                  onClick={() => {
                    if (!validateDatabase()) return
                    setFieldErrors({})
                    setStep(2)
                  }}
                >
                  {t('common.continue')}
                </Button>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="space-y-2">
                <Label>{t('install.appName')}</Label>
                <Input
                  value={app.name}
                  aria-invalid={Boolean(fieldErrors.name)}
                  onChange={(e) => {
                    clearError('name')
                    setApp({ ...app, name: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="name" />
              </div>
              <div className="space-y-2">
                <Label>{t('install.url')}</Label>
                <Input
                  value={app.url}
                  aria-invalid={Boolean(fieldErrors.url)}
                  onChange={(e) => {
                    clearError('url')
                    setApp({ ...app, url: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="url" />
              </div>
              <div className="space-y-2">
                <Label>{t('install.timezone')}</Label>
                <Input
                  value={app.timezone}
                  aria-invalid={Boolean(fieldErrors.timezone)}
                  onChange={(e) => {
                    clearError('timezone')
                    setApp({ ...app, timezone: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="timezone" />
              </div>
              <div className="space-y-2">
                <Label htmlFor="app-language">{t('common.language')}</Label>
                <LanguageSelect id="app-language" value={locale} onChange={onLanguageChange} />
              </div>
              <div className="space-y-2">
                <Label>{t('install.publicDir')}</Label>
                <Input
                  value={app.publicDir}
                  aria-invalid={Boolean(fieldErrors.publicDir)}
                  onChange={(e) => {
                    clearError('publicDir')
                    setApp({ ...app, publicDir: e.target.value })
                  }}
                  placeholder="public"
                  list="public-dir-suggestions"
                />
                <datalist id="public-dir-suggestions">
                  <option value="public" />
                  <option value="public_html" />
                  <option value="www" />
                  <option value="htdocs" />
                </datalist>
                <FieldError errors={fieldErrors} id="publicDir" />
                <p className="text-xs text-muted-foreground">
                  {status?.insideWebRoot
                    ? t('install.publicDirHintInside', { folder: app.publicDir })
                    : t('install.publicDirHint', {
                        admin: '/admin',
                        nested: `/${app.publicDir}/admin`,
                      })}
                </p>
              </div>
              <Button
                onClick={() => {
                  if (!validateApplication()) return
                  setFieldErrors({})
                  setStep(3)
                }}
              >
                {t('common.continue')}
              </Button>
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="space-y-2">
                <Label>{t('install.adminName')}</Label>
                <Input
                  value={admin.name}
                  aria-invalid={Boolean(fieldErrors.name)}
                  onChange={(e) => {
                    clearError('name')
                    setAdmin({ ...admin, name: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="name" />
              </div>
              <div className="space-y-2">
                <Label>{t('common.email')}</Label>
                <Input
                  type="email"
                  value={admin.email}
                  aria-invalid={Boolean(fieldErrors.email)}
                  onChange={(e) => {
                    clearError('email')
                    setAdmin({ ...admin, email: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="email" />
              </div>
              <div className="space-y-2">
                <Label>{t('common.password')}</Label>
                <Input
                  type="password"
                  value={admin.password}
                  aria-invalid={Boolean(fieldErrors.password)}
                  onChange={(e) => {
                    clearError('password')
                    setAdmin({ ...admin, password: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="password" />
              </div>
              <div className="space-y-2">
                <Label>{t('common.confirm')}</Label>
                <Input
                  type="password"
                  value={admin.passwordConfirm}
                  aria-invalid={Boolean(fieldErrors.passwordConfirm)}
                  onChange={(e) => {
                    clearError('passwordConfirm')
                    setAdmin({ ...admin, passwordConfirm: e.target.value })
                  }}
                />
                <FieldError errors={fieldErrors} id="passwordConfirm" />
              </div>
              <Button onClick={() => void complete()}>{t('install.install')}</Button>
            </>
          ) : null}

          {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
        </CardContent>
      </Card>
    </div>
  )
}
