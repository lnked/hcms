import { useRef, useState } from 'react'
import { RotateCcw, RotateCw } from 'lucide-react'
import { AnchorPicker, type AnchorPosition } from '@/components/AnchorPicker'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { api, apiUpload } from '@/lib/api'
import type { ImageSizeConfig, MediaFieldValue, MediaItemRef } from '@/types/field'

type UploadResult = MediaFieldValue & { media?: MediaItemRef }

interface MediaFieldPickerProps {
  id: string
  value: unknown
  disabled?: boolean
  accept?: string
  multiple?: boolean
  formats?: string[]
  sizes?: ImageSizeConfig[]
  isImage?: boolean
  onChange: (value: MediaFieldValue | MediaFieldValue[] | null) => void
}

function normalizeItem(raw: unknown): MediaFieldValue | null {
  if (raw == null || raw === '') return null
  if (typeof raw === 'number' || (typeof raw === 'string' && /^\d+$/.test(raw))) {
    return { id: Number(raw), rotation: 0, positions: {}, variants: {} }
  }
  if (typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const id = typeof obj.id === 'number' ? obj.id : Number(obj.id)
  if (!Number.isFinite(id) || id < 1) return null
  const positions =
    obj.positions && typeof obj.positions === 'object' && !Array.isArray(obj.positions)
      ? (obj.positions as Record<string, string>)
      : {}
  const variants =
    obj.variants && typeof obj.variants === 'object' && !Array.isArray(obj.variants)
      ? (obj.variants as Record<string, number | MediaItemRef>)
      : {}
  const rotation = typeof obj.rotation === 'number' ? obj.rotation : Number(obj.rotation) || 0
  const media = obj.media && typeof obj.media === 'object' ? (obj.media as MediaItemRef) : undefined
  return { id, rotation, positions, variants, media }
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

function variantId(v: number | MediaItemRef): number {
  return typeof v === 'number' ? v : v.id
}

export function MediaFieldPicker({
  id,
  value,
  disabled,
  accept,
  multiple = false,
  formats = [],
  sizes = [],
  isImage = false,
  onChange,
}: MediaFieldPickerProps) {
  const { t } = useI18n()
  const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
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
    try {
      const positions =
        replaceIndex != null
          ? { ...defaultPositions(sizes), ...items[replaceIndex]?.positions }
          : defaultPositions(sizes)
      const rotation = replaceIndex != null ? (items[replaceIndex]?.rotation ?? 0) : 0
      const extra: Record<string, string> = {}
      if (formats.length > 0) extra.formats = JSON.stringify(formats)
      if (sizes.length > 0) {
        extra.sizes = JSON.stringify(sizes)
        extra.positions = JSON.stringify(positions)
        extra.rotation = String(rotation)
      }
      const result = await apiUpload<UploadResult>('/admin/api/media', file, 'file', extra)
      const nextItem: MediaFieldValue = {
        id: result.id,
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

  function removeAt(index: number) {
    emit(items.filter((_, i) => i !== index))
  }

  return (
    <div className="space-y-3">
      <input
        ref={inputRef}
        id={id}
        type="file"
        accept={resolvedAccept}
        className="hidden"
        disabled={disabled || busy}
        multiple={false}
        onChange={async (e) => {
          const file = e.target.files?.[0]
          e.target.value = ''
          if (!file) return
          await uploadFile(file)
        }}
      />

      {items.map((item, index) => (
        <div key={`${item.id}-${index}`} className="space-y-2 rounded-md border border-border p-3">
          <div className="flex flex-wrap items-start gap-3">
            {isImage ||
            resolvedAccept?.includes('image') ||
            sizes.length > 0 ||
            item.media?.mime?.startsWith('image/') ? (
              <img
                src={mediaUrl(item)}
                alt=""
                className="h-20 w-20 rounded object-cover bg-muted"
                style={{
                  transform: item.rotation ? `rotate(${item.rotation}deg)` : undefined,
                }}
              />
            ) : null}
            <div className="min-w-0 flex-1 space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <a
                  href={mediaUrl(item)}
                  target="_blank"
                  rel="noreferrer"
                  className="text-sm text-primary underline-offset-4 hover:underline"
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
                      title={t('media.rotateLeft')}
                      onClick={() =>
                        void regenerate(index, {
                          rotation: (item.rotation + 270) % 360,
                        })
                      }
                    >
                      <RotateCcw className="h-3.5 w-3.5" />
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
                      <RotateCw className="h-3.5 w-3.5" />
                    </Button>
                  </>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  disabled={disabled || busy}
                  onClick={() => removeAt(index)}
                >
                  {t('media.clear')}
                </Button>
              </div>
              {sizes.length > 0 ? (
                <div className="flex flex-wrap gap-3">
                  {sizes.map((size) => {
                    const pos = item.positions[size.prefix] ?? size.position ?? 'c'
                    const vid = item.variants[size.prefix]
                    return (
                      <div key={size.prefix} className="space-y-1">
                        <div className="text-xs text-muted-foreground">
                          {size.prefix} ({size.width}×{size.height} {size.mode})
                          {vid != null ? (
                            <>
                              {' '}
                              <a
                                href={`/media/${variantId(vid)}`}
                                target="_blank"
                                rel="noreferrer"
                                className="underline-offset-2 hover:underline"
                              >
                                #{variantId(vid)}
                              </a>
                            </>
                          ) : null}
                        </div>
                        <AnchorPicker
                          value={pos}
                          disabled={disabled || busy}
                          title={t('media.variantAnchor', { prefix: size.prefix })}
                          onChange={(position: AnchorPosition) => {
                            void regenerate(index, {
                              positions: {
                                ...item.positions,
                                [size.prefix]: position,
                              },
                            })
                          }}
                        />
                      </div>
                    )
                  })}
                </div>
              ) : null}
            </div>
          </div>
        </div>
      ))}

      <div className="flex flex-wrap items-center gap-2">
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
          <span className="text-sm text-muted-foreground">{t('media.noFile')}</span>
        ) : null}
      </div>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  )
}
