import { Fragment, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Code2, Pencil, Trash2, Upload } from 'lucide-react'
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
import { ResourceFetchExample } from '@/features/resources/ResourceFetchExample'
import { useI18n } from '@/i18n'
import { api, ApiError } from '@/lib/api'
import { copyToClipboard } from '@/lib/clipboard'
import { showError } from '@/lib/toast'
import { cn } from '@/lib/utils'
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
      navigate(`/resources/${result.resource.id}/overview`)
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

  async function parsePackageText(raw: string): Promise<void> {
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
    await parsePackageText(await file.text())
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
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">{t('resources.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('resources.subtitle')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={openImport}>
            {t('resources.package.import')}
          </Button>
          <Button onClick={() => navigate('/resources/new')}>{t('resources.create')}</Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('resources.all')}</CardTitle>
          <CardDescription>{t('resources.total', { count: resources.length })}</CardDescription>
        </CardHeader>
        <CardContent>
          {resources.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('resources.empty')}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.label')}</TableHead>
                  <TableHead>{t('common.slug')}</TableHead>
                  <TableHead>{t('common.endpoint')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {resources.map((resource) => (
                  <Fragment key={resource.id}>
                    <TableRow>
                      <TableCell>
                        <Link
                          className="font-medium hover:underline"
                          to={`/resources/${resource.id}/overview`}
                        >
                          {resource.label}
                        </Link>
                      </TableCell>
                      <TableCell className="font-mono text-xs">{resource.slug}</TableCell>
                      <TableCell className="font-mono text-xs">
                        <button
                          type="button"
                          className="cursor-pointer underline decoration-dashed underline-offset-2 hover:text-primary"
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
                      <TableCell className="text-right">
                        <div className="inline-flex items-center justify-end gap-1">
                          {resource.status !== 'published' ? (
                            <Button
                              size="icon"
                              variant="ghost"
                              disabled={publish.isPending}
                              aria-label={t('resources.publish')}
                              title={t('resources.publish')}
                              onClick={() => publish.mutate(resource.id)}
                            >
                              <Upload className="h-4 w-4" />
                            </Button>
                          ) : null}
                          <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('common.edit')}
                            title={t('common.edit')}
                            onClick={() => navigate(`/resources/${resource.id}/overview`)}
                          >
                            <Pencil className="h-4 w-4" />
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
                            <Code2 className="h-4 w-4" />
                          </Button>
                          {!resource.isSystem ? (
                            <Button
                              size="icon"
                              variant="ghost"
                              disabled={remove.isPending}
                              aria-label={t('common.delete')}
                              title={t('common.delete')}
                              onClick={() => {
                                if (confirm(t('resources.deleteConfirm', { label: resource.label }))) {
                                  remove.mutate(resource.id)
                                }
                              }}
                            >
                              <Trash2 className="h-4 w-4 text-destructive" />
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>
                    {openExampleId === resource.id ? (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={5} className="border-t-0 pt-0 pb-4">
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
          <DialogHeader className="pr-6">
            <DialogTitle>{t('resources.package.importTitle')}</DialogTitle>
            <DialogDescription>{t('resources.package.importHint')}</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t('resources.package.importFile')}</Label>
              <input
                ref={importFileInputRef}
                type="file"
                className="hidden"
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
                className={cn(
                  'flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground',
                  importDragging && 'border-primary bg-muted/40',
                )}
              >
                {importFile
                  ? importFile.name
                  : importDragging
                    ? t('resources.package.importDropzoneActive')
                    : t('resources.package.importDropzone')}
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="package-paste">{t('resources.package.importPaste')}</Label>
              <textarea
                id="package-paste"
                className="min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-sm"
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
              <div className="space-y-2">
                {slugConflict ? (
                  <p className="text-sm text-amber-700 dark:text-amber-400">
                    {t('resources.package.slugConflict', { slug: packageSlug })}
                  </p>
                ) : null}
                <Label htmlFor="package-slug">{t('resources.package.slugLabel')}</Label>
                <Input
                  id="package-slug"
                  value={importSlug}
                  onChange={(e) => setImportSlug(e.target.value)}
                  className="font-mono text-sm"
                />
              </div>
            ) : null}

            {importError ? <p className="text-sm text-destructive">{importError}</p> : null}
            {importResult ? (
              <div className="space-y-2 text-sm">
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
                    <p className="font-medium">{t('resources.package.warnings')}</p>
                    <ul className="list-disc pl-5 text-muted-foreground">
                      {importResult.warnings.map((w) => (
                        <li key={w}>{w}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            ) : null}

            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setImportOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                disabled={doImport.isPending || (!importPackage && !importPaste.trim() && !importFile)}
                onClick={() => doImport.mutate()}
              >
                {doImport.isPending
                  ? t('resources.package.importing')
                  : t('resources.package.importSubmit')}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
