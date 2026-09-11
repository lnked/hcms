import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { Code2, Pencil, Trash2, Upload } from 'lucide-react'
import { Fragment, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { EmptyState } from '@/components/EmptyState'
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
import { Form } from '@/components/ui/form'
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
import { Textarea } from '@/components/ui/textarea'
import { ResourceFetchExample } from '@/features/resources/ResourceFetchExample'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api, ApiError } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showError } from '@/lib/toast'
import styles from './ResourcesPage.module.css'
import type { Resource } from '@/types/resource'

interface ResourcePackage {
  kind?: string
  contentType?: { slug?: string; label?: string }
  [key: string]: unknown
}

interface PackageImportResult {
  resource: Resource
  slugResolved: string
  mediaRemapped: number
  entries: { created: number; failed: number; errors: Array<{ row: number; message: string }> }
  warnings: string[]
}

function suggestFreeSlug(desired: string, taken: Set<string>): string {
  if (!taken.has(desired)) return desired
  for (let i = 2; i <= 999; i++) {
    const suffix = `_${i}`
    const base = desired.slice(0, Math.max(1, 48 - suffix.length))
    const candidate = `${base}${suffix}`
    if (!taken.has(candidate)) return candidate
  }
  return `${desired}_import`
}

export function ResourcesPage() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { aclEnabled } = useAcl()

  const [importOpen, setImportOpen] = useState(false)
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importDragging, setImportDragging] = useState(false)
  const [importPaste, setImportPaste] = useState('')
  const [importPackage, setImportPackage] = useState<ResourcePackage | null>(null)
  const [importSlug, setImportSlug] = useState('')
  const [importError, setImportError] = useState<string | null>(null)
  const [importResult, setImportResult] = useState<PackageImportResult | null>(null)
  const [openExampleId, setOpenExampleId] = useState<number | null>(null)
  const importFileInputRef = useRef<HTMLInputElement>(null)

  const query = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
  })

  const publish = useMutation({
    mutationFn: (id: number) =>
      api<Resource>(`/admin/api/resources/${id}/publish`, { method: 'POST', body: '{}' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['resources'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/resources/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['resources'] }),
  })

  const takenSlugs = useMemo(() => {
    const set = new Set<string>()
    for (const resource of query.data ?? []) {
      set.add(resource.slug)
      if (resource.contentTypeSlug) set.add(resource.contentTypeSlug)
    }
    return set
  }, [query.data])

  const packageSlug = importPackage?.contentType?.slug ?? ''
  const slugConflict = packageSlug !== '' && takenSlugs.has(packageSlug)

  const doImport = useMutation({
    mutationFn: async () => {
      let pkg = importPackage
      if (!pkg) {
        let raw = importPaste.trim()
        if (!raw && importFile) {
          raw = await importFile.text()
        }
        if (!raw) {
          throw new Error(t('resources.package.importEmpty'))
        }
        try {
          pkg = JSON.parse(raw) as ResourcePackage
        } catch {
          throw new Error(t('resources.package.importInvalid'))
        }
      }
      const desired = importSlug.trim() || pkg.contentType?.slug || ''
      const body: { package: ResourcePackage; slug?: string } = { package: pkg }
      if (desired && (slugConflict || desired !== pkg.contentType?.slug)) {
        body.slug = desired
      }
      return api<PackageImportResult>('/admin/api/resources/package/import', {
        method: 'POST',
        body: JSON.stringify(body),
      })
    },
    onSuccess: (result) => {
      setImportResult(result)
      setImportError(null)
      void queryClient.invalidateQueries({ queryKey: ['resources'] })
      void navigate(`/resources/${result.resource.id}/overview`)
    },
    onError: (err) => {
      const message = err instanceof Error ? err.message : t('resources.package.importFailed')
      setImportError(message)
      if (!(err instanceof ApiError)) showError(message)
    },
  })

  const copyEndpoint = async (endpoint: string) => {
    try {
      await copyToClipboard(endpoint)
    } catch {
      // ignore
    }
  }

  function parsePackageText(raw: string): void {
    try {
      const pkg = JSON.parse(raw) as ResourcePackage
      if (pkg.kind !== 'cms.resource.package') {
        setImportError(t('resources.package.importInvalid'))
        setImportPackage(null)
        return
      }
      const slug = typeof pkg.contentType?.slug === 'string' ? pkg.contentType.slug : ''
      setImportPackage(pkg)
      setImportSlug(suggestFreeSlug(slug, takenSlugs))
      setImportError(null)
      setImportResult(null)
    } catch {
      setImportError(t('resources.package.importInvalid'))
      setImportPackage(null)
    }
  }

  async function assignImportFile(file: File | null) {
    setImportFile(file)
    setImportResult(null)
    setImportError(null)
    setImportPackage(null)
    if (!file) return
    parsePackageText(await file.text())
  }

  function openImport() {
    setImportOpen(true)
    setImportFile(null)
    setImportDragging(false)
    setImportPaste('')
    setImportPackage(null)
    setImportSlug('')
    setImportError(null)
    setImportResult(null)
  }

  const resources = query.data ?? []

  return (
    <div className={styles.stack}>
      <div className={styles.header}>
        <div>
          <h1 className={styles.title}>{t('resources.title')}</h1>
          <p className={styles.subtitle}>{t('resources.subtitle')}</p>
        </div>
        <div className={styles.headerActions}>
          {!aclEnabled ? (
            <>
              <Button variant="outline" onClick={openImport}>
                {t('resources.package.import')}
              </Button>
              <Button
                onClick={() => {
                  void navigate('/resources/new')
                }}
              >
                {t('resources.create')}
              </Button>
            </>
          ) : null}
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('resources.all')}</CardTitle>
          <CardDescription>{t('resources.total', { count: resources.length })}</CardDescription>
        </CardHeader>
        <CardContent>
          {query.isLoading ? (
            <TableSkeleton columns={5} rows={6} />
          ) : resources.length === 0 ? (
            <EmptyState title={t('resources.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.label')}</TableHead>
                  <TableHead>{t('common.slug')}</TableHead>
                  <TableHead>{t('common.endpoint')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className={styles.alignRight}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {resources.map((resource) => (
                  <Fragment key={resource.id}>
                    <TableRow>
                      <TableCell>
                        <Link
                          className={styles.resourceLink}
                          to={`/resources/${resource.id}/overview`}
                        >
                          {resource.label}
                        </Link>
                      </TableCell>
                      <TableCell className={styles.mono}>{resource.slug}</TableCell>
                      <TableCell className={styles.mono}>
                        <button
                          type="button"
                          className={styles.endpointBtn}
                          onClick={() => void copyEndpoint(resource.endpoint)}
                        >
                          {resource.endpoint}
                        </button>
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            resource.status === 'published'
                              ? 'default'
                              : resource.status === 'archived'
                                ? 'outline'
                                : 'secondary'
                          }
                        >
                          {resource.status}
                        </Badge>
                      </TableCell>
                      <TableCell className={styles.alignRight}>
                        <div className={styles.rowActions}>
                          {resource.status !== 'published' ? (
                            <Button
                              size="icon"
                              variant="ghost"
                              disabled={publish.isPending}
                              aria-label={t('resources.publish')}
                              title={t('resources.publish')}
                              onClick={() => publish.mutate(resource.id)}
                            >
                              <Upload className={styles.icon} />
                            </Button>
                          ) : null}
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('common.edit')}
                            title={t('common.edit')}
                            onClick={() => {
                              void navigate(`/resources/${resource.id}/overview`)
                            }}
                          >
                            <Pencil className={styles.icon} />
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('resources.fetchExample')}
                            title={t('resources.fetchExample')}
                            aria-expanded={openExampleId === resource.id}
                            onClick={() =>
                              setOpenExampleId((prev) =>
                                prev === resource.id ? null : resource.id,
                              )
                            }
                          >
                            <Code2 className={styles.icon} />
                          </Button>
                          {!resource.isSystem ? (
                            <Button
                              size="icon"
                              variant="ghost"
                              disabled={remove.isPending}
                              aria-label={t('common.delete')}
                              title={t('common.delete')}
                              onClick={() => {
                                if (
                                  confirm(t('resources.deleteConfirm', { label: resource.label }))
                                ) {
                                  remove.mutate(resource.id)
                                }
                              }}
                            >
                              <Trash2 className={styles.iconDestructive} />
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>
                    {openExampleId === resource.id ? (
                      <TableRow className={styles.exampleRow}>
                        <TableCell colSpan={5} className={styles.exampleCell}>
                          <ResourceFetchExample resource={resource} showLabel={false} />
                        </TableCell>
                      </TableRow>
                    ) : null}
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={importOpen} onOpenChange={setImportOpen}>
        <DialogContent>
          <DialogHeader className={styles.dialogHeader}>
            <DialogTitle>{t('resources.package.importTitle')}</DialogTitle>
            <DialogDescription>{t('resources.package.importHint')}</DialogDescription>
          </DialogHeader>
          <Form className={styles.formStack} onSubmit={() => doImport.mutate()}>
            <div className={styles.field}>
              <Label>{t('resources.package.importFile')}</Label>
              <input
                ref={importFileInputRef}
                type="file"
                className={styles.hiddenInput}
                accept=".json,application/json,.cms-resource.json"
                onChange={(e) => {
                  void assignImportFile(e.target.files?.[0] ?? null)
                  e.target.value = ''
                }}
              />
              <div
                role="button"
                tabIndex={0}
                aria-label={t('resources.package.importDropzone')}
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
                  const file = e.dataTransfer.files?.[0] ?? null
                  void assignImportFile(file)
                }}
                className={clsx(styles.dropzone, importDragging && styles.dropzoneActive)}
              >
                {importFile
                  ? importFile.name
                  : importDragging
                    ? t('resources.package.importDropzoneActive')
                    : t('resources.package.importDropzone')}
              </div>
            </div>

            <div className={styles.field}>
              <Label htmlFor="package-paste">{t('resources.package.importPaste')}</Label>
              <Textarea
                id="package-paste"
                className={styles.pasteArea}
                placeholder={t('resources.package.importPastePlaceholder')}
                value={importPaste}
                onChange={(e) => {
                  setImportPaste(e.target.value)
                  setImportFile(null)
                  setImportResult(null)
                  const raw = e.target.value.trim()
                  if (raw) void parsePackageText(raw)
                  else {
                    setImportPackage(null)
                    setImportSlug('')
                    setImportError(null)
                  }
                }}
              />
            </div>

            {importPackage && packageSlug ? (
              <div className={styles.field}>
                {slugConflict ? (
                  <p className={styles.conflict}>
                    {t('resources.package.slugConflict', { slug: packageSlug })}
                  </p>
                ) : null}
                <Label htmlFor="package-slug">{t('resources.package.slugLabel')}</Label>
                <Input
                  id="package-slug"
                  value={importSlug}
                  onChange={(e) => setImportSlug(e.target.value)}
                  className={styles.slugInput}
                />
              </div>
            ) : null}

            {importError ? <p className={styles.error}>{importError}</p> : null}
            {importResult ? (
              <div className={styles.result}>
                <p>
                  {t('resources.package.importResult', {
                    slug: importResult.slugResolved,
                    created: importResult.entries.created,
                    failed: importResult.entries.failed,
                    media: importResult.mediaRemapped,
                  })}
                </p>
                {importResult.warnings.length > 0 ? (
                  <div>
                    <p className={styles.warningsTitle}>{t('resources.package.warnings')}</p>
                    <ul className={styles.warningsList}>
                      {importResult.warnings.map((w) => (
                        <li key={w}>{w}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            ) : null}

            <div className={styles.formActions}>
              <Button variant="outline" onClick={() => setImportOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                type="submit"
                disabled={
                  doImport.isPending || (!importPackage && !importPaste.trim() && !importFile)
                }
              >
                {doImport.isPending
                  ? t('resources.package.importing')
                  : t('resources.package.importSubmit')}
              </Button>
            </div>
          </Form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
