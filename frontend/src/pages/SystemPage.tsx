import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { LanguageSelect } from '@/components/LanguageSelect'
import { FormBlockSkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useAuthMe } from '@/hooks/useAcl'
import { useI18n, type Locale, type MessageKey } from '@/i18n'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showSuccess, showError } from '@/lib/toast'
import { ApiAccessForm, type ApiAccessSettings } from '@/pages/ApiAccessForm'
import styles from './SystemPage.module.css'
import type { SystemVersion } from '@/types/system'

interface UpdateChange {
  version: string
  type: string
  area?: string
  text: string
  migration?: string
}

interface UpdatePreview {
  from: string
  to: string
  direction: 'upgrade' | 'downgrade'
  latest: string | null
  availableVersions: string[]
  updateAvailable: boolean
  hasBreaking: boolean
  backupReady: boolean
  requiresDowngradeAck?: boolean
  changes: UpdateChange[]
  migrationNotes: string[]
}

interface UpdateStatus {
  state: string
  step: string | null
  progress?: number
  error: string | null
  from?: string
  to?: string
  direction?: string
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

type SystemSection = 'version' | 'update' | 'downgrade'

function parseSection(value: string | null): SystemSection {
  if (value === 'update' || value === 'downgrade') return value
  return 'version'
}

function groupChanges(changes: UpdateChange[]): Array<{ version: string; items: UpdateChange[] }> {
  const order: string[] = []
  const map = new Map<string, UpdateChange[]>()
  for (const change of changes) {
    const version = change.version || '?'
    if (!map.has(version)) {
      map.set(version, [])
      order.push(version)
    }
    map.get(version)!.push(change)
  }
  return order.map((version) => ({ version, items: map.get(version) ?? [] }))
}

export function SystemPage() {
  const { t, locale, setLocale } = useI18n()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const section = parseSection(searchParams.get('section'))
  const direction: 'upgrade' | 'downgrade' = section === 'downgrade' ? 'downgrade' : 'upgrade'
  const [ackBreaking, setAckBreaking] = useState(false)
  const [ackDowngrade, setAckDowngrade] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [trackUpdate, setTrackUpdate] = useState(false)
  const [previewAckAt, setPreviewAckAt] = useState(0)
  const [selectedVersion, setSelectedVersion] = useState<string | null>(null)

  function setSection(next: SystemSection) {
    setSelectedVersion(null)
    setAckBreaking(false)
    setAckDowngrade(false)
    setMessage(null)
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

  const adminBaseQuery = useQuery({
    queryKey: queryKeys.settings.adminBase,
    queryFn: () =>
      api<{ adminBase: string; uiBase: string; apiPrefix: string }>('/admin/api/settings/admin-base'),
  })
  const [adminBaseDraft, setAdminBaseDraft] = useState<string | null>(null)
  const adminBaseValue = adminBaseDraft ?? adminBaseQuery.data?.adminBase ?? 'admin'
  const adminBasePreviewUi = adminBaseValue === '' ? '/' : `/${adminBaseValue}`
  const adminBasePreviewApi =
    adminBaseValue === '' ? '/admin/api' : `/${adminBaseValue}/api`

  const saveAdminBase = useMutation({
    mutationFn: (adminBase: string) =>
      api<{ adminBase: { adminBase: string; uiBase: string; apiPrefix: string } }>(
        '/admin/api/settings',
        {
          method: 'PATCH',
          body: JSON.stringify({ adminBase }),
        },
      ),
    onSuccess: (data) => {
      showSuccess(t('system.adminBaseSaved'))
      const ui = data.adminBase.uiBase
      window.location.assign(ui === '' ? '/settings/system' : `${ui}/settings/system`)
    },
  })

  const live = status.data
  const isUpdating =
    live?.state === 'running' || (trackUpdate && live?.state !== 'done' && live?.state !== 'failed')

  const releaseSection = section === 'update' || section === 'downgrade'
  const previewQuery = useQuery({
    queryKey: queryKeys.system.updatePreview(direction, selectedVersion),
    queryFn: () =>
      api<UpdatePreview>('/admin/api/system/update/preview', {
        method: 'POST',
        body: JSON.stringify({
          direction,
          ...(selectedVersion ? { version: selectedVersion } : {}),
        }),
      }),
    enabled: releaseSection && !isUpdating,
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const preview = previewQuery.data ?? null

  if (releaseSection && previewQuery.isSuccess && previewQuery.dataUpdatedAt !== previewAckAt) {
    setPreviewAckAt(previewQuery.dataUpdatedAt)
    setAckBreaking(false)
    setAckDowngrade(false)
    setMessage(null)
    if (selectedVersion === null) {
      if (previewQuery.data.updateAvailable && previewQuery.data.to) {
        setSelectedVersion(previewQuery.data.to)
      } else if (previewQuery.data.availableVersions.length > 0) {
        setSelectedVersion(previewQuery.data.availableVersions[0] ?? null)
      }
    }
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
        body: JSON.stringify({
          direction,
          version: selectedVersion ?? preview?.to,
          acknowledgeBreaking: ackBreaking,
          acknowledgeDowngrade: ackDowngrade,
        }),
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
  const needsDowngradeAck = Boolean(preview?.requiresDowngradeAck)
  const runDisabled =
    !isOwner ||
    !canUpdate ||
    (needsAck && !ackBreaking) ||
    (needsDowngradeAck && !ackDowngrade) ||
    runUpdate.isPending ||
    isUpdating
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
  const visibleSteps =
    (live?.direction ?? direction) === 'downgrade'
      ? UPDATE_STEPS.filter((step) => step !== 'migrate')
      : UPDATE_STEPS
  const stepText =
    currentStep === 'error' ||
    currentStep === 'rolled_back' ||
    currentStep === 'rollback_failed' ||
    isUpdateStep(currentStep)
      ? t(stepLabelKey(currentStep))
      : t('system.updating')

  const previewError =
    releaseSection && previewQuery.isError
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

  const groupedChanges = preview?.changes?.length ? groupChanges(preview.changes) : []

  const sectionHint =
    section === 'version'
      ? t('system.versionHint')
      : section === 'downgrade'
        ? t('system.downgradeHint')
        : t('system.updateHint')

  const versions = preview?.availableVersions ?? []
  const selectValue = selectedVersion ?? preview?.to ?? ''

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
          <CardTitle>{t('system.adminBaseTitle')}</CardTitle>
          <CardDescription>{t('system.adminBaseHint')}</CardDescription>
        </CardHeader>
        <CardContent className={clsx(styles.stackMd)}>
          {adminBaseQuery.isLoading || !adminBaseQuery.data ? (
            <FormBlockSkeleton fields={2} />
          ) : (
            <>
              <div className={clsx(styles.langContent)}>
                <Label htmlFor="admin-base">{t('system.adminBaseLabel')}</Label>
                <Input
                  id="admin-base"
                  value={adminBaseValue}
                  placeholder={t('system.adminBasePlaceholder')}
                  disabled={!isOwner || saveAdminBase.isPending}
                  onChange={(e) =>
                    setAdminBaseDraft(e.target.value.trim().replace(/^\/+/, '').toLowerCase())
                  }
                />
                <p className={clsx(styles.hint)}>
                  {t('system.adminBasePreview', {
                    ui: adminBasePreviewUi,
                    api: adminBasePreviewApi,
                  })}
                </p>
                {!isOwner ? (
                  <p className={clsx(styles.hint)}>{t('system.adminBaseOwnerOnly')}</p>
                ) : null}
              </div>
              <Button
                type="button"
                disabled={
                  !isOwner ||
                  saveAdminBase.isPending ||
                  adminBaseValue === (adminBaseQuery.data.adminBase ?? 'admin')
                }
                onClick={() => saveAdminBase.mutate(adminBaseValue)}
              >
                {t('system.adminBaseSave')}
              </Button>
            </>
          )}
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
            {(['version', 'update', 'downgrade'] as SystemSection[]).map((item) => (
              <Button
                key={item}
                size="sm"
                variant={section === item ? 'default' : 'ghost'}
                onClick={() => setSection(item)}
                className={clsx(styles.tabBtn, section !== item && styles.tabBtnIdle)}
              >
                {item === 'version'
                  ? t('system.version')
                  : item === 'update'
                    ? t('system.update')
                    : t('system.downgrade')}
                {item === 'update' && data?.updateAvailable ? (
                  <span className={clsx(styles.dot)} title={t('common.updateAvailable')} />
                ) : null}
              </Button>
            ))}
          </div>
          <CardDescription>{sectionHint}</CardDescription>
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
            {versions.length > 0 ? (
              <div className={clsx(styles.versionPick)}>
                <Label htmlFor="system-target-version">{t('system.selectVersion')}</Label>
                <Select
                  id="system-target-version"
                  value={selectValue}
                  disabled={checking || isUpdating}
                  onChange={(e) => setSelectedVersion(e.target.value || null)}
                  containerClassName={styles.versionSelect}
                >
                  {versions.map((version) => (
                    <option key={version} value={version}>
                      v{version}
                      {preview?.latest === version ? ` (${t('system.latestTag')})` : ''}
                    </option>
                  ))}
                </Select>
              </div>
            ) : null}

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
                    ? direction === 'downgrade'
                      ? t('system.downgrading')
                      : t('system.updating')
                    : direction === 'downgrade'
                      ? t('system.downgradeTo', { version: preview.to })
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
                  {visibleSteps.map((step) => {
                    const stepIndex = UPDATE_STEPS.indexOf(step)
                    const done =
                      live?.state === 'done' ||
                      (isUpdateStep(currentStep) && stepIndex < currentStepIndex)
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

            {needsDowngradeAck ? (
              <div className={clsx(styles.breakingBox)}>
                <label className={clsx(styles.checkRow)}>
                  <input
                    type="checkbox"
                    checked={ackDowngrade}
                    onChange={(e) => setAckDowngrade(e.target.checked)}
                  />
                  {t('system.ackDowngrade')}
                </label>
              </div>
            ) : null}

            {preview && !preview.updateAvailable ? (
              <p className={clsx(styles.muted)}>
                {direction === 'downgrade'
                  ? t('system.noDowngradeVersions')
                  : versions.length === 0
                    ? t('system.latestRelease')
                    : t('system.noUpgradeVersions')}
              </p>
            ) : null}

            {groupedChanges.length > 0 ? (
              <div className={clsx(styles.delta)}>
                <p className={clsx(styles.deltaTitle)}>
                  {direction === 'downgrade'
                    ? t('system.changelogUndo')
                    : t('system.changelogDelta')}
                </p>
                <div className={clsx(styles.deltaList)}>
                  {groupedChanges.map((group) => (
                    <div key={group.version} className={clsx(styles.deltaGroup)}>
                      <p className={clsx(styles.deltaVersion)}>v{group.version}</p>
                      <ul className={clsx(styles.deltaGroupList)}>
                        {group.items.map((c, i) => (
                          <li key={`${group.version}-${i}`}>
                            <span className={clsx(styles.monoXs)}>[{c.type}]</span> {c.text}
                          </li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
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
