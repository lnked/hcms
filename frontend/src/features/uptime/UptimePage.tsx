import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { Ban, CircleCheck, Pencil, RefreshCw, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { CodeBlock } from '@/components/CodeBlock'
import { EmptyState } from '@/components/EmptyState'
import { FieldError } from '@/components/FieldError'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { formatDateValue } from '@/lib/dateFormat'
import { apiFieldErrors, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './UptimePage.module.css'

interface UptimeTarget {
  id: number
  name: string
  url: string
  kind: 'external' | 'self'
  method: string
  expectedStatus: number
  timeoutMs: number
  intervalSeconds: number
  enabled: boolean
  lastCheckAt: string | null
  lastOk: boolean | null
  lastStatusCode: number | null
  lastLatencyMs: number | null
  lastError: string | null
  lastHeartbeatAt: string | null
  createdAt: string
  updatedAt: string
}

interface UptimeIncident {
  id: number
  targetId: number
  startedAt: string
  endedAt: string | null
  durationSeconds: number | null
  reason: string | null
}

interface UptimeSummary {
  up: number
  down: number
  unknown: number
  total: number
  uptimePercent24h: number
  uptimePercent7d: number
  openIncidents: number
}

interface UptimeSchedulerHealth {
  state: 'ok' | 'stale' | 'never' | 'idle'
  softCronEnabled: boolean
  lastCheckAt: string | null
  overdueCount: number
  enabledCount: number
}

interface UptimeStatusPayload {
  summary: UptimeSummary
  targets: UptimeTarget[]
  scheduler: UptimeSchedulerHealth
}

function formatDuration(seconds: number | null, ongoingLabel: string): string {
  if (seconds === null) return ongoingLabel
  if (seconds < 60) return `${seconds}s`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  if (m < 60) return s > 0 ? `${m}m ${s}s` : `${m}m`
  const h = Math.floor(m / 60)
  const rm = m % 60
  return rm > 0 ? `${h}h ${rm}m` : `${h}h`
}

function statusVariant(target: UptimeTarget): 'default' | 'secondary' | 'destructive' | 'success' {
  if (target.lastOk === null) return 'secondary'
  return target.lastOk ? 'success' : 'destructive'
}

function schedulerVariant(
  state: UptimeSchedulerHealth['state'],
): 'default' | 'secondary' | 'destructive' | 'success' {
  if (state === 'ok') return 'success'
  if (state === 'stale') return 'destructive'
  return 'secondary'
}

function schedulerLabelKey(
  state: UptimeSchedulerHealth['state'],
):
  | 'uptime.schedulerOk'
  | 'uptime.schedulerStale'
  | 'uptime.schedulerNever'
  | 'uptime.schedulerIdle' {
  if (state === 'ok') return 'uptime.schedulerOk'
  if (state === 'stale') return 'uptime.schedulerStale'
  if (state === 'never') return 'uptime.schedulerNever'
  return 'uptime.schedulerIdle'
}

export function UptimePage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [intervalSeconds, setIntervalSeconds] = useState('60')
  const [expectedStatus, setExpectedStatus] = useState('200')
  const [timeoutMs, setTimeoutMs] = useState('5000')
  const [enabled, setEnabled] = useState(true)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const isEdit = editingId !== null

  const statusQuery = useQuery({
    queryKey: ['uptime-status'],
    queryFn: () => api<UptimeStatusPayload>('/admin/api/uptime/status'),
    refetchInterval: 30_000,
  })

  const incidents = useQuery({
    queryKey: ['uptime-incidents', selectedId],
    queryFn: () =>
      api<UptimeIncident[]>(`/admin/api/uptime/targets/${selectedId}/incidents?limit=50`),
    enabled: selectedId !== null,
  })

  const targets = useMemo(() => statusQuery.data?.targets ?? [], [statusQuery.data?.targets])
  const summary = statusQuery.data?.summary
  const scheduler = statusQuery.data?.scheduler
  const selected = useMemo(
    () => targets.find((row) => row.id === selectedId) ?? null,
    [targets, selectedId],
  )

  function resetForm() {
    setEditingId(null)
    setName('')
    setUrl('')
    setIntervalSeconds('60')
    setExpectedStatus('200')
    setTimeoutMs('5000')
    setEnabled(true)
    setFieldErrors({})
  }

  function openCreate() {
    resetForm()
    setOpen(true)
  }

  function openEdit(target: UptimeTarget) {
    setEditingId(target.id)
    setName(target.name)
    setUrl(target.url)
    setIntervalSeconds(String(target.intervalSeconds))
    setExpectedStatus(String(target.expectedStatus))
    setTimeoutMs(String(target.timeoutMs))
    setEnabled(target.enabled)
    setFieldErrors({})
    setOpen(true)
  }

  const create = useMutation({
    mutationFn: () =>
      api<UptimeTarget>('/admin/api/uptime/targets', {
        method: 'POST',
        body: JSON.stringify({
          name,
          url,
          intervalSeconds: Number(intervalSeconds),
          expectedStatus: Number(expectedStatus),
          timeoutMs: Number(timeoutMs),
          enabled,
        }),
      }),
    onSuccess: (data) => {
      showSuccess(t('common.saved'))
      setOpen(false)
      resetForm()
      setSelectedId(data.id)
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const update = useMutation({
    mutationFn: (id: number) => {
      const target = targets.find((row) => row.id === id)
      const body: Record<string, unknown> = {
        name,
        intervalSeconds: Number(intervalSeconds),
        timeoutMs: Number(timeoutMs),
        enabled,
      }
      if (target?.kind !== 'self') {
        body.url = url
        body.expectedStatus = Number(expectedStatus)
      }
      return api<UptimeTarget>(`/admin/api/uptime/targets/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
    },
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/uptime/targets/${id}`, { method: 'DELETE' }),
    onSuccess: (_data, id) => {
      if (selectedId === id) setSelectedId(null)
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
    },
  })

  const toggleEnabled = useMutation({
    mutationFn: (target: UptimeTarget) =>
      api<UptimeTarget>(`/admin/api/uptime/targets/${target.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ enabled: !target.enabled }),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
    },
  })

  const checkNow = useMutation({
    mutationFn: (id: number) =>
      api<unknown>(`/admin/api/uptime/targets/${id}/check`, { method: 'POST' }),
    onSuccess: (_data, id) => {
      showSuccess(t('uptime.checked'))
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-incidents', id] })
    },
  })

  const runAll = useMutation({
    mutationFn: () => api<{ checked: number }>('/admin/api/uptime/run', { method: 'POST' }),
    onSuccess: (data) => {
      showSuccess(t('uptime.runDone', { count: String(data.checked) }))
      void queryClient.invalidateQueries({ queryKey: ['uptime-status'] })
      void queryClient.invalidateQueries({ queryKey: ['uptime-summary'] })
      if (selectedId !== null) {
        void queryClient.invalidateQueries({ queryKey: ['uptime-incidents', selectedId] })
      }
    },
  })

  const editingSelf = isEdit && targets.find((row) => row.id === editingId)?.kind === 'self'

  const baseUrl = typeof window !== 'undefined' ? window.location.origin : 'https://example.com'
  const cronCliCmd = `cd /path/to/hcms && php cms uptime:check >/dev/null 2>&1`
  const cronHttpCmd = `curl -fsS -X POST \\\n  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \\\n  ${baseUrl}/admin/api/uptime/run >/dev/null`
  const cronCli = `* * * * * ${cronCliCmd}`
  const cronHttp = `* * * * * ${cronHttpCmd}`

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h1 className={clsx(styles.title)}>{t('uptime.title')}</h1>
          <p className={clsx(styles.subtitle)}>{t('uptime.subtitle')}</p>
        </div>
        <div className={clsx(styles.headerActions)}>
          <Button variant="outline" onClick={() => runAll.mutate()} disabled={runAll.isPending}>
            {t('uptime.runAll')}
          </Button>
          <Button onClick={openCreate}>{t('uptime.addUrl')}</Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className={clsx(styles.cronHeader)}>
            <div>
              <CardTitle>{t('uptime.cronTitle')}</CardTitle>
              <CardDescription>{t('uptime.cronHint')}</CardDescription>
            </div>
            {scheduler ? (
              <Badge variant={schedulerVariant(scheduler.state)}>
                {t(schedulerLabelKey(scheduler.state))}
              </Badge>
            ) : null}
          </div>
        </CardHeader>
        <CardContent className={clsx(styles.cronStack)}>
          {scheduler ? (
            <div className={clsx(styles.schedulerMeta)}>
              <span className={clsx(styles.mutedXs)}>
                {scheduler.softCronEnabled
                  ? t('uptime.schedulerSoftOn')
                  : t('uptime.schedulerSoftOff')}
              </span>
              <span className={clsx(styles.mutedXs)}>
                {t('uptime.schedulerLastCheck', {
                  time:
                    formatDateValue(scheduler.lastCheckAt, 'DD.MM.YYYY HH:mm:ss') ??
                    t('uptime.statusUnknown'),
                })}
              </span>
              {scheduler.overdueCount > 0 ? (
                <span className={clsx(styles.mutedXs)}>
                  {t('uptime.schedulerOverdue', { count: String(scheduler.overdueCount) })}
                </span>
              ) : null}
              <span className={clsx(styles.mutedXs)}>{t('uptime.schedulerHint')}</span>
            </div>
          ) : null}
          <CodeBlock
            code={cronCli}
            copyCode={cronCliCmd}
            language="bash"
            label={t('uptime.cronCliLabel')}
            rows={2}
          />
          <CodeBlock
            code={cronHttp}
            copyCode={cronHttpCmd}
            language="bash"
            label={t('uptime.cronHttpLabel')}
            rows={4}
          />
        </CardContent>
      </Card>

      <div className={clsx(styles.summaryGrid)}>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.kpiLabel)}>{t('uptime.up')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.kpiValue)}>{summary?.up ?? '—'}</CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.kpiLabel)}>{t('uptime.down')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.kpiValue)}>{summary?.down ?? '—'}</CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.kpiLabel)}>{t('uptime.uptime24h')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.kpiValue)}>
            {summary ? `${summary.uptimePercent24h}%` : '—'}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className={clsx(styles.kpiLabel)}>{t('uptime.openIncidents')}</CardTitle>
          </CardHeader>
          <CardContent className={clsx(styles.kpiValue)}>
            {summary?.openIncidents ?? '—'}
          </CardContent>
        </Card>
      </div>

      <div className={clsx(styles.layout)}>
        <Card>
          <CardHeader>
            <CardTitle>{t('uptime.targets')}</CardTitle>
            <CardDescription>{t('uptime.targetsHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            {statusQuery.isLoading ? (
              <TableSkeleton rows={4} />
            ) : targets.length === 0 ? (
              <EmptyState title={t('uptime.empty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('uptime.colName')}</TableHead>
                    <TableHead>{t('uptime.colStatus')}</TableHead>
                    <TableHead>{t('uptime.colLatency')}</TableHead>
                    <TableHead>{t('uptime.colLastCheck')}</TableHead>
                    <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {targets.map((target) => (
                    <TableRow
                      key={target.id}
                      className={clsx(selectedId === target.id && styles.rowSelected)}
                    >
                      <TableCell>
                        <button
                          type="button"
                          className={clsx(styles.nameBtn)}
                          onClick={() => setSelectedId(target.id)}
                        >
                          {target.name}
                        </button>
                        <div className={clsx(styles.mutedXs, styles.urlCell)} title={target.url}>
                          {target.kind === 'self' ? t('uptime.kindSelf') : target.url}
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant={statusVariant(target)}>
                          {target.lastOk === null
                            ? t('uptime.statusUnknown')
                            : target.lastOk
                              ? t('uptime.statusUp')
                              : t('uptime.statusDown')}
                        </Badge>
                        {!target.enabled ? (
                          <div className={clsx(styles.mutedXs)}>{t('uptime.disabled')}</div>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        {target.lastLatencyMs !== null ? `${target.lastLatencyMs}ms` : '—'}
                      </TableCell>
                      <TableCell className={clsx(styles.mutedXs)}>
                        {target.lastCheckAt ?? '—'}
                      </TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        <div className={clsx(styles.rowActions)}>
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('uptime.checkNow')}
                            title={t('uptime.checkNow')}
                            onClick={() => checkNow.mutate(target.id)}
                            disabled={checkNow.isPending && checkNow.variables === target.id}
                          >
                            <RefreshCw className={clsx(styles.icon)} />
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('common.edit')}
                            title={t('common.edit')}
                            onClick={() => openEdit(target)}
                          >
                            <Pencil className={clsx(styles.icon)} />
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={target.enabled ? t('uptime.disable') : t('uptime.enable')}
                            title={target.enabled ? t('uptime.disable') : t('uptime.enable')}
                            onClick={() => toggleEnabled.mutate(target)}
                          >
                            {target.enabled ? (
                              <Ban className={clsx(styles.icon)} />
                            ) : (
                              <CircleCheck className={clsx(styles.icon)} />
                            )}
                          </Button>
                          {target.kind !== 'self' ? (
                            <Button
                              size="icon"
                              variant="ghost"
                              aria-label={t('common.delete')}
                              title={t('common.delete')}
                              onClick={() => {
                                if (window.confirm(t('uptime.confirmDelete'))) {
                                  remove.mutate(target.id)
                                }
                              }}
                            >
                              <Trash2 className={clsx(styles.iconDanger)} />
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('uptime.incidents')}</CardTitle>
            <CardDescription>{selected ? selected.name : t('uptime.selectTarget')}</CardDescription>
          </CardHeader>
          <CardContent>
            {selectedId === null ? (
              <EmptyState title={t('uptime.selectTarget')} />
            ) : incidents.isLoading ? (
              <TableSkeleton rows={4} />
            ) : (incidents.data ?? []).length === 0 ? (
              <EmptyState title={t('uptime.noIncidents')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('uptime.colStarted')}</TableHead>
                    <TableHead>{t('uptime.colEnded')}</TableHead>
                    <TableHead>{t('uptime.colDuration')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(incidents.data ?? []).map((incident) => (
                    <TableRow key={incident.id}>
                      <TableCell>
                        <div className={clsx(styles.incidentMeta)}>
                          <span>{incident.startedAt}</span>
                          {incident.reason ? (
                            <span className={clsx(styles.mutedXs)}>{incident.reason}</span>
                          ) : null}
                        </div>
                      </TableCell>
                      <TableCell>{incident.endedAt ?? t('uptime.ongoing')}</TableCell>
                      <TableCell>
                        {formatDuration(incident.durationSeconds, t('uptime.ongoing'))}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      </div>

      <Dialog
        open={open}
        onOpenChange={(next) => {
          setOpen(next)
          if (!next) resetForm()
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{isEdit ? t('uptime.editUrl') : t('uptime.addUrl')}</DialogTitle>
            <DialogDescription>{t('uptime.formHint')}</DialogDescription>
          </DialogHeader>
          <div className={clsx(styles.formGrid)}>
            <div className={clsx(styles.formRow)}>
              <Label htmlFor="uptime-name">{t('uptime.fieldName')}</Label>
              <Input id="uptime-name" value={name} onChange={(e) => setName(e.target.value)} />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={clsx(styles.formRow)}>
              <Label htmlFor="uptime-url">{t('uptime.fieldUrl')}</Label>
              <Input
                id="uptime-url"
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                disabled={editingSelf}
              />
              <FieldError messages={fieldErrors.url} />
            </div>
            <div className={clsx(styles.formRow)}>
              <Label htmlFor="uptime-interval">{t('uptime.fieldInterval')}</Label>
              <Input
                id="uptime-interval"
                type="number"
                min={30}
                value={intervalSeconds}
                onChange={(e) => setIntervalSeconds(e.target.value)}
              />
              <FieldError messages={fieldErrors.intervalSeconds} />
            </div>
            {!editingSelf ? (
              <div className={clsx(styles.formRow)}>
                <Label htmlFor="uptime-expected">{t('uptime.fieldExpected')}</Label>
                <Input
                  id="uptime-expected"
                  type="number"
                  min={100}
                  max={599}
                  value={expectedStatus}
                  onChange={(e) => setExpectedStatus(e.target.value)}
                />
                <FieldError messages={fieldErrors.expectedStatus} />
              </div>
            ) : null}
            <div className={clsx(styles.formRow)}>
              <Label htmlFor="uptime-timeout">{t('uptime.fieldTimeout')}</Label>
              <Input
                id="uptime-timeout"
                type="number"
                min={500}
                max={15000}
                value={timeoutMs}
                onChange={(e) => setTimeoutMs(e.target.value)}
              />
              <FieldError messages={fieldErrors.timeoutMs} />
            </div>
            <label className={clsx(styles.formRow)}>
              <span>
                <input
                  type="checkbox"
                  checked={enabled}
                  onChange={(e) => setEnabled(e.target.checked)}
                />{' '}
                {t('uptime.fieldEnabled')}
              </span>
            </label>
            <div className={clsx(styles.formActions)}>
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                onClick={() => {
                  if (isEdit && editingId !== null) update.mutate(editingId)
                  else create.mutate()
                }}
                disabled={create.isPending || update.isPending}
              >
                {t('common.save')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
