import { useState } from 'react'
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
import { FieldError } from '@/components/FieldError'
import { api } from '@/lib/api'
import { apiFieldErrors, type FieldErrors } from '@/lib/formErrors'
import { showError, showSuccess } from '@/lib/toast'
import styles from './TranslatesPage.module.css'

type Section = 'keys' | 'languages' | 'api'

interface Locale {
  code: string
  label: string
  enabled: boolean
  isDefault: boolean
  sortOrder: number
}

interface Translation {
  id: number
  key: string
  description: string | null
  values: Record<string, string>
  missing: string[]
  updatedAt: string
}

interface ApiSettings {
  enabled: boolean
  path: string
  requireToken: boolean
}

export function TranslatesPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const sectionParam = params.get('section')
  const section: Section =
    sectionParam === 'languages' || sectionParam === 'api' ? sectionParam : 'keys'

  const [search, setSearch] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})

  const [keyDialog, setKeyDialog] = useState(false)
  const [editingKey, setEditingKey] = useState<Translation | null>(null)
  const [trKey, setTrKey] = useState('')
  const [trDesc, setTrDesc] = useState('')
  const [trValues, setTrValues] = useState<Record<string, string>>({})

  const [localeDialog, setLocaleDialog] = useState(false)
  const [localeCode, setLocaleCode] = useState('')
  const [localeLabel, setLocaleLabel] = useState('')

  const [apiDraft, setApiDraft] = useState<Partial<ApiSettings> | null>(null)

  const locales = useQuery({
    queryKey: ['locales'],
    queryFn: () => api<Locale[]>('/admin/api/locales'),
  })

  const translations = useQuery({
    queryKey: ['translations', search],
    queryFn: () => {
      const q = search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''
      return api<Translation[]>(`/admin/api/translations${q}`)
    },
  })

  const settings = useQuery({
    queryKey: ['translations-settings'],
    queryFn: () => api<ApiSettings>('/admin/api/translations/settings'),
  })

  const apiServer = settings.data ?? {
    enabled: true,
    path: '/api/translates',
    requireToken: false,
  }
  const apiEnabled = apiDraft?.enabled ?? apiServer.enabled
  const apiPath = apiDraft?.path ?? apiServer.path
  const requireToken = apiDraft?.requireToken ?? apiServer.requireToken

  const enabledLocales = (locales.data ?? []).filter((l) => l.enabled)

  function setSection(next: Section) {
    const p = new URLSearchParams(params)
    if (next === 'keys') p.delete('section')
    else p.set('section', next)
    setParams(p, { replace: true })
  }

  function openCreateKey() {
    setEditingKey(null)
    setTrKey('')
    setTrDesc('')
    const vals: Record<string, string> = {}
    for (const loc of enabledLocales) vals[loc.code] = ''
    setTrValues(vals)
    setFieldErrors({})
    setKeyDialog(true)
  }

  function openEditKey(row: Translation) {
    setEditingKey(row)
    setTrKey(row.key)
    setTrDesc(row.description ?? '')
    const vals: Record<string, string> = {}
    for (const loc of enabledLocales) vals[loc.code] = row.values[loc.code] ?? ''
    setTrValues(vals)
    setFieldErrors({})
    setKeyDialog(true)
  }

  const saveKey = useMutation({
    mutationFn: async () => {
      if (editingKey) {
        return api<Translation>(`/admin/api/translations/${editingKey.id}`, {
          method: 'PATCH',
          body: JSON.stringify({
            description: trDesc.trim() || null,
            values: trValues,
          }),
        })
      }
      return api<Translation>('/admin/api/translations', {
        method: 'POST',
        body: JSON.stringify({
          key: trKey,
          description: trDesc.trim() || null,
          values: trValues,
        }),
      })
    },
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setKeyDialog(false)
      void queryClient.invalidateQueries({ queryKey: ['translations'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const removeKey = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/translations/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['translations'] }),
  })

  const createLocale = useMutation({
    mutationFn: () =>
      api<Locale>('/admin/api/locales', {
        method: 'POST',
        body: JSON.stringify({ code: localeCode, label: localeLabel }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setLocaleDialog(false)
      setLocaleCode('')
      setLocaleLabel('')
      void queryClient.invalidateQueries({ queryKey: ['locales'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const patchLocale = useMutation({
    mutationFn: ({ code, ...body }: { code: string } & Record<string, unknown>) =>
      api<Locale>(`/admin/api/locales/${code}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['locales'] }),
  })

  const setDefault = useMutation({
    mutationFn: (code: string) =>
      api<Locale>(`/admin/api/locales/${code}/default`, { method: 'PUT', body: '{}' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['locales'] }),
  })

  const removeLocale = useMutation({
    mutationFn: (code: string) => api<void>(`/admin/api/locales/${code}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['locales'] })
      void queryClient.invalidateQueries({ queryKey: ['translations'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const saveSettings = useMutation({
    mutationFn: () =>
      api<ApiSettings>('/admin/api/translations/settings', {
        method: 'PUT',
        body: JSON.stringify({ enabled: apiEnabled, path: apiPath, requireToken }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setApiDraft(null)
      void queryClient.invalidateQueries({ queryKey: ['translations-settings'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  async function exportJson() {
    const data = await api<Record<string, Record<string, string>>>('/admin/api/translations/export')
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'translations.json'
    a.click()
    URL.revokeObjectURL(url)
  }

  async function importJson(file: File) {
    try {
      const text = await file.text()
      const parsed = JSON.parse(text) as unknown
      if (!parsed || typeof parsed !== 'object') throw new Error(t('translates.invalidImport'))
      await api('/admin/api/translations/import', {
        method: 'POST',
        body: JSON.stringify(parsed),
      })
      void queryClient.invalidateQueries({ queryKey: ['translations'] })
      showSuccess(t('common.saved'))
      setFieldErrors({})
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('translates.invalidImport')
      showError(msg)
    }
  }

  const curlExample = `curl -s "${apiPath}?locale=en&keys=amount.title,amount.description"`

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <h1 className={clsx(styles.title)}>{t('translates.title')}</h1>
        <p className={clsx(styles.subtitle)}>{t('translates.subtitle')}</p>
      </div>

      <div className={clsx(styles.tabs)}>
        {(['keys', 'languages', 'api'] as Section[]).map((item) => (
          <Button
            key={item}
            size="sm"
            variant={section === item ? 'default' : 'ghost'}
            onClick={() => setSection(item)}
          >
            {item === 'keys'
              ? t('translates.tabKeys')
              : item === 'languages'
                ? t('translates.tabLanguages')
                : t('translates.tabApi')}
          </Button>
        ))}
      </div>

      {section === 'keys' ? (
        <Card>
          <CardHeader className={clsx(styles.keysCardHeader)}>
            <div>
              <CardTitle>{t('translates.keysTitle')}</CardTitle>
              <CardDescription>{t('translates.keysHint')}</CardDescription>
            </div>
            <div className={clsx(styles.headerActions)}>
              <Button size="sm" onClick={openCreateKey}>
                {t('translates.createKey')}
              </Button>
              <div className={clsx(styles.headerActionsRight)}>
                <label className={clsx(styles.importLabel)}>
                  <input
                    type="file"
                    accept="application/json,.json"
                    className={clsx(styles.hiddenInput)}
                    onChange={(e) => {
                      const f = e.target.files?.[0]
                      e.target.value = ''
                      if (f) void importJson(f)
                    }}
                  />
                  <span className={clsx(styles.importBtn)}>{t('translates.import')}</span>
                </label>
                <Button size="sm" variant="outline" onClick={() => void exportJson()}>
                  {t('translates.export')}
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <Input
              className={clsx(styles.search)}
              placeholder={t('translates.search')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            {translations.isLoading ? (
              <TableSkeleton columns={4} rows={5} />
            ) : (translations.data ?? []).length === 0 ? (
              <EmptyState title={t('translates.emptyKeys')} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('translates.key')}</TableHead>
                    <TableHead>{t('translates.missing')}</TableHead>
                    <TableHead>{t('translates.updated')}</TableHead>
                    <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(translations.data ?? []).map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        <div className={clsx(styles.keyCell)}>
                          <span className={clsx(styles.mono)}>{row.key}</span>
                          {row.description ? (
                            <span className={clsx(styles.muted)}>{row.description}</span>
                          ) : null}
                        </div>
                      </TableCell>
                      <TableCell>
                        {row.missing.length > 0 ? (
                          <span className={clsx(styles.missing)}>{row.missing.join(', ')}</span>
                        ) : (
                          '—'
                        )}
                      </TableCell>
                      <TableCell>{row.updatedAt}</TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        <div className={clsx(styles.rowActions)}>
                          <Button size="sm" variant="outline" onClick={() => openEditKey(row)}>
                            {t('common.edit')}
                          </Button>
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => {
                              if (confirm(t('translates.deleteKeyConfirm', { key: row.key }))) {
                                removeKey.mutate(row.id)
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
      ) : null}

      {section === 'languages' ? (
        <Card>
          <CardHeader className={clsx(styles.cardHeader)}>
            <div>
              <CardTitle>{t('translates.languagesTitle')}</CardTitle>
              <CardDescription>{t('translates.languagesHint')}</CardDescription>
            </div>
            <Button size="sm" onClick={() => setLocaleDialog(true)}>
              {t('translates.addLocale')}
            </Button>
          </CardHeader>
          <CardContent>
            {locales.isLoading ? (
              <TableSkeleton columns={5} rows={3} />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('translates.code')}</TableHead>
                    <TableHead>{t('common.name')}</TableHead>
                    <TableHead>{t('translates.enabled')}</TableHead>
                    <TableHead>{t('translates.default')}</TableHead>
                    <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(locales.data ?? []).map((loc) => (
                    <TableRow key={loc.code}>
                      <TableCell className={clsx(styles.mono)}>{loc.code}</TableCell>
                      <TableCell>{loc.label}</TableCell>
                      <TableCell>
                        <Switch
                          checked={loc.enabled}
                          disabled={loc.isDefault}
                          onCheckedChange={(checked) =>
                            patchLocale.mutate({ code: loc.code, enabled: checked })
                          }
                        />
                      </TableCell>
                      <TableCell>
                        {loc.isDefault ? (
                          t('common.yes')
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDefault.mutate(loc.code)}
                          >
                            {t('translates.setDefault')}
                          </Button>
                        )}
                      </TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        {!loc.isDefault ? (
                          <div className={clsx(styles.rowActions)}>
                            <Button
                              size="sm"
                              variant="destructive"
                              onClick={() => {
                                if (
                                  confirm(t('translates.deleteLocaleConfirm', { code: loc.code }))
                                ) {
                                  removeLocale.mutate(loc.code)
                                }
                              }}
                            >
                              {t('common.delete')}
                            </Button>
                          </div>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      ) : null}

      {section === 'api' ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('translates.apiTitle')}</CardTitle>
            <CardDescription>{t('translates.apiHint')}</CardDescription>
          </CardHeader>
          <CardContent className={clsx(styles.apiForm)}>
            <div className={clsx(styles.switchRow)}>
              <Switch
                checked={apiEnabled}
                onCheckedChange={(v) => setApiDraft((prev) => ({ ...prev, enabled: v }))}
                id="tr-api-enabled"
              />
              <Label htmlFor="tr-api-enabled">{t('translates.apiEnabled')}</Label>
            </div>
            <div className={clsx(styles.field)}>
              <Label htmlFor="tr-api-path">{t('translates.apiPath')}</Label>
              <Input
                id="tr-api-path"
                value={apiPath}
                onChange={(e) => setApiDraft((prev) => ({ ...prev, path: e.target.value }))}
              />
              <FieldError messages={fieldErrors.path} />
            </div>
            <div className={clsx(styles.switchRow)}>
              <Switch
                checked={requireToken}
                onCheckedChange={(v) => setApiDraft((prev) => ({ ...prev, requireToken: v }))}
                id="tr-require-token"
              />
              <Label htmlFor="tr-require-token">{t('translates.requireToken')}</Label>
            </div>
            <CodeBlock code={curlExample} language="bash" label={t('translates.curlExample')} />
            <Button
              size="sm"
              className={clsx(styles.saveBtn)}
              disabled={saveSettings.isPending}
              onClick={() => saveSettings.mutate()}
            >
              {saveSettings.isPending ? t('common.saving') : t('common.save')}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <Dialog open={keyDialog} onOpenChange={setKeyDialog}>
        <DialogContent className={clsx(styles.dialog)}>
          <DialogHeader>
            <DialogTitle>
              {editingKey ? t('translates.editKey') : t('translates.createKey')}
            </DialogTitle>
            <DialogDescription>{t('translates.keyFormHint')}</DialogDescription>
          </DialogHeader>
          <div className={clsx(styles.form)}>
            <div className={clsx(styles.field)}>
              <Label>{t('translates.key')}</Label>
              <Input
                value={trKey}
                disabled={!!editingKey}
                onChange={(e) => setTrKey(e.target.value)}
                placeholder="amount.title"
              />
              <FieldError messages={fieldErrors.key} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('flags.description')}</Label>
              <Input value={trDesc} onChange={(e) => setTrDesc(e.target.value)} />
              <FieldError messages={fieldErrors.description} />
            </div>
            {enabledLocales.map((loc) => (
              <div key={loc.code} className={clsx(styles.field)}>
                <Label>
                  {loc.label} ({loc.code}){loc.isDefault ? ` · ${t('translates.default')}` : ''}
                </Label>
                <Input
                  value={trValues[loc.code] ?? ''}
                  onChange={(e) => setTrValues((prev) => ({ ...prev, [loc.code]: e.target.value }))}
                />
              </div>
            ))}
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setKeyDialog(false)}>
                {t('common.cancel')}
              </Button>
              <Button disabled={saveKey.isPending} onClick={() => saveKey.mutate()}>
                {saveKey.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={localeDialog} onOpenChange={setLocaleDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('translates.addLocale')}</DialogTitle>
          </DialogHeader>
          <div className={clsx(styles.form)}>
            <div className={clsx(styles.field)}>
              <Label>{t('translates.code')}</Label>
              <Input
                value={localeCode}
                onChange={(e) => setLocaleCode(e.target.value)}
                placeholder="de"
              />
              <FieldError messages={fieldErrors.code} />
            </div>
            <div className={clsx(styles.field)}>
              <Label>{t('common.name')}</Label>
              <Input
                value={localeLabel}
                onChange={(e) => setLocaleLabel(e.target.value)}
                placeholder="Deutsch"
              />
              <FieldError messages={fieldErrors.label} />
            </div>
            <div className={clsx(styles.actions)}>
              <Button variant="outline" onClick={() => setLocaleDialog(false)}>
                {t('common.cancel')}
              </Button>
              <Button disabled={createLocale.isPending} onClick={() => createLocale.mutate()}>
                {createLocale.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
