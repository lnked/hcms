import { clsx } from 'clsx'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
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
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import styles from './OptimizeImageDialog.module.css'

export type OptimizeFormat = 'keep' | 'webp' | 'jpeg' | 'png'

export type OptimizeOpts = {
  quality: number
  format: OptimizeFormat
  maxWidth: number | null
  maxHeight: number | null
  applyToVariants: boolean
}

export type OptimizeResult = {
  media: { id: number; size: number; mime: string; url?: string; originalName?: string }
  before: { size: number; mime: string }
  after: { size: number; mime: string }
  savedBytes: number
  savedPercent: number
  variantsOptimized: number
}

export type BulkOptimizeResult = {
  results: Array<{ id: number; ok: boolean; error?: string; data?: OptimizeResult }>
  optimized: number
  failed: number
  savedBytes: number
}

const PRESETS = [60, 75, 85] as const

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

interface OptimizeImageDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Single id, or multiple for bulk */
  mediaIds: number[]
  onDone: (result: OptimizeResult | BulkOptimizeResult) => void
}

export function OptimizeImageDialog({
  open,
  onOpenChange,
  mediaIds,
  onDone,
}: OptimizeImageDialogProps) {
  const { t } = useI18n()
  const [preset, setPreset] = useState<'60' | '75' | '85' | 'custom'>('75')
  const [quality, setQuality] = useState(75)
  const [format, setFormat] = useState<OptimizeFormat>('keep')
  const [maxSide, setMaxSide] = useState('')
  const [applyToVariants, setApplyToVariants] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [lastResult, setLastResult] = useState<OptimizeResult | BulkOptimizeResult | null>(null)

  const isBulk = mediaIds.length > 1

  function applyPreset(next: '60' | '75' | '85' | 'custom') {
    setPreset(next)
    if (next !== 'custom') setQuality(Number(next))
  }

  async function submit() {
    if (mediaIds.length === 0) return
    const q = Math.max(1, Math.min(100, quality))
    const side = maxSide.trim() === '' ? null : Number(maxSide)
    if (side !== null && (!Number.isFinite(side) || side < 1)) {
      setError(t('media.optimizeInvalidMax'))
      return
    }
    const body: OptimizeOpts = {
      quality: q,
      format,
      maxWidth: side,
      maxHeight: side,
      applyToVariants,
    }
    setBusy(true)
    setError(null)
    try {
      if (isBulk) {
        const result = await api<BulkOptimizeResult>('/admin/api/media/bulk-optimize', {
          method: 'POST',
          body: JSON.stringify({ ids: mediaIds, ...body }),
        })
        setLastResult(result)
        onDone(result)
      } else {
        const id = mediaIds[0]
        const result = await api<OptimizeResult>(`/admin/api/media/${id}/optimize`, {
          method: 'POST',
          body: JSON.stringify(body),
        })
        setLastResult(result)
        onDone(result)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : t('media.optimizeFailed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className={clsx(styles.content)}>
        <DialogHeader>
          <DialogTitle>
            {isBulk
              ? t('media.optimizeBulkTitle', { count: mediaIds.length })
              : t('media.optimizeTitle')}
          </DialogTitle>
          <DialogDescription>{t('media.optimizeDescription')}</DialogDescription>
        </DialogHeader>

        <div className={clsx(styles.form)}>
          <div className={clsx(styles.field)}>
            <Label>{t('media.optimizeQuality')}</Label>
            <div className={clsx(styles.presets)}>
              {PRESETS.map((p) => (
                <Button
                  key={p}
                  type="button"
                  size="sm"
                  variant={preset === String(p) ? 'default' : 'outline'}
                  disabled={busy}
                  onClick={() => applyPreset(String(p) as '60' | '75' | '85')}
                >
                  {p}
                </Button>
              ))}
              <Button
                type="button"
                size="sm"
                variant={preset === 'custom' ? 'default' : 'outline'}
                disabled={busy}
                onClick={() => applyPreset('custom')}
              >
                {t('media.optimizeCustom')}
              </Button>
            </div>
            {preset === 'custom' ? (
              <Input
                type="number"
                min={1}
                max={100}
                value={quality}
                disabled={busy}
                onChange={(e) => setQuality(Number(e.target.value))}
              />
            ) : null}
          </div>

          <div className={clsx(styles.field)}>
            <Label htmlFor="optimize-format">{t('media.optimizeFormat')}</Label>
            <Select
              id="optimize-format"
              value={format}
              disabled={busy}
              onChange={(e) => setFormat(e.target.value as OptimizeFormat)}
            >
              <option value="keep">{t('media.optimizeFormatKeep')}</option>
              <option value="webp">WebP</option>
              <option value="jpeg">JPEG</option>
              <option value="png">PNG</option>
            </Select>
          </div>

          <div className={clsx(styles.field)}>
            <Label htmlFor="optimize-max">{t('media.optimizeMaxSide')}</Label>
            <Input
              id="optimize-max"
              type="number"
              min={1}
              placeholder={t('media.optimizeMaxSidePlaceholder')}
              value={maxSide}
              disabled={busy}
              onChange={(e) => setMaxSide(e.target.value)}
            />
          </div>

          <div className={clsx(styles.switchRow)}>
            <Switch
              checked={applyToVariants}
              disabled={busy}
              onCheckedChange={setApplyToVariants}
              id="optimize-variants"
            />
            <Label htmlFor="optimize-variants">{t('media.optimizeVariants')}</Label>
          </div>

          <p className={clsx(styles.hint)}>{t('media.optimizeExifHint')}</p>

          {error ? <p className={clsx(styles.error)}>{error}</p> : null}

          {lastResult && 'savedBytes' in lastResult && !('optimized' in lastResult) ? (
            <p className={clsx(styles.result)}>
              {t('media.optimizeSaved', {
                before: formatSize(lastResult.before.size),
                after: formatSize(lastResult.after.size),
                saved: formatSize(Math.max(0, lastResult.savedBytes)),
                percent: String(lastResult.savedPercent),
              })}
            </p>
          ) : null}
          {lastResult && 'optimized' in lastResult ? (
            <p className={clsx(styles.result)}>
              {t('media.optimizeBulkSaved', {
                ok: lastResult.optimized,
                failed: lastResult.failed,
                saved: formatSize(Math.max(0, lastResult.savedBytes)),
              })}
            </p>
          ) : null}

          <div className={clsx(styles.actions)}>
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={() => onOpenChange(false)}
            >
              {t('common.cancel')}
            </Button>
            <Button
              type="button"
              disabled={busy || mediaIds.length === 0}
              onClick={() => void submit()}
            >
              {busy ? t('media.optimizing') : t('media.optimize')}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
