import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router-dom'
import { Columns3, History, Link2, Upload } from 'lucide-react'
import { clsx } from 'clsx'
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
import { Textarea } from '@/components/ui/textarea'
import { resolveColumns } from '@/features/data-table/columns'
import { ColumnsDialog } from '@/features/data-table/ColumnsDialog'
import { DataTable, type EntryRow } from '@/features/data-table/DataTable'
import { useRelationLabels } from '@/features/data-table/useRelationLabels'
import { emptyValues, FormRenderer, type EntryValues } from '@/features/form-renderer/FormRenderer'
import { apiFieldErrors, type FieldErrors } from '@/lib/formErrors'
import { EntryRevisionsPanel } from '@/features/resources/EntryRevisionsPanel'
import { useResourceEntriesList } from '@/features/resources/useResourceEntriesList'
import { useI18n } from '@/i18n'
import { ApiError, api, getToken, handleUnauthorized } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { showError, showSuccess } from '@/lib/toast'
import type { SchemaField } from '@/types/field'
import type { Resource, ResourceListColumn } from '@/types/resource'
import styles from './ResourceEntriesPanel.module.css'

const SYSTEM_EXPORT_FIELDS = ['id', 'createdAt', 'updatedAt'] as const

type ExportFormat = 'csv' | 'json'

interface ImportResult {
  created: number
  failed: number
  errors: Array<{ row: number; message: string }>
}

/** `null` closes the editor, `'new'` opens the create card, a numeric id opens that entry. */
type EntryParam = string | 'new' | null

/** Unsaved input for one entry card, tied to the URL segment that opened it. */
interface EntryDraft {
  key: string
  values: EntryValues
  fieldErrors: FieldErrors
}

interface ResourceEntriesPanelProps {
  resourceId: number
  resourceSlug: string
  fields: SchemaField[]
  published: boolean
  /** Saved table layout from resource settings; empty means schema defaults. */
  listColumns?: ResourceListColumn[]
  /** Entry segment from the URL: `12`, `new` or `null`. */
  entryParam: EntryParam
  /** Builds the router path for a given entry segment. */
  entryPath: (entry: EntryParam) => string
}

export function ResourceEntriesPanel({
  resourceId,
  resourceSlug,
  fields,
  published,
  listColumns,
  entryParam,
  entryPath,
}: ResourceEntriesPanelProps) {
  const { t } = useI18n()
  const navigate = useNavigate()
  const { key: locationKey } = useLocation()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState('id')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [draft, setDraft] = useState<EntryDraft | null>(null)
  const [revisionsOpen, setRevisionsOpen] = useState(false)
  const [columnsOpen, setColumnsOpen] = useState(false)

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

  const { list } = useResourceEntriesList({
    resourceId,
    published,
    page,
    search,
    sort,
    filters,
    fields,
  })

  const rows = list.data?.data ?? []
  const visibleFields = useMemo(
    () => resolveColumns(fields, listColumns).map((column) => column.field),
    [fields, listColumns],
  )
  const relations = useRelationLabels(resourceId, visibleFields, rows)

  const creating = entryParam === 'new'
  const editingId = entryParam !== null && /^\d+$/.test(entryParam) ? Number(entryParam) : null
  const editorOpen = creating || editingId !== null

  // Rows carry the full record (list and show share the same serializer), so opening
  // an entry from the table needs no extra request; a direct link still fetches it.
  const listedEntry =
    editingId === null ? undefined : list.data?.data.find((row) => row.id === editingId)

  const entryQuery = useQuery({
    queryKey: ['resource-entry', resourceId, editingId],
    queryFn: () => api<EntryRow>(`/admin/api/resources/${resourceId}/entries/${editingId}`),
    enabled: published && editingId !== null,
    initialData: listedEntry,
    initialDataUpdatedAt: listedEntry ? list.dataUpdatedAt : undefined,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  })

  const editing = editingId === null ? null : (entryQuery.data ?? null)

  const loadedValues = useMemo(() => {
    const next = emptyValues(fields)
    for (const field of fields) {
      if (editing && field.name in editing) next[field.name] = editing[field.name]
    }
    return next
  }, [editing, fields])

  // The draft is keyed by the URL segment, so switching entries drops stale input
  // without an effect, while the loaded record stays the source of truth until typing.
  const activeDraft = draft?.key === entryParam ? draft : null
  const values = activeDraft?.values ?? loadedValues
  const fieldErrors = activeDraft?.fieldErrors ?? {}

  function openEntry(entry: Exclude<EntryParam, null>) {
    navigate(entryPath(entry))
  }

  /**
   * Step back when the card was pushed inside the app, so closing it doesn't stack
   * history. On a direct link (`key === 'default'`) there is nothing to go back to.
   */
  function closeEntry() {
    if (locationKey !== 'default') {
      navigate(-1)
      return
    }
    navigate(entryPath(null), { replace: true })
  }

  async function copyEntryLink() {
    try {
      await navigator.clipboard.writeText(window.location.href)
      showSuccess(t('entries.linkCopied'))
    } catch {
      showError(t('common.copyFailed'))
    }
  }

  const save = useMutation({
    mutationFn: async () => {
      if (editingId !== null) {
        return api<EntryRow>(`/admin/api/resources/${resourceId}/entries/${editingId}`, {
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
      setDraft(null)
      void queryClient.invalidateQueries({ queryKey: queryKeys.resources.entries(resourceId) })
      void queryClient.invalidateQueries({ queryKey: ['resource-entry', resourceId, editingId] })
      showSuccess(t('entries.saved'))
      closeEntry()
    },
    onError: (err) =>
      setDraft({
        key: entryParam ?? '',
        values,
        fieldErrors: apiFieldErrors(err),
      }),
  })

  const remove = useMutation({
    mutationFn: (row: EntryRow) =>
      api<void>(`/admin/api/resources/${resourceId}/entries/${row.id}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.resources.entries(resourceId) })
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
      void queryClient.invalidateQueries({ queryKey: queryKeys.resources.entries(resourceId) })
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('entries.bulkDeleteFailed')
      if (!(err instanceof ApiError)) showError(message)
    },
  })

  const saveColumns = useMutation({
    mutationFn: (columns: ResourceListColumn[]) =>
      api<Resource>(`/admin/api/resources/${resourceId}`, {
        method: 'PATCH',
        body: JSON.stringify({ settings: { list: { columns } } }),
      }),
    onSuccess: (data) => {
      setColumnsOpen(false)
      queryClient.setQueryData(['resource', resourceId], data)
      showSuccess(t('entries.columnsSaved'))
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
      void queryClient.invalidateQueries({ queryKey: queryKeys.resources.entries(resourceId) })
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('entries.importFailed')
      setImportError(message)
      // api() already toasts ApiError; toast local validation too
      if (!(err instanceof ApiError)) showError(message)
    },
  })

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

  return (
    <Card>
      <CardHeader className={styles.headerRow}>
        <div>
          <CardTitle>{t('resources.data')}</CardTitle>
          <CardDescription>{t('entries.hint')}</CardDescription>
        </div>
        <div className={styles.headerActions}>
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
          <Button variant="outline" onClick={() => setColumnsOpen(true)}>
            <Columns3 className={styles.icon} />
            {t('entries.columns')}
          </Button>
          <Button variant="outline" onClick={openImport}>
            {t('entries.import')}
          </Button>
          <Button variant="outline" onClick={openExport}>
            {t('entries.export')}
          </Button>
          <Button onClick={() => openEntry('new')}>{t('entries.new')}</Button>
        </div>
      </CardHeader>
      <CardContent className={styles.stack}>
        <form
          className={styles.searchForm}
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
            className={styles.searchInput}
          />
          <Button type="submit" variant="outline">
            {t('common.search')}
          </Button>
        </form>

        {list.isLoading ? (
          <TableSkeleton columns={Math.max(3, fields.length + 2)} rows={8} />
        ) : list.isError ? (
          <p className={styles.error}>
            {list.error instanceof Error ? list.error.message : t('entries.loadFailed')}
          </p>
        ) : (
          <DataTable
            fields={fields}
            columns={listColumns}
            relations={relations}
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
            editHref={(row) => entryPath(String(row.id))}
            onDelete={(row) => {
              if (confirm(t('entries.deleteConfirm', { id: row.id }))) remove.mutate(row)
            }}
          />
        )}

        {meta ? (
          <div className={styles.pagination}>
            <span>
              {t('common.pageOfTotal', {
                page: meta.page,
                totalPages: meta.totalPages,
                total: meta.total,
              })}
            </span>
            <div className={styles.paginationActions}>
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

      <Dialog
        open={editorOpen}
        onOpenChange={(open) => {
          if (!open) closeEntry()
        }}
      >
        <DialogContent>
          <DialogHeader className={styles.dialogHeader}>
            <DialogTitle>
              {editingId !== null ? t('entries.edit', { id: editingId }) : t('entries.new')}
            </DialogTitle>
            <DialogDescription>{t('entries.dialogHint')}</DialogDescription>
          </DialogHeader>
          {editingId !== null ? (
            <div className={styles.entryToolbar}>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => void copyEntryLink()}
              >
                <Link2 className={styles.icon} />
                {t('entries.copyLink')}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={!editing}
                onClick={() => setRevisionsOpen(true)}
              >
                <History className={styles.icon} />
                {t('entries.history')}
              </Button>
            </div>
          ) : null}
          {entryQuery.isLoading ? (
            <p className={styles.statusMessage}>{t('common.loading')}</p>
          ) : editingId !== null && !editing ? (
            <p className={styles.statusError}>{t('entries.notFound')}</p>
          ) : (
            <>
              <FormRenderer
                key={editingId ?? 'new'}
                fields={fields}
                values={values}
                onChange={(next) =>
                  setDraft({ key: entryParam ?? '', values: next, fieldErrors: {} })
                }
                disabled={save.isPending}
                entryId={editingId}
                errors={fieldErrors}
              />
              <div className={styles.formActions}>
                <Button variant="outline" onClick={closeEntry}>
                  {t('common.cancel')}
                </Button>
                <Button disabled={save.isPending} onClick={() => save.mutate()}>
                  {save.isPending ? t('common.saving') : t('common.save')}
                </Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={exportOpen} onOpenChange={setExportOpen}>
        <DialogContent>
          <DialogHeader className={styles.dialogHeader}>
            <DialogTitle>{t('entries.exportTitle')}</DialogTitle>
            <DialogDescription>{t('entries.exportHint')}</DialogDescription>
          </DialogHeader>
          <div className={styles.stack}>
            <div className={styles.field}>
              <Label htmlFor="export-format">{t('entries.format')}</Label>
              <Select
                id="export-format"
                value={exportFormat}
                onChange={(e) => setExportFormat(e.target.value as ExportFormat)}
              >
                <option value="json">{t('entries.formatJson')}</option>
                <option value="csv">{t('entries.formatCsv')}</option>
              </Select>
            </div>
            <div className={styles.field}>
              <div className={styles.fieldsHeader}>
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
              <div className={styles.fieldsBox}>
                <p className={styles.hint}>{t('entries.systemFields')}</p>
                {SYSTEM_EXPORT_FIELDS.map((name) => (
                  <label key={name} className={styles.checkLabel}>
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
                    <p className={styles.hintSpaced}>{t('entries.fields')}</p>
                    {schemaFieldNames.map((name) => (
                      <label key={name} className={styles.checkLabel}>
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
            {exportError ? <p className={styles.error}>{exportError}</p> : null}
            <div className={styles.formActions}>
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
          <DialogHeader className={styles.dialogHeader}>
            <DialogTitle>{t('entries.importTitle')}</DialogTitle>
            <DialogDescription>{t('entries.importHint')}</DialogDescription>
          </DialogHeader>
          <div className={styles.stack}>
            <div className={styles.field}>
              <Label htmlFor="import-format">{t('entries.format')}</Label>
              <Select
                id="import-format"
                value={importFormat}
                onChange={(e) => setImportFormat(e.target.value as ExportFormat)}
              >
                <option value="json">{t('entries.formatJson')}</option>
                <option value="csv">{t('entries.formatCsv')}</option>
              </Select>
            </div>
            <div className={styles.field}>
              <Label>{t('entries.importFile')}</Label>
              <input
                ref={importFileInputRef}
                id="import-file"
                type="file"
                className={styles.hiddenInput}
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
                className={clsx(styles.dropzone, importDragging && styles.dropzoneActive)}
              >
                <Upload className={styles.uploadIcon} />
                <div className={styles.dropzoneText}>
                  <p className={styles.dropzoneTitle}>
                    {importFile ? importFile.name : t('entries.importDropzone')}
                  </p>
                  <p className={styles.dropzoneHint}>
                    {importFile
                      ? t('entries.importDropzoneChange')
                      : t('entries.importDropzoneHint')}
                  </p>
                </div>
              </div>
            </div>
            <div className={styles.field}>
              <Label htmlFor="import-paste">{t('entries.importPaste')}</Label>
              <Textarea
                id="import-paste"
                placeholder={t('entries.importPastePlaceholder')}
                value={importPaste}
                onChange={(e) => {
                  setImportPaste(e.target.value)
                  setImportResult(null)
                }}
              />
            </div>
            {importResult ? (
              <div className={styles.result}>
                <p>
                  {t('entries.importResult', {
                    created: importResult.created,
                    failed: importResult.failed,
                  })}
                </p>
                {importResult.errors.length > 0 ? (
                  <ul className={styles.errorList}>
                    {importResult.errors.slice(0, 20).map((err) => (
                      <li key={`${err.row}-${err.message}`}>
                        {t('entries.importErrorRow', { row: err.row, message: err.message })}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : null}
            {importError ? <p className={styles.error}>{importError}</p> : null}
            <div className={styles.formActions}>
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

      <ColumnsDialog
        open={columnsOpen}
        onOpenChange={setColumnsOpen}
        fields={fields}
        columns={listColumns}
        saving={saveColumns.isPending}
        onSave={(columns) => saveColumns.mutate(columns)}
      />

      {editingId !== null ? (
        <EntryRevisionsPanel
          resourceId={resourceId}
          entryId={editingId}
          open={revisionsOpen}
          onOpenChange={setRevisionsOpen}
        />
      ) : null}
    </Card>
  )
}
