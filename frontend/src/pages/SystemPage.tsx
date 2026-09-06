import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { api } from '@/lib/api'
import { useI18n, type Locale } from '@/i18n'
import type { SystemVersion } from '@/types/system'

interface UpdatePreview {
  from: string
  to: string
  updateAvailable: boolean
  hasBreaking: boolean
  backupReady: boolean
  changes: Array<{
    version: string
    type: string
    area?: string
    text: string
    migration?: string
  }>
  migrationNotes: string[]
}

interface UpdateStatus {
  state: string
  step: string | null
  error: string | null
}

export function SystemPage() {
  const { t, locale, setLocale } = useI18n()
  const queryClient = useQueryClient()
  const [ackBreaking, setAckBreaking] = useState(false)
  const [preview, setPreview] = useState<UpdatePreview | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
  })

  const status = useQuery({
    queryKey: ['update-status'],
    queryFn: () => api<UpdateStatus>('/admin/api/system/update/status'),
  })

  const loadPreview = useMutation({
    mutationFn: () =>
      api<UpdatePreview>('/admin/api/system/update/preview', { method: 'POST', body: '{}' }),
    onSuccess: (data) => {
      setPreview(data)
      setAckBreaking(false)
      setMessage(null)
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('system.previewFailed')),
  })

  const runUpdate = useMutation({
    mutationFn: () =>
      api<UpdateStatus>('/admin/api/system/update/run', {
        method: 'POST',
        body: JSON.stringify({ acknowledgeBreaking: ackBreaking }),
      }),
    onSuccess: (data) => {
      const state = t('system.updateState', { state: data.state })
      setMessage(data.step ? `${state} (${data.step})` : state)
      void queryClient.invalidateQueries({ queryKey: ['system-version'] })
      void queryClient.invalidateQueries({ queryKey: ['update-status'] })
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('system.updateFailed')),
  })

  const saveLanguage = useMutation({
    mutationFn: (language: Locale) =>
      api<{ language: string }>('/admin/api/settings', {
        method: 'PATCH',
        body: JSON.stringify({ language }),
      }),
    onSuccess: (data) => {
      if (data.language === 'en' || data.language === 'ru') {
        setLocale(data.language)
      }
      setMessage(t('system.languageSaved'))
    },
    onError: (err) => setMessage(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  function onLanguageChange(next: Locale) {
    setLocale(next)
    saveLanguage.mutate(next)
  }

  const data = query.data
  const canUpdate = Boolean(preview?.updateAvailable && preview.backupReady)
  const needsAck = Boolean(preview?.hasBreaking)
  const runDisabled = !canUpdate || (needsAck && !ackBreaking) || runUpdate.isPending
  const na = t('system.na')

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">{t('system.title')}</h1>

      <Card>
        <CardHeader>
          <CardTitle>{t('system.languageTitle')}</CardTitle>
          <CardDescription>{t('system.languageHint')}</CardDescription>
        </CardHeader>
        <CardContent className="max-w-xs space-y-2">
          <Label htmlFor="admin-language">{t('common.language')}</Label>
          <LanguageSelect
            id="admin-language"
            value={locale}
            onChange={onLanguageChange}
            className={saveLanguage.isPending ? 'opacity-70' : undefined}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('system.version')}</CardTitle>
          <CardDescription>{t('system.versionHint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <p>{t('system.current', { value: data?.current ?? '…' })}</p>
          <p>{t('system.latest', { value: data?.latest ?? na })}</p>
          <p>{t('system.released', { value: data?.releasedAt ?? na })}</p>
          <p>{t('system.channel', { value: data?.channel ?? 'stable' })}</p>
          <p>
            {t('system.updateAvailable', {
              value: data?.updateAvailable ? t('common.yes') : t('common.no'),
            })}
          </p>
          <p>{t('system.lastState', { value: status.data?.state ?? t('system.idle') })}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('system.update')}</CardTitle>
          <CardDescription>{t('system.updateHint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              disabled={loadPreview.isPending}
              onClick={() => loadPreview.mutate()}
            >
              {loadPreview.isPending ? t('system.checking') : t('system.checkUpdates')}
            </Button>
            {preview?.updateAvailable ? (
              <Button disabled={runDisabled} onClick={() => runUpdate.mutate()}>
                {runUpdate.isPending
                  ? t('system.updating')
                  : t('system.updateTo', { version: preview.to })}
              </Button>
            ) : null}
          </div>

          {preview?.hasBreaking ? (
            <div className="space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
              <p className="font-medium text-destructive">{t('system.breakingTitle')}</p>
              <ul className="list-disc space-y-1 pl-5">
                {preview.changes
                  .filter((c) => c.type === 'breaking')
                  .map((c, i) => (
                    <li key={i}>
                      <span className="font-mono text-xs">v{c.version}</span> — {c.text}
                      {c.migration ? (
                        <span className="block text-muted-foreground">
                          {t('system.migration', { text: c.migration })}
                        </span>
                      ) : null}
                    </li>
                  ))}
              </ul>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={ackBreaking}
                  onChange={(e) => setAckBreaking(e.target.checked)}
                />
                {t('system.ackBreaking')}
              </label>
            </div>
          ) : null}

          {preview && !preview.updateAvailable ? (
            <p className="text-sm text-muted-foreground">{t('system.latestRelease')}</p>
          ) : null}

          {preview?.changes && preview.changes.length > 0 ? (
            <div className="space-y-1 text-sm">
              <p className="font-medium">{t('system.changelogDelta')}</p>
              <ul className="max-h-48 space-y-1 overflow-auto text-muted-foreground">
                {preview.changes.map((c, i) => (
                  <li key={i}>
                    <span className="font-mono text-xs">[{c.type}]</span> {c.text}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
          {status.data?.error ? (
            <p className="text-sm text-destructive">{status.data.error}</p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
