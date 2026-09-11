import { clsx } from 'clsx'
import { Pencil, RotateCcw, RotateCw, Sparkles, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { api, apiUpload } from '@/lib/api'
import { showSuccess } from '@/lib/toast'
import { ImageEditorDialog, type ImageEditorResult } from './ImageEditorDialog'
import styles from './MediaFieldPicker.module.css'
import { OptimizeImageDialog } from './OptimizeImageDialog'
import type {
  CropRect,
  ImageSizeConfig,
  MediaEdit,
  MediaFieldValue,
  MediaItemRef,
} from '@/types/field'

type UploadResult = MediaFieldValue & { media?: MediaItemRef; warning?: string | null }

interface MediaFieldPickerProps {
  id: string
  value: unknown
  disabled?: boolean
  accept?: string
  multiple?: boolean
  formats?: string[]
  sizes?: ImageSizeConfig[]
  /** Optional storage re-encode: webp | jpeg | png */
  encodeFormat?: string | null
  isImage?: boolean
  onChange: (value: MediaFieldValue | MediaFieldValue[] | null) => void
}

function mediaId(raw: unknown): number | null {
  const id = typeof raw === 'number' ? raw : Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

function normalizeItem(raw: unknown): MediaFieldValue | null {
  if (raw == null || raw === '') return null
  if (typeof raw === 'number' || (typeof raw === 'string' && /^\d+$/.test(raw))) {
    const id = mediaId(raw)
    return id === null ? null : { id, rotation: 0, positions: {}, variants: {} }
  }
  if (typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const id = mediaId(obj.id)
  if (id === null) return null
  const rotation = typeof obj.rotation === 'number' ? obj.rotation : Number(obj.rotation) || 0
  const media = obj.media && typeof obj.media === 'object' ? (obj.media as MediaItemRef) : undefined
  return {
    id,
    sourceId: mediaId(obj.sourceId),
    rotation,
    edit: obj.edit && typeof obj.edit === 'object' ? (obj.edit as MediaEdit) : null,
    positions: plainObject<string>(obj.positions),
    overrides: plainObject<{ crop: CropRect }>(obj.overrides),
    variants: plainObject<number | MediaItemRef>(obj.variants),
    media,
  }
}

function plainObject<T>(raw: unknown): Record<string, T> {
  return raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, T>) : {}
}

function parseValue(value: unknown, multiple: boolean): MediaFieldValue[] {
  if (value == null || value === '') return []
  if (multiple) {
    if (Array.isArray(value)) {
      return value.map(normalizeItem).filter((v): v is MediaFieldValue => v != null)
    }
    const one = normalizeItem(value)
    return one ? [one] : []
  }
  const one = normalizeItem(value)
  return one ? [one] : []
}

function acceptFromFormats(formats: string[] | undefined, fallback?: string): string | undefined {
  if (!formats || formats.length === 0) return fallback
  return formats.map((ext) => `.${ext.replace(/^\./, '')}`).join(',')
}

function defaultPositions(sizes: ImageSizeConfig[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (const size of sizes) {
    out[size.prefix] = size.position || 'c'
  }
  return out
}

function mediaUrl(item: MediaFieldValue): string {
  if (item.media?.url) return item.media.url
  return `/media/${item.id}`
}

/** Edits are always authored against the untouched upload, never a baked master. */
function sourceUrl(item: MediaFieldValue): string {
  return `/media/${item.sourceId ?? item.id}`
}

export function MediaFieldPicker({
  id,
  value,
  disabled,
  accept,
  multiple = false,
  formats = [],
  sizes = [],
  encodeFormat = null,
  isImage = false,
  onChange,
}: MediaFieldPickerProps) {
  const { t } = useI18n()
  const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [warning, setWarning] = useState<string | null>(null)
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const [optimizeIndex, setOptimizeIndex] = useState<number | null>(null)
  const items = parseValue(value, multiple)
  const resolvedAccept = acceptFromFormats(formats, accept)

  function emit(next: MediaFieldValue[]) {
    if (multiple) {
      onChange(next.length === 0 ? null : next)
    } else {
      onChange(next[0] ?? null)
    }
  }

  async function uploadFile(file: File, replaceIndex?: number) {
    setBusy(true)
    setError(null)
    setWarning(null)
    try {
      const positions =
        replaceIndex != null
          ? { ...defaultPositions(sizes), ...items[replaceIndex]?.positions }
          : defaultPositions(sizes)
      const rotation = replaceIndex != null ? (items[replaceIndex]?.rotation ?? 0) : 0
      const extra: Record<string, string> = {}
      if (formats.length > 0) extra.formats = JSON.stringify(formats)
      if (encodeFormat) extra.encodeFormat = encodeFormat
      if (sizes.length > 0) {
        extra.sizes = JSON.stringify(sizes)
        extra.positions = JSON.stringify(positions)
        extra.rotation = String(rotation)
      } else if (encodeFormat) {
        // Still send rotation/positions so the upload path stays consistent when only converting.
        extra.positions = JSON.stringify(positions)
        extra.rotation = String(rotation)
      }
      const result = await apiUpload<UploadResult>('/admin/api/media', file, 'file', extra)
      const uploadedId = mediaId(result.id)
      if (uploadedId === null) throw new Error(t('common.uploadFailed'))
      // The file is stored even when variant generation failed; keep it and say so.
      setWarning(result.warning ?? null)
      const nextItem: MediaFieldValue = {
        id: uploadedId,
        rotation: result.rotation ?? rotation,
        positions: result.positions ?? positions,
        variants: result.variants ?? {},
        media: result.media,
      }
      if (multiple) {
        if (replaceIndex != null) {
          const next = [...items]
          next[replaceIndex] = nextItem
          emit(next)
        } else {
          emit([...items, nextItem])
        }
      } else {
        emit([nextItem])
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : t('common.uploadFailed'))
    } finally {
      setBusy(false)
    }
  }

  async function regenerate(index: number, patch: Partial<MediaFieldValue>) {
    const current = items[index]
    if (!current) return
    const nextItem: MediaFieldValue = {
      ...current,
      ...patch,
      positions: patch.positions ?? current.positions,
      rotation: patch.rotation ?? current.rotation,
    }
    if (sizes.length === 0) {
      const next = [...items]
      next[index] = nextItem
      emit(next)
      return
    }
    setBusy(true)
    setError(null)
    try {
      const result = await api<UploadResult>(`/admin/api/media/${current.id}/regenerate`, {
        method: 'POST',
        body: JSON.stringify({
          sizes,
          rotation: nextItem.rotation,
          positions: nextItem.positions,
          overrides: nextItem.overrides ?? {},
          ...(encodeFormat ? { encodeFormat } : {}),
        }),
      })
      nextItem.variants = result.variants ?? {}
      nextItem.media = result.media ?? nextItem.media
      nextItem.rotation = result.rotation ?? nextItem.rotation
      nextItem.positions = result.positions ?? nextItem.positions
      const next = [...items]
      next[index] = nextItem
      emit(next)
    } catch (err) {
      setError(err instanceof Error ? err.message : t('common.uploadFailed'))
    } finally {
      setBusy(false)
    }
  }

  async function applyEdit(index: number, result: ImageEditorResult) {
    const current = items[index]
    if (!current) return
    setBusy(true)
    setError(null)
    try {
      const positions = { ...defaultPositions(sizes), ...current.positions }
      const edited = await api<UploadResult>(
        `/admin/api/media/${current.sourceId ?? current.id}/edit`,
        {
          method: 'POST',
          body: JSON.stringify({
            edit: result.edit,
            sizes,
            positions,
            overrides: result.overrides,
            ...(encodeFormat ? { encodeFormat } : {}),
          }),
        },
      )
      const editedId = mediaId(edited.id)
      if (editedId === null) throw new Error(t('common.uploadFailed'))
      const next = [...items]
      next[index] = {
        id: editedId,
        sourceId: mediaId(edited.sourceId),
        rotation: edited.rotation ?? 0,
        edit: edited.edit ?? null,
        positions: edited.positions ?? positions,
        overrides: edited.overrides ?? {},
        variants: edited.variants ?? {},
        media: edited.media,
      }
      emit(next)
      setEditingIndex(null)
      showSuccess(t('media.edited'))
    } catch (err) {
      setError(err instanceof Error ? err.message : t('common.uploadFailed'))
    } finally {
      setBusy(false)
    }
  }

  function removeAt(index: number) {
    emit(items.filter((_, i) => i !== index))
  }

  const editingItem = editingIndex == null ? null : (items[editingIndex] ?? null)

  return (
    <div className={clsx(styles.root)}>
      <input
        ref={inputRef}
        id={id}
        type="file"
        accept={resolvedAccept}
        className={clsx(styles.hiddenInput)}
        disabled={disabled || busy}
        multiple={false}
        onChange={(e) => {
          void (async () => {
            const file = e.target.files?.[0]
            e.target.value = ''
            if (!file) return
            await uploadFile(file)
          })()
        }}
      />

      {items.map((item, index) => (
        <div key={`${item.id}-${index}`} className={clsx(styles.item)}>
          <div className={clsx(styles.itemRow)}>
            {isImage ||
            resolvedAccept?.includes('image') ||
            sizes.length > 0 ||
            item.media?.mime?.startsWith('image/') ? (
              <img
                src={mediaUrl(item)}
                alt=""
                className={clsx(styles.preview)}
                style={{
                  transform: item.rotation ? `rotate(${item.rotation}deg)` : undefined,
                }}
              />
            ) : null}
            <div className={clsx(styles.itemBody)}>
              <div className={clsx(styles.itemToolbar)}>
                <a
                  href={mediaUrl(item)}
                  target="_blank"
                  rel="noreferrer"
                  className={clsx(styles.mediaLink)}
                >
                  #{item.id}
                  {item.media?.originalName ? ` · ${item.media.originalName}` : ''}
                </a>
                {isImage ? (
                  <>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={disabled || busy}
                      title={t('media.edit')}
                      onClick={() => setEditingIndex(index)}
                    >
                      <Pencil className={clsx(styles.iconSm)} />
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={disabled || busy}
                      title={t('media.optimize')}
                      aria-label={t('media.optimize')}
                      onClick={() => setOptimizeIndex(index)}
                    >
                      <Sparkles className={clsx(styles.iconSm)} aria-hidden />
                    </Button>
                    {/* Quick rotate stays for untouched uploads; once edited, the editor owns orientation. */}
                    {item.sourceId == null ? (
                      <>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={disabled || busy}
                          title={t('media.rotateLeft')}
                          onClick={() =>
                            void regenerate(index, {
                              rotation: (item.rotation + 270) % 360,
                            })
                          }
                        >
                          <RotateCcw className={clsx(styles.iconSm)} />
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={disabled || busy}
                          title={t('media.rotateRight')}
                          onClick={() =>
                            void regenerate(index, {
                              rotation: (item.rotation + 90) % 360,
                            })
                          }
                        >
                          <RotateCw className={clsx(styles.iconSm)} />
                        </Button>
                      </>
                    ) : null}
                  </>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  disabled={disabled || busy}
                  title={t('media.clear')}
                  aria-label={t('media.clear')}
                  onClick={() => removeAt(index)}
                >
                  <X className={clsx(styles.iconSm)} aria-hidden />
                </Button>
              </div>
            </div>
          </div>
        </div>
      ))}

      <div className={clsx(styles.actionsRow)}>
        {(multiple || items.length === 0) && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled || busy}
            onClick={() => inputRef.current?.click()}
          >
            {busy
              ? t('media.uploading')
              : items.length > 0
                ? t('media.addAnother')
                : t('media.upload')}
          </Button>
        )}
        {!multiple && items.length === 0 ? (
          <span className={clsx(styles.emptyHint)}>{t('media.noFile')}</span>
        ) : null}
      </div>
      {error ? <p className={clsx(styles.error)}>{error}</p> : null}
      {warning ? (
        <p className={clsx(styles.warning)}>{t('media.variantsFailed', { reason: warning })}</p>
      ) : null}

      {editingItem ? (
        <ImageEditorDialog
          open
          onOpenChange={(next) => {
            if (!next) setEditingIndex(null)
          }}
          sourceUrl={sourceUrl(editingItem)}
          sizes={sizes}
          positions={{ ...defaultPositions(sizes), ...editingItem.positions }}
          edit={editingItem.edit ?? null}
          overrides={editingItem.overrides ?? {}}
          busy={busy}
          onApply={(result) => void applyEdit(editingIndex as number, result)}
        />
      ) : null}

      {optimizeIndex != null && items[optimizeIndex] ? (
        <OptimizeImageDialog
          open
          mediaIds={[items[optimizeIndex].id]}
          onOpenChange={(next) => {
            if (!next) setOptimizeIndex(null)
          }}
          onDone={(result) => {
            if (!('media' in result) || !result.media) return
            const index = optimizeIndex
            const current = items[index]
            if (!current) return
            const media = result.media as MediaItemRef & { size?: number; mime?: string }
            const nextItem: MediaFieldValue = {
              ...current,
              media: {
                ...current.media,
                ...media,
                id: media.id ?? current.id,
              },
            }
            emit(items.map((it, i) => (i === index ? nextItem : it)))
            setOptimizeIndex(null)
          }}
        />
      ) : null}
    </div>
  )
}
