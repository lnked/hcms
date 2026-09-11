import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { ExternalLink } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CodeBlock } from '@/components/CodeBlock'
import { EmptyState } from '@/components/EmptyState'
import { FieldError } from '@/components/FieldError'
import { TableSkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
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
import { configString } from '@/lib/coerce'
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './FeatureFlagsPage.module.css'

type FlagType = 'boolean' | 'integer' | 'string' | 'object'
type Section = 'flags' | 'api'

interface FeatureFlag {
  id: number
  name: string
  key: string
  type: FlagType
  value: unknown
  description: string | null
  enabled: boolean
  abTest: boolean
  rolloutPercent: number
}

interface ApiSettings {
  enabled: boolean
  path: string
  requireToken: boolean
}

const TYPES: FlagType[] = ['boolean', 'integer', 'string', 'object']

function previewValue(flag: FeatureFlag): string {
  if (flag.abTest && flag.type === 'boolean') {
    return `A/B ${flag.rolloutPercent}%`
  }
  if (flag.type === 'object') return JSON.stringify(flag.value)
  return configString(flag.value)
}

export function FeatureFlagsPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const section = params.get('section') === 'api' ? 'api' : 'flags'
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<FeatureFlag | null>(null)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const [name, setName] = useState('')
  const [key, setKey] = useState('')
  const [type, setType] = useState<FlagType>('boolean')
  const [description, setDescription] = useState('')
  const [boolValue, setBoolValue] = useState(false)
  const [intValue, setIntValue] = useState('0')
  const [stringValue, setStringValue] = useState('')
  const [objectValue, setObjectValue] = useState('{\n  \n}')
  const [jsonError, setJsonError] = useState<string | null>(null)
  const [enabled, setEnabled] = useState(true)
  const [abTest, setAbTest] = useState(false)
  const [rolloutPercent, setRolloutPercent] = useState('50')

  const list = useQuery({
    queryKey: ['feature-flags', search, typeFilter],
    queryFn: () => {
      const q = new URLSearchParams()
      if (search.trim()) q.set('search', search.trim())
      if (typeFilter) q.set('type', typeFilter)
      const qs = q.toString()
      return api<FeatureFlag[]>(`/admin/api/feature-flags${qs ? `?${qs}` : ''}`)
    },
  })

  const settings = useQuery({
    queryKey: ['feature-flags-settings'],
    queryFn: () => api<ApiSettings>('/admin/api/feature-flags/settings'),
  })

  const [apiDraft, setApiDraft] = useState<Partial<ApiSettings> | null>(null)
  const apiServer = settings.data ?? { enabled: true, path: '/api/features', requireToken: false }
  const apiEnabled = apiDraft?.enabled ?? apiServer.enabled
  const apiPath = apiDraft?.path ?? apiServer.path
  const requireToken = apiDraft?.requireToken ?? apiServer.requireToken

  function openCreate() {
    setEditing(null)
    setName('')
    setKey('')
    setType('boolean')
    setDescription('')
    setBoolValue(false)
    setIntValue('0')
    setStringValue('')
    setObjectValue('{\n  \n}')
    setJsonError(null)
    setEnabled(true)
    setAbTest(false)
    setRolloutPercent('50')
    setFieldErrors({})
    setDialogOpen(true)
  }

  function openEdit(flag: FeatureFlag) {
    setEditing(flag)
    setName(flag.name)
    setKey(flag.key)
    setType(flag.type)
    setDescription(flag.description ?? '')
    setEnabled(flag.enabled)
    setAbTest(Boolean(flag.abTest))
    setRolloutPercent(String(flag.rolloutPercent ?? 50))
    setJsonError(null)
    setFieldErrors({})
    if (flag.type === 'boolean') setBoolValue(Boolean(flag.value))
    if (flag.type === 'integer') setIntValue(configString(flag.value))
    if (flag.type === 'string') setStringValue(configString(flag.value))
    if (flag.type === 'object') setObjectValue(JSON.stringify(flag.value ?? {}, null, 2))
    setDialogOpen(true)
  }

  function parseValue(): unknown {
    switch (type) {
      case 'boolean':
        return boolValue
      case 'integer': {
        const n = Number(intValue)
        if (!Number.isFinite(n) || !Number.isInteger(n)) throw new Error(t('flags.invalidInt'))
        return n
      }
      case 'string':
        return stringValue
      case 'object': {
        try {
          const parsed = JSON.parse(objectValue) as unknown
          if (parsed === null || typeof parsed !== 'object') {
            throw new Error(t('flags.invalidJson'))
          }
          setJsonError(null)
          return parsed
        } catch {
          setJsonError(t('flags.invalidJson'))
          throw new Error(t('flags.invalidJson'))
        }
      }
    }
  }

  const save = useMutation({
    mutationFn: async () => {
      const value = parseValue()
      const body: Record<string, unknown> = {
        name,
        type,
        value,
        description: description.trim() || null,
        enabled,
        abTest: type === 'boolean' ? abTest : false,
        rolloutPercent:
          type === 'boolean' && abTest
            ? Math.min(100, Math.max(0, Number.parseInt(rolloutPercent, 10) || 0))
            : 100,
      }
      if (!editing) body.key = key
      if (editing) {
        return api<FeatureFlag>(`/admin/api/feature-flags/${editing.id}`, {
          method: 'PATCH',
          body: JSON.stringify(body),
        })
      }
      return api<FeatureFlag>('/admin/api/feature-flags', {
        method: 'POST',
        body: JSON.stringify(body),
      })
    },
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setFieldErrors({})
      setDialogOpen(false)
      void queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const toggleEnabled = useMutation({
    mutationFn: (flag: FeatureFlag) =>
      api<FeatureFlag>(`/admin/api/feature-flags/${flag.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ enabled: !flag.enabled }),
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['feature-flags'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/feature-flags/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['feature-flags'] }),
  })

  const saveSettings = useMutation({
    mutationFn: () =>
      api<ApiSettings>('/admin/api/feature-flags/settings', {
        method: 'PUT',
        body: JSON.stringify({ enabled: apiEnabled, path: apiPath, requireToken }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setApiDraft(null)
      void queryClient.invalidateQueries({ queryKey: ['feature-flags-settings'] })
      setFieldErrors({})
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const setSection = (next: Section) => {
    const p = new URLSearchParams(params)
    if (next === 'flags') p.delete('section')
    else p.set('section', next)
    setParams(p, { replace: true })
  }

  const curlExample = `curl -s "${apiPath}?keys=enabledNews,newCheckout&subject=user-42"`

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <h1 className={clsx(styles.title)}>{t('flags.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('flags.subtitle')}</p>
      </div>

      <div className={clsx(styles.tabs)}>
        <Button
          size="sm"
          variant={section === 'flags' ? 'default' : 'ghost'}
          onClick={() => setSection('flags')}
        >
          {t('flags.tabFlags')}
        </Button>
        <Button
          size="sm"
          variant={section === 'api' ? 'default' : 'ghost'}
          onClick={() => setSection('api')}
        >
          {t('flags.tabApi')}
        </Button>
      </div>

      {section === 'flags' ? (
        <Card>
          <CardHeader className={clsx(styles.cardHeader)}>
            <div>
              <CardTitle>{t('flags.listTitle')}</CardTitle>
              <CardDescription>{t('flags.listHint')}</CardDescription>
            </div>
            <Button size="sm" onClick={openCreate}>
              {t('flags.create')}
            </Button>
          </CardHeader>
          <CardContent>
            <div className={clsx(styles.filters)}>
              <Input
                placeholder={t('flags.search')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
                <option value="">{t('flags.allTypes')}</option>
                {TYPES.map((tp) => (
                  <option key={tp} value={tp}>
                    {tp}
                  </option>
                ))}
              </Select>
            </div>
            {list.isLoading ? (
              <TableSkeleton columns={7} rows={5} />
            ) : (list.data ?? []).length === 0 ? (
              <EmptyState title={t('flags.empty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('flags.name')}</TableHead>
                    <TableHead>{t('flags.key')}</TableHead>
                    <TableHead>{t('flags.type')}</TableHead>
                    <TableHead>{t('flags.value')}</TableHead>
                    <TableHead>{t('flags.abShort')}</TableHead>
                    <TableHead>{t('flags.enabled')}</TableHead>
                    <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(list.data ?? []).map((flag) => (
                    <TableRow key={flag.id}>
                      <TableCell>{flag.name}</TableCell>
                      <TableCell className={clsx(styles.mono)}>{flag.key}</TableCell>
                      <TableCell>{flag.type}</TableCell>
                      <TableCell className={clsx(styles.mono)}>{previewValue(flag)}</TableCell>
                      <TableCell className={clsx(styles.mono)}>
                        {flag.abTest && flag.type === 'boolean' ? `${flag.rolloutPercent}%` : '—'}
                      </TableCell>
                      <TableCell>
                        <Switch
                          checked={flag.enabled}
                          onCheckedChange={() => toggleEnabled.mutate(flag)}
                        />
                      </TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        <div className={clsx(styles.rowActions)}>
                          <Button size="sm" variant="outline" onClick={() => openEdit(flag)}>
                            {t('common.edit')}
                          </Button>
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => {
                              if (confirm(t('flags.deleteConfirm', { key: flag.key }))) {
                                remove.mutate(flag.id)
                              }
                            }}
                          >
                            {t('common.delete')}
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{t('flags.apiTitle')}</CardTitle>
            <CardDescription>{t('flags.apiHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            <Form className={clsx(styles.apiForm)} onSubmit={() => saveSettings.mutate()}>
              <div className={clsx(styles.switchRow)}>
                <Switch
                  checked={apiEnabled}
                  onCheckedChange={(v) => setApiDraft((prev) => ({ ...prev, enabled: v }))}
                  id="flags-api-enabled"
                />
                <Label htmlFor="flags-api-enabled">{t('flags.apiEnabled')}</Label>
              </div>
              <div className={clsx(styles.field)}>
                <Label htmlFor="flags-api-path">{t('flags.apiPath')}</Label>
                <Input
                  id="flags-api-path"
                  value={apiPath}
                  aria-invalid={hasFieldError(fieldErrors, 'path') || undefined}
                  onChange={(e) => {
                    setApiDraft((prev) => ({ ...prev, path: e.target.value }))
                    setFieldErrors((prev) => clearFieldError(prev, 'path'))
                  }}
                />
                <FieldError messages={fieldErrors.path} />
              </div>
              <div className={clsx(styles.switchRow)}>
                <Switch
                  checked={requireToken}
                  onCheckedChange={(v) => setApiDraft((prev) => ({ ...prev, requireToken: v }))}
                  id="flags-require-token"
                />
                <Label htmlFor="flags-require-token">{t('flags.requireToken')}</Label>
              </div>
              <CodeBlock code={curlExample} language="bash" label={t('flags.curlExample')} />
              <Button
                type="submit"
                size="sm"
                className={clsx(styles.saveBtn)}
                disabled={saveSettings.isPending}
              >
                {saveSettings.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </Form>
          </CardContent>
        </Card>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className={clsx(styles.dialog)}>
          <DialogHeader>
            <DialogTitle>{editing ? t('flags.editTitle') : t('flags.createTitle')}</DialogTitle>
            <DialogDescription>{t('flags.formHint')}</DialogDescription>
          </DialogHeader>
          <Form className={clsx(styles.form)} onSubmit={() => save.mutate()}>
            <div className={clsx(styles.switchRow)}>
              <Switch checked={enabled} onCheckedChange={setEnabled} id="flag-enabled" />
              <Label htmlFor="flag-enabled">{t('flags.enabled')}</Label>
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.name')}</Label>
              <Input
                value={name}
                aria-invalid={hasFieldError(fieldErrors, 'name') || undefined}
                onChange={(e) => {
                  setName(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'name'))
                }}
              />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.key')}</Label>
              <Input
                value={key}
                disabled={!!editing}
                aria-invalid={hasFieldError(fieldErrors, 'key') || undefined}
                onChange={(e) => {
                  setKey(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'key'))
                }}
                placeholder="enabledNews"
              />
              <FieldError messages={fieldErrors.key} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.type')}</Label>
              <Select
                value={type}
                disabled={!!editing}
                onChange={(e) => {
                  const next = e.target.value as FlagType
                  setType(next)
                  if (next !== 'boolean') setAbTest(false)
                }}
              >
                {TYPES.map((tp) => (
                  <option key={tp} value={tp}>
                    {tp}
                  </option>
                ))}
              </Select>
            </div>
            <div className={clsx(styles.abSection)}>
              <div className={clsx(styles.switchRow)}>
                <Switch
                  checked={abTest}
                  onCheckedChange={setAbTest}
                  id="flag-ab"
                  disabled={type !== 'boolean'}
                />
                <div className={clsx(styles.abLabelBlock)}>
                  <Label htmlFor="flag-ab">{t('flags.abTest')}</Label>
                  <Link
                    to="/docs/feature-flags"
                    target="_blank"
                    rel="noopener noreferrer"
                    className={clsx(styles.abDocsLink)}
                  >
                    {t('flags.abDocs')}
                    <ExternalLink className={clsx(styles.abDocsIcon)} aria-hidden />
                  </Link>
                </div>
              </div>
              {type !== 'boolean' ? (
                <p className={clsx(styles.hint)}>{t('flags.abBooleanOnly')}</p>
              ) : null}
              {type === 'boolean' && abTest ? (
                <div className={clsx(styles.field)}>
                  <Label htmlFor="flag-rollout">{t('flags.rolloutPercent')}</Label>
                  <Input
                    id="flag-rollout"
                    type="number"
                    min={0}
                    max={100}
                    value={rolloutPercent}
                    onChange={(e) => setRolloutPercent(e.target.value)}
                  />
                  <p className={clsx(styles.hint)}>{t('flags.abHint')}</p>
                </div>
              ) : null}
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.description')}</Label>
              <Input value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.value')}</Label>
              {type === 'boolean' ? (
                <>
                  <Switch checked={boolValue} onCheckedChange={setBoolValue} disabled={abTest} />
                  {abTest ? <p className={clsx(styles.hint)}>{t('flags.abValueIgnored')}</p> : null}
                </>
              ) : null}
              {type === 'integer' ? (
                <Input
                  type="number"
                  value={intValue}
                  onChange={(e) => setIntValue(e.target.value)}
                />
              ) : null}
              {type === 'string' ? (
                <Input value={stringValue} onChange={(e) => setStringValue(e.target.value)} />
              ) : null}
              {type === 'object' ? (
                <>
                  <CodeBlock
                    code={objectValue}
                    editable
                    language="js"
                    rows={8}
                    onChange={(v) => {
                      setObjectValue(v)
                      try {
                        JSON.parse(v)
                        setJsonError(null)
                      } catch {
                        setJsonError(t('flags.invalidJson'))
                      }
                    }}
                  />
                  {jsonError ? <p className={clsx(styles.error)}>{jsonError}</p> : null}
                </>
              ) : null}
            </div>
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setDialogOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={save.isPending || !!jsonError}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </Form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
