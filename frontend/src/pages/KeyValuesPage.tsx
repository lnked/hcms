import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
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
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './KeyValuesPage.module.css'

type Section = 'entries' | 'api'

interface Author {
  id: number
  name: string
  email: string
}

interface KeyValueEntry {
  id: number
  key: string
  value: unknown
  createdBy: Author | null
  updatedBy: Author | null
  createdAt: string
  updatedAt: string
}

interface ApiSettings {
  enabled: boolean
  path: string
  requireToken: boolean
}

function previewValue(value: unknown): string {
  if (typeof value === 'string') return value
  return JSON.stringify(value)
}

function authorLabel(author: Author | null): string {
  if (!author) return '—'
  return author.name || author.email || `#${author.id}`
}

function formatDt(raw: string): string {
  const d = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleString()
}

/** Parse editor text: valid JSON → that value; otherwise plain string. */
function parseEditorValue(raw: string): unknown {
  const trimmed = raw.trim()
  if (trimmed === '') return ''
  try {
    return JSON.parse(trimmed) as unknown
  } catch {
    return raw
  }
}

function valueToEditor(value: unknown): string {
  if (typeof value === 'string') return value
  return JSON.stringify(value, null, 2)
}

export function KeyValuesPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const section = params.get('section') === 'api' ? 'api' : 'entries'
  const [search, setSearch] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<KeyValueEntry | null>(null)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const [key, setKey] = useState('')
  const [valueText, setValueText] = useState('')

  const list = useQuery({
    queryKey: ['key-values', search],
    queryFn: () => {
      const q = new URLSearchParams()
      if (search.trim()) q.set('search', search.trim())
      const qs = q.toString()
      return api<KeyValueEntry[]>(`/admin/api/key-values${qs ? `?${qs}` : ''}`)
    },
  })

  const settings = useQuery({
    queryKey: ['key-values-settings'],
    queryFn: () => api<ApiSettings>('/admin/api/key-values/settings'),
  })

  const [apiDraft, setApiDraft] = useState<Partial<ApiSettings> | null>(null)
  const apiServer = settings.data ?? { enabled: true, path: '/api/kv', requireToken: false }
  const apiEnabled = apiDraft?.enabled ?? apiServer.enabled
  const apiPath = apiDraft?.path ?? apiServer.path
  const requireToken = apiDraft?.requireToken ?? apiServer.requireToken

  function openCreate() {
    setEditing(null)
    setKey('')
    setValueText('')
    setFieldErrors({})
    setDialogOpen(true)
  }

  function openEdit(entry: KeyValueEntry) {
    setEditing(entry)
    setKey(entry.key)
    setValueText(valueToEditor(entry.value))
    setFieldErrors({})
    setDialogOpen(true)
  }

  const save = useMutation({
    mutationFn: async () => {
      const value = parseEditorValue(valueText)
      const body: Record<string, unknown> = { value }
      if (!editing) body.key = key
      if (editing) {
        return api<KeyValueEntry>(`/admin/api/key-values/${editing.id}`, {
          method: 'PATCH',
          body: JSON.stringify(body),
        })
      }
      return api<KeyValueEntry>('/admin/api/key-values', {
        method: 'POST',
        body: JSON.stringify(body),
      })
    },
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setFieldErrors({})
      setDialogOpen(false)
      void queryClient.invalidateQueries({ queryKey: ['key-values'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/key-values/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['key-values'] }),
  })

  const saveSettings = useMutation({
    mutationFn: () =>
      api<ApiSettings>('/admin/api/key-values/settings', {
        method: 'PUT',
        body: JSON.stringify({ enabled: apiEnabled, path: apiPath, requireToken }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setApiDraft(null)
      void queryClient.invalidateQueries({ queryKey: ['key-values-settings'] })
      setFieldErrors({})
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const setSection = (next: Section) => {
    const p = new URLSearchParams(params)
    if (next === 'entries') p.delete('section')
    else p.set('section', next)
    setParams(p, { replace: true })
  }

  const curlExample = `curl -s "${apiPath}?keys=siteName,theme"`

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <h1 className={clsx(styles.title)}>{t('kv.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('kv.subtitle')}</p>
      </div>

      <div className={clsx(styles.tabs)}>
        <Button
          size="sm"
          variant={section === 'entries' ? 'default' : 'ghost'}
          onClick={() => setSection('entries')}
        >
          {t('kv.tabEntries')}
        </Button>
        <Button
          size="sm"
          variant={section === 'api' ? 'default' : 'ghost'}
          onClick={() => setSection('api')}
        >
          {t('kv.tabApi')}
        </Button>
      </div>

      {section === 'entries' ? (
        <Card>
          <CardHeader className={clsx(styles.cardHeader)}>
            <div>
              <CardTitle>{t('kv.listTitle')}</CardTitle>
              <CardDescription>{t('kv.listHint')}</CardDescription>
            </div>
            <Button size="sm" onClick={openCreate}>
              {t('kv.create')}
            </Button>
          </CardHeader>
          <CardContent>
            <div className={clsx(styles.filters)}>
              <Input
                placeholder={t('kv.search')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            {list.isLoading ? (
              <TableSkeleton columns={6} rows={5} />
            ) : (list.data ?? []).length === 0 ? (
              <EmptyState title={t('kv.empty')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('kv.key')}</TableHead>
                    <TableHead>{t('kv.value')}</TableHead>
                    <TableHead>{t('kv.author')}</TableHead>
                    <TableHead>{t('kv.createdAt')}</TableHead>
                    <TableHead>{t('kv.updatedAt')}</TableHead>
                    <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(list.data ?? []).map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className={clsx(styles.mono)}>{entry.key}</TableCell>
                      <TableCell className={clsx(styles.mono, styles.valueCell)}>
                        {previewValue(entry.value)}
                      </TableCell>
                      <TableCell>{authorLabel(entry.updatedBy ?? entry.createdBy)}</TableCell>
                      <TableCell className={clsx(styles.muted)}>
                        {formatDt(entry.createdAt)}
                      </TableCell>
                      <TableCell className={clsx(styles.muted)}>
                        {formatDt(entry.updatedAt)}
                      </TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        <div className={clsx(styles.rowActions)}>
                          <Button size="sm" variant="outline" onClick={() => openEdit(entry)}>
                            {t('common.edit')}
                          </Button>
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => {
                              if (confirm(t('kv.deleteConfirm', { key: entry.key }))) {
                                remove.mutate(entry.id)
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
            <CardTitle>{t('kv.apiTitle')}</CardTitle>
            <CardDescription>{t('kv.apiHint')}</CardDescription>
          </CardHeader>
          <CardContent>
            <Form className={clsx(styles.apiForm)} onSubmit={() => saveSettings.mutate()}>
              <div className={clsx(styles.switchRow)}>
                <Switch
                  checked={apiEnabled}
                  onCheckedChange={(v) => setApiDraft((prev) => ({ ...prev, enabled: v }))}
                  id="kv-api-enabled"
                />
                <Label htmlFor="kv-api-enabled">{t('kv.apiEnabled')}</Label>
              </div>
              <div className={clsx(styles.field)}>
                <Label htmlFor="kv-api-path">{t('kv.apiPath')}</Label>
                <Input
                  id="kv-api-path"
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
                  id="kv-require-token"
                />
                <Label htmlFor="kv-require-token">{t('kv.requireToken')}</Label>
              </div>
              <CodeBlock code={curlExample} language="bash" label={t('kv.curlExample')} />
              <p className={clsx(styles.hint)}>{t('kv.apiResponseHint')}</p>
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
            <DialogTitle>{editing ? t('kv.editTitle') : t('kv.createTitle')}</DialogTitle>
            <DialogDescription>{t('kv.formHint')}</DialogDescription>
          </DialogHeader>
          <Form className={clsx(styles.form)} onSubmit={() => save.mutate()}>
            <div className={clsx(styles.field)}>
              <Label>{t('kv.key')}</Label>
              <Input
                value={key}
                disabled={!!editing}
                aria-invalid={hasFieldError(fieldErrors, 'key') || undefined}
                onChange={(e) => {
                  setKey(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'key'))
                }}
                placeholder="siteName"
              />
              <FieldError messages={fieldErrors.key} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('kv.value')}</Label>
              <CodeBlock
                code={valueText}
                editable
                language="js"
                rows={8}
                onChange={(v) => {
                  setValueText(v)
                  setFieldErrors((prev) => clearFieldError(prev, 'value'))
                }}
              />
              <p className={clsx(styles.hint)}>{t('kv.valueHint')}</p>
              <FieldError messages={fieldErrors.value} />
            </div>
            {editing ? (
              <div className={clsx(styles.meta)}>
                <p>
                  {t('kv.createdAt')}: {formatDt(editing.createdAt)} ·{' '}
                  {authorLabel(editing.createdBy)}
                </p>
                <p>
                  {t('kv.updatedAt')}: {formatDt(editing.updatedAt)} ·{' '}
                  {authorLabel(editing.updatedBy)}
                </p>
              </div>
            ) : null}
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setDialogOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={save.isPending}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </Form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
