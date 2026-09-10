import { useEffect, useState } from 'react'
import { clsx } from 'clsx'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { FormBlockSkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { LanguageSelect } from '@/components/LanguageSelect'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess, showError } from '@/lib/toast'
import { useAuthMe } from '@/hooks/useAcl'
import { useI18n, type Locale, type MessageKey } from '@/i18n'
import type { SystemVersion } from '@/types/system'
import { ApiAccessForm, type ApiAccessSettings } from '@/pages/ApiAccessForm'
import styles from './SystemPage.module.css'

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

type SystemSection = 'version' | 'update'

function parseSection(value: string | null): SystemSection {
  return value === 'update' ? 'update' : 'version'
}

export function SystemPage() {
  const { t, locale, setLocale } = useI18n()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const section = parseSection(searchParams.get('section'))
  const [ackBreaking, setAckBreaking] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [trackUpdate, setTrackUpdate] = useState(false)
  const [previewAckAt, setPreviewAckAt] = useState(0)

  function setSection(next: SystemSection) {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev)
        params.set('section', next)
        return params
      },
      { replace: true },
    )
  }

  useEffect(() => {
    if (window.location.hash !== '#system-release') return
    const el = document.getElementById('system-release')
    if (!el) return
    const id = window.requestAnimationFrame(() => {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
    return () => window.cancelAnimationFrame(id)
  }, [section, searchParams])

  const query = useQuery({
    queryKey: queryKeys.system.version,
    queryFn: () => api<SystemVersion>('/admin/api/system/version'),
    staleTime: 0,
    gcTime: 0,
    refetchOnMount: 'always',
  })

  const status = useQuery({
    queryKey: queryKeys.system.updateStatus,
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

  const me = useAuthMe({ retry: false })

  const apiAccess = useQuery({
    queryKey: queryKeys.settings.apiAccess,
    queryFn: () => api<ApiAccessSettings>('/admin/api/settings/api-access'),
  })

  const live = status.data
  const isUpdating =
    live?.state === 'running' || (trackUpdate && live?.state !== 'done' && live?.state !== 'failed')

  const previewQuery = useQuery({
    queryKey: queryKeys.system.updatePreview,
    queryFn: () =>
      api<UpdatePreview>('/admin/api/system/update/preview', { method: 'POST', body: '{}' }),
    enabled: section === 'update' && !isUpdating,
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const preview = previewQuery.data ?? null

  if (
    section === 'update' &&
    previewQuery.isSuccess &&
    previewQuery.dataUpdatedAt !== previewAckAt
  ) {
    setPreviewAckAt(previewQuery.dataUpdatedAt)
    setAckBreaking(false)
    setMessage(null)
  }

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

  const runUpdate = useMutation({
    mutationFn: () =>
      api<UpdateStatus>('/admin/api/system/update/run', {
        method: 'POST',
        body: JSON.stringify({ acknowledgeBreaking: ackBreaking }),
      }),
    onSuccess: (data) => {
      setTrackUpdate(true)
      setMessage(null)
      void queryClient.setQueryData(queryKeys.system.updateStatus, data)
    },
    onError: (err) => {
      setTrackUpdate(false)
      showError(err instanceof Error ? err.message : t('system.updateFailed'))
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
      showSuccess(t('system.languageSaved'))
    },
  })

  function onLanguageChange(next: Locale) {
    setLocale(next)
    saveLanguage.mutate(next)
  }

  const data = query.data
  const isOwner = me.data?.role === 'owner'
  const canUpdate = Boolean(preview?.updateAvailable && preview.backupReady)
  const needsAck = Boolean(preview?.hasBreaking)
  const runDisabled =
    !isOwner || !canUpdate || (needsAck && !ackBreaking) || runUpdate.isPending || isUpdating
  const na = t('system.na')
  const accessKey = apiAccess.data
    ? `${apiAccess.data.unrestricted}:${apiAccess.data.allowedOrigins.join('|')}`
    : 'loading'
  const checking = previewQuery.isFetching

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

  const previewError =
    section === 'update' && previewQuery.isError
      ? previewQuery.error instanceof Error
        ? previewQuery.error.message
        : t('system.previewFailed')
      : null

  const statusMessage =
    trackUpdate && live?.state === 'done'
      ? t('system.updateDone')
      : trackUpdate && live?.state === 'failed'
        ? (live.error ?? t('system.updateFailed'))
        : (previewError ?? message)

  return (
    <div className={clsx(styles.root)}>
      <h1 className={clsx(styles.title)}>{t('system.title')}</h1>

      <Card>
        <CardHeader>
          <CardTitle>{t('system.languageTitle')}</CardTitle>
          <CardDescription>{t('system.languageHint')}</CardDescription>
        </CardHeader>
        <CardContent className={clsx(styles.langContent)}>
          <Label htmlFor="admin-language">{t('common.language')}</Label>
          <LanguageSelect
            id="admin-language"
            value={locale}
            onChange={onLanguageChange}
            className={clsx(saveLanguage.isPending && styles.pending)}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('system.apiAccessTitle')}</CardTitle>
          <CardDescription>{t('system.apiAccessHint')}</CardDescription>
        </CardHeader>
        <CardContent className={clsx(styles.stackMd)}>
          {apiAccess.isLoading || !apiAccess.data ? (
            <FormBlockSkeleton fields={3} />
          ) : (
            <ApiAccessForm key={accessKey} initial={apiAccess.data} />
          )}
        </CardContent>
      </Card>

      <Card id="system-release">
        <CardHeader className={clsx(styles.cardHeaderStack)}>
          <div className={clsx(styles.tabs)}>
            {(['version', 'update'] as SystemSection[]).map((item) => (
              <Button
                key={item}
                size="sm"
                variant={section === item ? 'default' : 'ghost'}
                onClick={() => setSection(item)}
                className={clsx(styles.tabBtn, section !== item && styles.tabBtnIdle)}
              >
                {item === 'version' ? t('system.version') : t('system.update')}
                {item === 'update' && data?.updateAvailable ? (
                  <span className={clsx(styles.dot)} title={t('common.updateAvailable')} />
                ) : null}
              </Button>
            ))}
          </div>
          <CardDescription>
            {section === 'version' ? t('system.versionHint') : t('system.updateHint')}
          </CardDescription>
        </CardHeader>
        {section === 'version' ? (
          <CardContent className={clsx(styles.infoContent)}>
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
        ) : (
          <CardContent className={clsx(styles.stackMd)}>
            <div className={clsx(styles.actionsRow)}>
              <Button
                variant="outline"
                disabled={checking || isUpdating}
                onClick={() => void previewQuery.refetch()}
              >
                {checking ? t('system.checking') : t('system.checkUpdates')}
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
              <div className={clsx(styles.progressBox)}>
                <div className={clsx(styles.progressHeader)}>
                  <p className={clsx(styles.progressTitle)}>{t('system.updateProgress')}</p>
                  <span className={clsx(styles.progressPct)}>{progress}%</span>
                </div>
                <div
                  className={clsx(styles.progressTrack)}
                  role="progressbar"
                  aria-valuenow={progress}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label={t('system.updateProgress')}
                >
                  <div
                    className={clsx(
                      styles.progressBar,
                      live?.state === 'failed' && styles.progressBarError,
                    )}
                    style={{ width: `${progress}%` }}
                  />
                </div>
                <p className={clsx(styles.muted)}>{stepText}</p>
                <ol className={clsx(styles.stepList)}>
                  {UPDATE_STEPS.map((step, index) => {
                    const done =
                      live?.state === 'done' ||
                      (isUpdateStep(currentStep) && index < currentStepIndex)
                    const active = currentStep === step && live?.state === 'running'
                    return (
                      <li
                        key={step}
                        className={clsx(
                          styles.stepItem,
                          done && styles.stepItemDone,
                          active && styles.stepItemActive,
                        )}
                      >
                        <span
                          className={clsx(styles.stepDot, (done || active) && styles.stepDotOn)}
                        />
                        {t(stepLabelKey(step))}
                      </li>
                    )
                  })}
                </ol>
              </div>
            ) : null}

            {preview?.updateAvailable && !isOwner ? (
              <p className={clsx(styles.muted)}>{t('system.ownerOnly')}</p>
            ) : null}

            {preview?.hasBreaking ? (
              <div className={clsx(styles.breakingBox)}>
                <p className={clsx(styles.breakingTitle)}>{t('system.breakingTitle')}</p>
                <ul className={clsx(styles.breakingList)}>
                  {preview.changes
                    .filter((c) => c.type === 'breaking')
                    .map((c, i) => (
                      <li key={i}>
                        <span className={clsx(styles.monoXs)}>v{c.version}</span> — {c.text}
                        {c.migration ? (
                          <span className={clsx(styles.blockMuted)}>
                            {t('system.migration', { text: c.migration })}
                          </span>
                        ) : null}
                      </li>
                    ))}
                </ul>
                <label className={clsx(styles.checkRow)}>
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
              <p className={clsx(styles.muted)}>{t('system.latestRelease')}</p>
            ) : null}

            {preview?.changes && preview.changes.length > 0 ? (
              <div className={clsx(styles.delta)}>
                <p className={clsx(styles.deltaTitle)}>{t('system.changelogDelta')}</p>
                <ul className={clsx(styles.deltaList)}>
                  {preview.changes.map((c, i) => (
                    <li key={i}>
                      <span className={clsx(styles.monoXs)}>[{c.type}]</span> {c.text}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            {statusMessage ? (
              <p
                className={clsx(
                  styles.statusMsg,
                  live?.state === 'failed' && styles.statusMsgError,
                )}
              >
                {statusMessage}
              </p>
            ) : null}
          </CardContent>
        )}
      </Card>
    </div>
  )
}
