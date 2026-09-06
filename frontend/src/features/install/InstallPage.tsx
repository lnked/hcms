import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { useI18n, type Locale } from '@/i18n'
import { installApi } from '@/lib/api'
import type { InstallStatus } from '@/types/system'

const stepKeys = [
  'install.step.files',
  'install.step.database',
  'install.step.application',
  'install.step.administrator',
] as const

export function InstallPage() {
  const { t, locale, setLocale } = useI18n()
  const [step, setStep] = useState(0)
  const [status, setStatus] = useState<InstallStatus | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [done, setDone] = useState(false)
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
    const result = await installApi<{ ok: boolean; error?: string }>('test-connection', {
      database: db,
    })
    setMessage(
      result.ok ? t('install.connectionOk') : (result.error ?? t('install.connectionFailed')),
    )
  }

  async function complete() {
    setMessage(t('install.installing'))
    try {
      await installApi('complete', {
        database: db,
        application: { ...app, language: locale },
        administrator: admin,
      })
      setDone(true)
    } catch (err) {
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
                    <li key={key} className={ok ? 'text-emerald-600' : 'text-destructive'}>
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
                    onChange={(e) =>
                      setDb((prev) => ({
                        ...prev,
                        [key]: key === 'port' ? Number(e.target.value) : e.target.value,
                      }))
                    }
                  />
                </div>
              ))}
              <div className="flex gap-2">
                <Button type="button" variant="outline" onClick={() => void testConnection()}>
                  {t('install.testConnection')}
                </Button>
                <Button type="button" onClick={() => setStep(2)}>
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
                  onChange={(e) => setApp({ ...app, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('install.url')}</Label>
                <Input value={app.url} onChange={(e) => setApp({ ...app, url: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>{t('install.timezone')}</Label>
                <Input
                  value={app.timezone}
                  onChange={(e) => setApp({ ...app, timezone: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="app-language">{t('common.language')}</Label>
                <LanguageSelect id="app-language" value={locale} onChange={onLanguageChange} />
              </div>
              <div className="space-y-2">
                <Label>{t('install.publicDir')}</Label>
                <Input
                  value={app.publicDir}
                  onChange={(e) => setApp({ ...app, publicDir: e.target.value })}
                  placeholder="public"
                  list="public-dir-suggestions"
                />
                <datalist id="public-dir-suggestions">
                  <option value="public" />
                  <option value="public_html" />
                  <option value="www" />
                  <option value="htdocs" />
                </datalist>
                <p className="text-xs text-muted-foreground">
                  {t('install.publicDirHint', {
                    admin: '/admin',
                    nested: `/{folder}/admin`,
                  })}
                </p>
              </div>
              <Button onClick={() => setStep(3)}>{t('common.continue')}</Button>
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="space-y-2">
                <Label>{t('install.adminName')}</Label>
                <Input
                  value={admin.name}
                  onChange={(e) => setAdmin({ ...admin, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('common.email')}</Label>
                <Input
                  type="email"
                  value={admin.email}
                  onChange={(e) => setAdmin({ ...admin, email: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('common.password')}</Label>
                <Input
                  type="password"
                  value={admin.password}
                  onChange={(e) => setAdmin({ ...admin, password: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('common.confirm')}</Label>
                <Input
                  type="password"
                  value={admin.passwordConfirm}
                  onChange={(e) => setAdmin({ ...admin, passwordConfirm: e.target.value })}
                />
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
