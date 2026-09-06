import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { api } from '@/lib/api'
import { useI18n, type Locale, type MessageKey } from '@/i18n'
import type { SystemVersion } from '@/types/system'
import { ApiAccessForm, type ApiAccessSettings } from '@/pages/ApiAccessForm'
import { cn } from '@/lib/utils'

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
  progress?: number
  error: string | null
  from?: string
  to?: string
}

const UPDATE_STEPS = [
  'starting',
  'backup',
  'download',
  'unpack',
  'publish',
  'migrate',
  'verify',
] as const

type UpdateStep = (typeof UPDATE_STEPS)[number]

function isUpdateStep(step: string | null | undefined): step is UpdateStep {
  return !!step && (UPDATE_STEPS as readonly string[]).includes(step)
}

function stepLabelKey(step: string): MessageKey {
  return `system.step.${step}` as MessageKey
}

export function SystemPage() {
  const { t, locale, setLocale } = useI18n()
  const queryClient = useQueryClient()
  const [ackBreaking, setAckBreaking] = useState(false)
  const [preview, setPreview] = useState<UpdatePreview | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [trackUpdate, setTrackUpdate] = useState(false)

  const query = useQuery({
    queryKey: ['system-version'],
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
    staleTime: 0,
    gcTime: 0,
    refetchOnMount: 'always',
  })

  const status = useQuery({
    queryKey: ['update-status'],
    queryFn: () => api<UpdateStatus>('/admin/api/system/update/status'),
    staleTime: 0,
    refetchOnMount: 'always',
    refetchInterval: (q) => {
      const state = q.state.data?.state
      if (state === 'running' || trackUpdate) {
        if (state === 'done' || state === 'failed') return false
        return 500
      }
      return false
    },
  })

  const apiAccess = useQuery({
    queryKey: ['settings-api-access'],
    queryFn: () => api<ApiAccessSettings>('/admin/api/settings/api-access'),
  })

  const live = status.data
  const isUpdating =
    live?.state === 'running' || (trackUpdate && live?.state !== 'done' && live?.state !== 'failed')

  useEffect(() => {
    if (!trackUpdate) return
    if (live?.state === 'done') {
      const id = window.setTimeout(() => {
        window.location.reload()
      }, 1200)
      return () => window.clearTimeout(id)
    }
    return undefined
  }, [trackUpdate, live?.state])

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
      setTrackUpdate(true)
      setMessage(null)
      void queryClient.setQueryData(['update-status'], data)
    },
    onError: (err) => {
      setTrackUpdate(false)
      setMessage(err instanceof Error ? err.message : t('system.updateFailed'))
    },
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
  const runDisabled = !canUpdate || (needsAck && !ackBreaking) || runUpdate.isPending || isUpdating
  const na = t('system.na')
  const accessKey = apiAccess.data
    ? `${apiAccess.data.unrestricted}:${apiAccess.data.allowedOrigins.join('|')}`
    : 'loading'

  const showProgress =
    isUpdating ||
    live?.state === 'running' ||
    (trackUpdate && (live?.state === 'done' || live?.state === 'failed'))
  const currentStep = live?.step ?? 'starting'
  const progress = Math.min(100, Math.max(0, live?.progress ?? (live?.state === 'done' ? 100 : 0)))
  const currentStepIndex = isUpdateStep(currentStep) ? UPDATE_STEPS.indexOf(currentStep) : 0
  const stepText =
    currentStep === 'error' ||
    currentStep === 'rolled_back' ||
    currentStep === 'rollback_failed' ||
    isUpdateStep(currentStep)
      ? t(stepLabelKey(currentStep))
      : t('system.updating')

  const statusMessage =
    trackUpdate && live?.state === 'done'
      ? t('system.updateDone')
      : trackUpdate && live?.state === 'failed'
        ? (live.error ?? t('system.updateFailed'))
        : message

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
          <CardTitle>{t('system.apiAccessTitle')}</CardTitle>
          <CardDescription>{t('system.apiAccessHint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {apiAccess.isLoading || !apiAccess.data ? (
            <p className="text-sm text-muted-foreground">{t('system.apiAccessLoading')}</p>
          ) : (
            <ApiAccessForm key={accessKey} initial={apiAccess.data} onMessage={setMessage} />
          )}
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
              disabled={loadPreview.isPending || isUpdating}
              onClick={() => loadPreview.mutate()}
            >
              {loadPreview.isPending ? t('system.checking') : t('system.checkUpdates')}
            </Button>
            {preview?.updateAvailable ? (
              <Button disabled={runDisabled} onClick={() => runUpdate.mutate()}>
                {isUpdating || runUpdate.isPending
                  ? t('system.updating')
                  : t('system.updateTo', { version: preview.to })}
              </Button>
            ) : null}
          </div>

          {showProgress ? (
            <div className="space-y-3 rounded-md border border-border bg-muted/30 p-4">
              <div className="flex items-center justify-between gap-3 text-sm">
                <p className="font-medium">{t('system.updateProgress')}</p>
                <span className="tabular-nums text-muted-foreground">{progress}%</span>
              </div>
              <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={progress}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={t('system.updateProgress')}
              >
                <div
                  className={cn(
                    'h-full rounded-full transition-[width] duration-300',
                    live?.state === 'failed' ? 'bg-destructive' : 'bg-primary',
                  )}
                  style={{ width: `${progress}%` }}
                />
              </div>
              <p className="text-sm text-muted-foreground">{stepText}</p>
              <ol className="space-y-1 text-xs text-muted-foreground">
                {UPDATE_STEPS.map((step, index) => {
                  const done =
                    live?.state === 'done' ||
                    (isUpdateStep(currentStep) && index < currentStepIndex)
                  const active = currentStep === step && live?.state === 'running'
                  return (
                    <li
                      key={step}
                      className={cn(
                        'flex items-center gap-2',
                        done && 'text-foreground',
                        active && 'font-medium text-foreground',
                      )}
                    >
                      <span
                        className={cn(
                          'inline-block h-1.5 w-1.5 rounded-full',
                          done || active ? 'bg-primary' : 'bg-border',
                        )}
                      />
                      {t(stepLabelKey(step))}
                    </li>
                  )
                })}
              </ol>
            </div>
          ) : null}

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

          {statusMessage ? (
            <p
              className={cn(
                'text-sm',
                live?.state === 'failed' ? 'text-destructive' : 'text-muted-foreground',
              )}
            >
              {statusMessage}
            </p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
