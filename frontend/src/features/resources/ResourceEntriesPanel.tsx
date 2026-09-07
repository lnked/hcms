import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { History, Upload } from 'lucide-react'
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
import { DataTable, type EntryRow } from '@/features/data-table/DataTable'
import { emptyValues, FormRenderer, type EntryValues } from '@/features/form-renderer/FormRenderer'
import { EntryRevisionsPanel } from '@/features/resources/EntryRevisionsPanel'
import { useI18n } from '@/i18n'
import { ApiError, api, apiPage, getToken, handleUnauthorized } from '@/lib/api'
import { showError } from '@/lib/toast'
import { cn } from '@/lib/utils'
import type { SchemaField } from '@/types/field'

const SYSTEM_EXPORT_FIELDS = ['id', 'createdAt', 'updatedAt'] as const

type ExportFormat = 'csv' | 'json'

interface ImportResult {
  created: number
  failed: number
  errors: Array<{ row: number; message: string }>
}

interface ResourceEntriesPanelProps {
  resourceId: number
  resourceSlug: string
  fields: SchemaField[]
  published: boolean
}

export function ResourceEntriesPanel({
  resourceId,
  resourceSlug,
  fields,
  published,
}: ResourceEntriesPanelProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState('id')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<EntryRow | null>(null)
  const [values, setValues] = useState<EntryValues>({})
  const [error, setError] = useState<string | null>(null)
  const [revisionsOpen, setRevisionsOpen] = useState(false)

  const [exportOpen, setExportOpen] = useState(false)
  const [exportFormat, setExportFormat] = useState<ExportFormat>('json')
  const [exportAll, setExportAll] = useState(true)
  const [exportFields, setExportFields] = useState<string[]>([])
  const [exportError, setExportError] = useState<string | null>(null)

  const [importOpen, setImportOpen] = useState(false)
  const [importFormat, setImportFormat] = useState<ExportFormat>('json')
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importDragging, setImportDragging] = useState(false)
  const [importPaste, setImportPaste] = useState('')
  const [importError, setImportError] = useState<string | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)
  const importFileInputRef = useRef<HTMLInputElement>(null)

  const schemaFieldNames = useMemo(
    () => fields.filter((f) => !(f.hidden ?? false)).map((f) => f.name),
    [fields],
  )

  const allExportFieldNames = useMemo(
    () => [...SYSTEM_EXPORT_FIELDS, ...schemaFieldNames],
    [schemaFieldNames],
  )

  const allExportFieldsSelected =
    exportAll ||
    (allExportFieldNames.length > 0 &&
      allExportFieldNames.every((name) => exportFields.includes(name)))

  const activeFilters = useMemo(() => {
    const out: Record<string, string> = {}
    for (const [key, value] of Object.entries(filters)) {
      const trimmed = value.trim()
      if (trimmed !== '') out[key] = trimmed
    }
    return out
  }, [filters])

  const queryKey = useMemo(
    () => ['resource-entries', resourceId, page, search, sort, activeFilters] as const,
    [resourceId, page, search, sort, activeFilters],
  )

  const list = useQuery({
    queryKey,
    enabled: published,
    queryFn: () => {
      const params = new URLSearchParams({
        page: String(page),
        limit: '20',
        sort,
      })
      if (search) params.set('search', search)
      for (const [field, value] of Object.entries(activeFilters)) {
        const fieldMeta = fields.find((f) => f.name === field)
        const type = fieldMeta?.type ?? 'string'
        const useContains =
          type === 'string' ||
          type === 'text' ||
          type === 'email' ||
          type === 'slug' ||
          type === 'url' ||
          type === 'uuid'
        if (useContains) params.set(`filter[${field}][contains]`, value)
        else params.set(`filter[${field}]`, value)
      }
      return apiPage<EntryRow>(`/admin/api/resources/${resourceId}/entries?${params}`)
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      if (editing) {
        return api<EntryRow>(`/admin/api/resources/${resourceId}/entries/${editing.id}`, {
          method: 'PATCH',
          body: JSON.stringify(values),
        })
      }
      return api<EntryRow>(`/admin/api/resources/${resourceId}/entries`, {
        method: 'POST',
        body: JSON.stringify(values),
      })
    },
    onSuccess: () => {
      setEditorOpen(false)
      setEditing(null)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  const remove = useMutation({
    mutationFn: (row: EntryRow) =>
      api<void>(`/admin/api/resources/${resourceId}/entries/${row.id}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
  })

  const bulkRemove = useMutation({
    mutationFn: (ids: number[]) =>
      api<{ deleted: number }>(`/admin/api/resources/${resourceId}/entries/bulk-delete`, {
        method: 'POST',
        body: JSON.stringify({ ids }),
      }),
    onSuccess: () => {
      setSelectedIds([])
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('entries.bulkDeleteFailed')
      if (!(err instanceof ApiError)) showError(message)
    },
  })

  const doExport = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams({ format: exportFormat })
      if (!exportAll && exportFields.length > 0) {
        params.set('fields', exportFields.join(','))
      }
      const path = `/admin/api/resources/${resourceId}/entries/export?${params}`
      const headers = new Headers({ Accept: '*/*' })
      const token = getToken()
      if (token) headers.set('Authorization', `Bearer ${token}`)
      const response = await fetch(path, { headers })
      if (!response.ok) {
        if (response.status === 401) handleUnauthorized(path)
        let message = t('entries.exportFailed')
        try {
          const payload = (await response.json()) as { error?: { message?: string } }
          if (payload.error?.message) message = payload.error.message
        } catch {
          /* non-json error body */
        }
        throw new ApiError(response.status, 'ERROR', message)
      }
      const blob = await response.blob()
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const match = /filename="([^"]+)"/.exec(disposition)
      const filename = match?.[1] ?? `${resourceSlug}-entries.${exportFormat}`
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename
      anchor.click()
      URL.revokeObjectURL(url)
    },
    onSuccess: () => {
      setExportOpen(false)
      setExportError(null)
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('entries.exportFailed')
      setExportError(message)
      showError(message)
    },
  })

  const doImport = useMutation({
    mutationFn: async (): Promise<ImportResult> => {
      let content = importPaste.trim()
      if (!content && importFile) {
        content = await importFile.text()
      }
      if (!content) {
        throw new Error(t('entries.importEmpty'))
      }
      return api<ImportResult>(`/admin/api/resources/${resourceId}/entries/import`, {
        method: 'POST',
        body: JSON.stringify({ format: importFormat, content }),
      })
    },
    onSuccess: (result) => {
      setImportResult(result)
      setImportError(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('entries.importFailed')
      setImportError(message)
      // api() already toasts ApiError; toast local validation too
      if (!(err instanceof ApiError)) showError(message)
    },
  })

  function openCreate() {
    setEditing(null)
    setValues(emptyValues(fields))
    setError(null)
    setEditorOpen(true)
  }

  function openEdit(row: EntryRow) {
    const next = emptyValues(fields)
    for (const field of fields) {
      if (field.name in row) next[field.name] = row[field.name]
    }
    setEditing(row)
    setValues(next)
    setError(null)
    setEditorOpen(true)
  }

  function openExport() {
    setExportFormat('json')
    setExportAll(true)
    setExportFields(allExportFieldNames)
    setExportError(null)
    setExportOpen(true)
  }

  function openImport() {
    setImportFormat('json')
    setImportFile(null)
    setImportDragging(false)
    setImportPaste('')
    setImportError(null)
    setImportResult(null)
    setImportOpen(true)
  }

  function assignImportFile(file: File | null) {
    setImportFile(file)
    setImportResult(null)
    setImportError(null)
  }

  function toggleExportField(name: string) {
    const base = exportAll ? allExportFieldNames : exportFields
    const next = base.includes(name) ? base.filter((f) => f !== name) : [...base, name]
    const selectedAll =
      allExportFieldNames.length > 0 && allExportFieldNames.every((field) => next.includes(field))
    setExportAll(selectedAll)
    setExportFields(next)
  }

  function toggleAllExportFields() {
    if (allExportFieldsSelected) {
      setExportAll(false)
      setExportFields([])
      return
    }
    setExportAll(true)
    setExportFields(allExportFieldNames)
  }

  if (!published) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('resources.data')}</CardTitle>
          <CardDescription>{t('entries.publishFirst')}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  const meta = list.data?.meta
  const rows = list.data?.data ?? []

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
        <div>
          <CardTitle>{t('resources.data')}</CardTitle>
          <CardDescription>{t('entries.hint')}</CardDescription>
        </div>
        <div className="flex flex-wrap gap-2">
          {selectedIds.length > 0 ? (
            <Button
              variant="destructive"
              disabled={bulkRemove.isPending}
              onClick={() => {
                if (confirm(t('entries.bulkDeleteConfirm', { count: selectedIds.length }))) {
                  bulkRemove.mutate(selectedIds)
                }
              }}
            >
              {bulkRemove.isPending
                ? t('entries.bulkDeleting')
                : t('entries.bulkDelete', { count: selectedIds.length })}
            </Button>
          ) : null}
          <Button variant="outline" onClick={openImport}>
            {t('entries.import')}
          </Button>
          <Button variant="outline" onClick={openExport}>
            {t('entries.export')}
          </Button>
          <Button onClick={openCreate}>{t('entries.new')}</Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setPage(1)
            setSearch(searchInput.trim())
          }}
        >
          <Input
            placeholder={t('entries.searchPlaceholder')}
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            className="max-w-xs"
          />
          <Button type="submit" variant="outline">
            {t('common.search')}
          </Button>
        </form>

        {list.isLoading ? (
          <TableSkeleton columns={Math.max(3, fields.length + 2)} rows={8} />
        ) : list.isError ? (
          <p className="text-sm text-destructive">
            {list.error instanceof Error ? list.error.message : t('entries.loadFailed')}
          </p>
        ) : (
          <DataTable
            fields={fields}
            rows={rows}
            sort={sort}
            onSort={(next) => {
              setSort(next)
              setPage(1)
            }}
            filters={filters}
            onFilterChange={(field, value) => {
              setFilters((prev) => ({ ...prev, [field]: value }))
              setPage(1)
              setSelectedIds([])
            }}
            selectedIds={selectedIds}
            onSelectionChange={setSelectedIds}
            onEdit={openEdit}
            onDelete={(row) => {
              if (confirm(t('entries.deleteConfirm', { id: row.id }))) remove.mutate(row)
            }}
          />
        )}

        {meta ? (
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              {t('common.pageOfTotal', {
                page: meta.page,
                totalPages: meta.totalPages,
                total: meta.total,
              })}
            </span>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                disabled={page <= 1}
                onClick={() => {
                  setPage((p) => Math.max(1, p - 1))
                  setSelectedIds([])
                }}
              >
                {t('common.prev')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={page >= meta.totalPages}
                onClick={() => {
                  setPage((p) => p + 1)
                  setSelectedIds([])
                }}
              >
                {t('common.next')}
              </Button>
            </div>
          </div>
        ) : null}
      </CardContent>

      <Dialog open={editorOpen} onOpenChange={setEditorOpen}>
        <DialogContent>
          <DialogHeader className="pr-6">
            <DialogTitle>
              {editing ? t('entries.edit', { id: editing.id }) : t('entries.new')}
            </DialogTitle>
            <DialogDescription>{t('entries.dialogHint')}</DialogDescription>
          </DialogHeader>
          {editing ? (
            <div className="flex justify-end">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setRevisionsOpen(true)}
              >
                <History className="mr-1 h-4 w-4" />
                {t('entries.history')}
              </Button>
            </div>
          ) : null}
          <FormRenderer
            key={editing?.id ?? 'new'}
            fields={fields}
            values={values}
            onChange={setValues}
            disabled={save.isPending}
            entryId={editing?.id ?? null}
          />
          {error ? <p className="mt-3 text-sm text-destructive">{error}</p> : null}
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="outline" onClick={() => setEditorOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? t('common.saving') : t('common.save')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={exportOpen} onOpenChange={setExportOpen}>
        <DialogContent>
          <DialogHeader className="pr-6">
            <DialogTitle>{t('entries.exportTitle')}</DialogTitle>
            <DialogDescription>{t('entries.exportHint')}</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="export-format">{t('entries.format')}</Label>
              <select
                id="export-format"
                className={cn(
                  'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm',
                )}
                value={exportFormat}
                onChange={(e) => setExportFormat(e.target.value as ExportFormat)}
              >
                <option value="json">{t('entries.formatJson')}</option>
                <option value="csv">{t('entries.formatCsv')}</option>
              </select>
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between gap-2">
                <Label>{t('entries.fields')}</Label>
                <Button
                  type="button"
                  size="sm"
                  variant={allExportFieldsSelected ? 'secondary' : 'ghost'}
                  aria-pressed={allExportFieldsSelected}
                  onClick={toggleAllExportFields}
                >
                  {t('entries.selectAllFields')}
                </Button>
              </div>
              <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border p-3">
                <p className="text-xs text-muted-foreground">{t('entries.systemFields')}</p>
                {SYSTEM_EXPORT_FIELDS.map((name) => (
                  <label key={name} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={exportAll || exportFields.includes(name)}
                      onChange={() => toggleExportField(name)}
                    />
                    <span>{name}</span>
                  </label>
                ))}
                {schemaFieldNames.length > 0 ? (
                  <>
                    <p className="pt-2 text-xs text-muted-foreground">{t('entries.fields')}</p>
                    {schemaFieldNames.map((name) => (
                      <label key={name} className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={exportAll || exportFields.includes(name)}
                          onChange={() => toggleExportField(name)}
                        />
                        <span>{name}</span>
                      </label>
                    ))}
                  </>
                ) : null}
              </div>
            </div>
            {exportError ? <p className="text-sm text-destructive">{exportError}</p> : null}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setExportOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                disabled={doExport.isPending || (!exportAll && exportFields.length === 0)}
                onClick={() => doExport.mutate()}
              >
                {doExport.isPending ? t('entries.exporting') : t('entries.download')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={importOpen} onOpenChange={setImportOpen}>
        <DialogContent>
          <DialogHeader className="pr-6">
            <DialogTitle>{t('entries.importTitle')}</DialogTitle>
            <DialogDescription>{t('entries.importHint')}</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="import-format">{t('entries.format')}</Label>
              <select
                id="import-format"
                className={cn(
                  'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm',
                )}
                value={importFormat}
                onChange={(e) => setImportFormat(e.target.value as ExportFormat)}
              >
                <option value="json">{t('entries.formatJson')}</option>
                <option value="csv">{t('entries.formatCsv')}</option>
              </select>
            </div>
            <div className="space-y-2">
              <Label>{t('entries.importFile')}</Label>
              <input
                ref={importFileInputRef}
                id="import-file"
                type="file"
                className="hidden"
                accept={importFormat === 'csv' ? '.csv,text/csv' : '.json,application/json'}
                onChange={(e) => {
                  assignImportFile(e.target.files?.[0] ?? null)
                  e.target.value = ''
                }}
              />
              <div
                role="button"
                tabIndex={0}
                aria-label={t('entries.importDropzone')}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    importFileInputRef.current?.click()
                  }
                }}
                onClick={() => importFileInputRef.current?.click()}
                onDragEnter={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  setImportDragging(true)
                }}
                onDragOver={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  setImportDragging(true)
                }}
                onDragLeave={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  const next = e.relatedTarget as Node | null
                  if (next && e.currentTarget.contains(next)) return
                  setImportDragging(false)
                }}
                onDrop={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  setImportDragging(false)
                  assignImportFile(e.dataTransfer.files?.[0] ?? null)
                }}
                className={cn(
                  'flex cursor-pointer items-center gap-3 rounded-lg border border-dashed px-4 py-3 transition-colors',
                  importDragging
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/50 hover:bg-muted/40',
                )}
              >
                <Upload className="h-5 w-5 shrink-0 text-muted-foreground" />
                <div className="min-w-0 space-y-0.5">
                  <p className="truncate text-sm font-medium">
                    {importFile ? importFile.name : t('entries.importDropzone')}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {importFile
                      ? t('entries.importDropzoneChange')
                      : t('entries.importDropzoneHint')}
                  </p>
                </div>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="import-paste">{t('entries.importPaste')}</Label>
              <textarea
                id="import-paste"
                className={cn(
                  'flex min-h-[120px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm',
                )}
                placeholder={t('entries.importPastePlaceholder')}
                value={importPaste}
                onChange={(e) => {
                  setImportPaste(e.target.value)
                  setImportResult(null)
                }}
              />
            </div>
            {importResult ? (
              <div className="space-y-2 text-sm">
                <p>
                  {t('entries.importResult', {
                    created: importResult.created,
                    failed: importResult.failed,
                  })}
                </p>
                {importResult.errors.length > 0 ? (
                  <ul className="max-h-32 space-y-1 overflow-y-auto text-destructive">
                    {importResult.errors.slice(0, 20).map((err) => (
                      <li key={`${err.row}-${err.message}`}>
                        {t('entries.importErrorRow', { row: err.row, message: err.message })}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : null}
            {importError ? <p className="text-sm text-destructive">{importError}</p> : null}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setImportOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button disabled={doImport.isPending} onClick={() => doImport.mutate()}>
                {doImport.isPending ? t('entries.importing') : t('entries.importSubmit')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {editing ? (
        <EntryRevisionsPanel
          resourceId={resourceId}
          entryId={editing.id}
          open={revisionsOpen}
          onOpenChange={setRevisionsOpen}
        />
      ) : null}
    </Card>
  )
}
