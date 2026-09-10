import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useSearchParams } from 'react-router-dom'
import { CodeBlock } from '@/components/CodeBlock'
import { EmptyState } from '@/components/EmptyState'
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
}

interface ApiSettings {
  enabled: boolean
  path: string
  requireToken: boolean
}

const TYPES: FlagType[] = ['boolean', 'integer', 'string', 'object']

function previewValue(flag: FeatureFlag): string {
  if (flag.type === 'object') return JSON.stringify(flag.value)
  return String(flag.value)
}

export function FeatureFlagsPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const section = (params.get('section') === 'api' ? 'api' : 'flags') as Section
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<FeatureFlag | null>(null)
  const [error, setError] = useState<string | null>(null)

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

  const [apiEnabled, setApiEnabled] = useState(true)
  const [apiPath, setApiPath] = useState('/api/features')
  const [requireToken, setRequireToken] = useState(false)

  useEffect(() => {
    if (settings.data) {
      setApiEnabled(settings.data.enabled)
      setApiPath(settings.data.path)
      setRequireToken(settings.data.requireToken)
    }
  }, [settings.data])

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
    setError(null)
    setDialogOpen(true)
  }

  function openEdit(flag: FeatureFlag) {
    setEditing(flag)
    setName(flag.name)
    setKey(flag.key)
    setType(flag.type)
    setDescription(flag.description ?? '')
    setEnabled(flag.enabled)
    setJsonError(null)
    setError(null)
    if (flag.type === 'boolean') setBoolValue(Boolean(flag.value))
    if (flag.type === 'integer') setIntValue(String(flag.value))
    if (flag.type === 'string') setStringValue(String(flag.value ?? ''))
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
      setDialogOpen(false)
      void queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
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
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['feature-flags-settings'] })
      setApiEnabled(data.enabled)
      setApiPath(data.path)
      setRequireToken(data.requireToken)
      setError(null)
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const setSection = (next: Section) => {
    const p = new URLSearchParams(params)
    if (next === 'flags') p.delete('section')
    else p.set('section', next)
    setParams(p, { replace: true })
  }

  const curlExample = `curl -s "${apiPath}?keys=enabledNews,intMaxAmount"`

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

      {error ? <p className={clsx(styles.error)}>{error}</p> : null}

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
              <TableSkeleton columns={6} rows={5} />
            ) : (list.data ?? []).length === 0 ? (
              <EmptyState title={t('flags.empty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('common.name')}</TableHead>
                    <TableHead>{t('flags.key')}</TableHead>
                    <TableHead>{t('flags.type')}</TableHead>
                    <TableHead>{t('flags.value')}</TableHead>
                    <TableHead>{t('flags.enabled')}</TableHead>
                    <TableHead>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(list.data ?? []).map((flag) => (
                    <TableRow key={flag.id}>
                      <TableCell>{flag.name}</TableCell>
                      <TableCell className={clsx(styles.mono)}>{flag.key}</TableCell>
                      <TableCell>{flag.type}</TableCell>
                      <TableCell className={clsx(styles.mono)}>{previewValue(flag)}</TableCell>
                      <TableCell>
                        <Switch
                          checked={flag.enabled}
                          onCheckedChange={() => toggleEnabled.mutate(flag)}
                        />
                      </TableCell>
                      <TableCell>
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
          <CardContent className={clsx(styles.apiForm)}>
            <div className={clsx(styles.switchRow)}>
              <Switch checked={apiEnabled} onCheckedChange={setApiEnabled} id="flags-api-enabled" />
              <Label htmlFor="flags-api-enabled">{t('flags.apiEnabled')}</Label>
            </div>
            <div className={clsx(styles.field)}>
              <Label htmlFor="flags-api-path">{t('flags.apiPath')}</Label>
              <Input
                id="flags-api-path"
                value={apiPath}
                onChange={(e) => setApiPath(e.target.value)}
              />
            </div>
            <div className={clsx(styles.switchRow)}>
              <Switch
                checked={requireToken}
                onCheckedChange={setRequireToken}
                id="flags-require-token"
              />
              <Label htmlFor="flags-require-token">{t('flags.requireToken')}</Label>
            </div>
            <CodeBlock code={curlExample} language="bash" label={t('flags.curlExample')} />
            <Button
              size="sm"
              disabled={saveSettings.isPending}
              onClick={() => saveSettings.mutate()}
            >
              {saveSettings.isPending ? t('common.saving') : t('common.save')}
            </Button>
          </CardContent>
        </Card>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className={clsx(styles.dialog)}>
          <DialogHeader>
            <DialogTitle>{editing ? t('flags.editTitle') : t('flags.createTitle')}</DialogTitle>
            <DialogDescription>{t('flags.formHint')}</DialogDescription>
          </DialogHeader>
          <div className={clsx(styles.form)}>
            <div className={clsx(styles.field)}>
              <Label>{t('common.name')}</Label>
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.key')}</Label>
              <Input
                value={key}
                disabled={!!editing}
                onChange={(e) => setKey(e.target.value)}
                placeholder="enabledNews"
              />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.type')}</Label>
              <Select
                value={type}
                disabled={!!editing}
                onChange={(e) => setType(e.target.value as FlagType)}
              >
                {TYPES.map((tp) => (
                  <option key={tp} value={tp}>
                    {tp}
                  </option>
                ))}
              </Select>
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.description')}</Label>
              <Input value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.value')}</Label>
              {type === 'boolean' ? (
                <Switch checked={boolValue} onCheckedChange={setBoolValue} />
              ) : null}
              {type === 'integer' ? (
                <Input type="number" value={intValue} onChange={(e) => setIntValue(e.target.value)} />
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
            <div className={clsx(styles.switchRow)}>
              <Switch checked={enabled} onCheckedChange={setEnabled} id="flag-enabled" />
              <Label htmlFor="flag-enabled">{t('flags.enabled')}</Label>
            </div>
            {error ? <p className={clsx(styles.error)}>{error}</p> : null}
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setDialogOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button disabled={save.isPending || !!jsonError} onClick={() => save.mutate()}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
