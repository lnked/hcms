import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react'
import {
  FlipHorizontal,
  FlipVertical,
  Grid3x3,
  RotateCcw,
  RotateCw,
  Undo2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import type { CropRect, ImageSizeConfig, MediaEdit } from '@/types/field'
import {
  anchorCrop,
  cropWithin,
  FULL_CROP,
  isFullCrop,
  MAX_ZOOM,
  MIN_ZOOM,
  roundCrop,
  useCropFrame,
} from './useCropFrame'

const BASE_TAB = 'base'
const PAN_STEP = 0.02
const ZOOM_STEP = 1.15
/** Tallest the crop frame may get, so the dialog stays inside the viewport. */
const FRAME_MAX_HEIGHT = '46vh'

export type ImageEditorResult = {
  edit: MediaEdit | null
  overrides: Record<string, { crop: CropRect }>
}

interface ImageEditorDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** URL of the untouched source image; edits are always authored against it. */
  sourceUrl: string
  sizes: ImageSizeConfig[]
  positions: Record<string, string>
  edit: MediaEdit | null
  overrides: Record<string, { crop: CropRect }>
  busy?: boolean
  onApply: (result: ImageEditorResult) => void
}

/** Positions a child box so that `crop` of it exactly fills this layer's parent. */
function CropLayer({ crop, children }: { crop: CropRect; children: ReactNode }) {
  return (
    <div
      className="absolute"
      style={{
        width: `${100 / crop.w}%`,
        height: `${100 / crop.h}%`,
        left: `${(-crop.x / crop.w) * 100}%`,
        top: `${(-crop.y / crop.h) * 100}%`,
      }}
    >
      {children}
    </div>
  )
}

/**
 * Fills its parent with the rotated + flipped source.
 *
 * Transform order mirrors the server pipeline (rotate, then flip): CSS applies
 * functions right to left, so `scale(...) rotate(...)` flips the rotated image.
 */
function SourceImage({
  src,
  naturalAspect,
  rotation,
  flipH,
  flipV,
  onLoad,
  onError,
}: {
  src: string
  naturalAspect: number
  rotation: number
  flipH: boolean
  flipV: boolean
  onLoad?: (width: number, height: number) => void
  onError?: () => void
}) {
  const swap = rotation % 180 !== 0
  return (
    <img
      src={src}
      alt=""
      draggable={false}
      className="pointer-events-none absolute max-w-none select-none"
      style={{
        left: '50%',
        top: '50%',
        width: swap ? `${100 * naturalAspect}%` : '100%',
        height: swap ? `${100 / naturalAspect}%` : '100%',
        transform: `translate(-50%, -50%) scale(${flipH ? -1 : 1}, ${flipV ? -1 : 1}) rotate(${rotation}deg)`,
      }}
      onLoad={(e) => onLoad?.(e.currentTarget.naturalWidth, e.currentTarget.naturalHeight)}
      onError={onError}
    />
  )
}

export function ImageEditorDialog(props: ImageEditorDialogProps) {
  const { t } = useI18n()
  return (
    <Dialog open={props.open} onOpenChange={props.onOpenChange}>
      <DialogContent className="max-w-4xl">
        <DialogHeader>
          <DialogTitle>{t('media.editTitle')}</DialogTitle>
          <DialogDescription>{t('media.editDescription')}</DialogDescription>
        </DialogHeader>
        {/* Radix unmounts the content on close, so editor state resets between openings. */}
        <ImageEditor {...props} />
      </DialogContent>
    </Dialog>
  )
}

function ImageEditor({
  onOpenChange,
  sourceUrl,
  sizes,
  positions,
  edit,
  overrides: initialOverrides,
  busy,
  onApply,
}: ImageEditorDialogProps) {
  const { t } = useI18n()
  const frameRef = useRef<HTMLDivElement>(null)
  const dragRef = useRef<{ x: number; y: number; w: number; h: number } | null>(null)

  const [natural, setNatural] = useState<{ w: number; h: number } | null>(null)
  const [failed, setFailed] = useState(false)
  const [rotation, setRotation] = useState(edit?.rotation ?? 0)
  const [flipH, setFlipH] = useState(edit?.flipH ?? false)
  const [flipV, setFlipV] = useState(edit?.flipV ?? false)
  const [baseCrop, setBaseCrop] = useState<CropRect>(edit?.crop ?? FULL_CROP)
  const [overrides, setOverrides] = useState<Record<string, CropRect>>(() =>
    Object.fromEntries(Object.entries(initialOverrides).map(([key, value]) => [key, value.crop])),
  )
  const [tab, setTab] = useState<string>(BASE_TAB)
  const [grid, setGrid] = useState(true)

  const naturalAspect = natural ? natural.w / natural.h : 1
  const swap = rotation % 180 !== 0
  const rotatedAspect = swap ? 1 / naturalAspect : naturalAspect
  const rotatedWidth = natural ? (swap ? natural.h : natural.w) : 0
  const rotatedHeight = natural ? (swap ? natural.w : natural.h) : 0
  const masterAspect = rotatedAspect * (baseCrop.w / baseCrop.h)

  const frameAspectFor = useCallback(
    (size: ImageSizeConfig): number =>
      size.mode === 'crop' ? size.width / size.height : masterAspect,
    [masterAspect],
  )

  /** Crop each size ends up with, expressed against the source image. */
  const sourceCropFor = useCallback(
    (size: ImageSizeConfig): CropRect => {
      const inner =
        overrides[size.prefix] ??
        anchorCrop(masterAspect, frameAspectFor(size), positions[size.prefix] ?? size.position)
      return cropWithin(baseCrop, inner)
    },
    [overrides, masterAspect, frameAspectFor, positions, baseCrop],
  )

  const activeSize = sizes.find((size) => size.prefix === tab) ?? null
  const activeAspect = activeSize ? frameAspectFor(activeSize) : rotatedAspect
  const activeSpaceAspect = activeSize ? masterAspect : rotatedAspect
  const activeCrop = activeSize
    ? (overrides[activeSize.prefix] ??
      anchorCrop(masterAspect, activeAspect, positions[activeSize.prefix] ?? activeSize.position))
    : baseCrop

  const handleCropChange = useCallback(
    (next: CropRect) => {
      if (activeSize) {
        setOverrides((prev) => ({ ...prev, [activeSize.prefix]: next }))
      } else {
        setBaseCrop(next)
      }
    },
    [activeSize],
  )

  const frame = useCropFrame({
    sourceAspect: activeSpaceAspect,
    frameAspect: activeAspect,
    crop: activeCrop,
    onChange: handleCropChange,
  })

  // Rotation and flips invalidate every crop authored in the previous orientation.
  const reorient = (nextRotation: number, nextFlipH: boolean, nextFlipV: boolean) => {
    setRotation(((nextRotation % 360) + 360) % 360)
    setFlipH(nextFlipH)
    setFlipV(nextFlipV)
    setBaseCrop(FULL_CROP)
    setOverrides({})
  }

  const resetAll = () => {
    reorient(0, false, false)
    setTab(BASE_TAB)
  }

  // Wheel must be non-passive to cancel page scroll, so it bypasses React's listener.
  const frameApi = useRef(frame)
  useEffect(() => {
    frameApi.current = frame
  })
  useEffect(() => {
    const element = frameRef.current
    if (!element) return
    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      const api = frameApi.current
      api.setZoom(api.zoom * Math.exp(-event.deltaY / 400))
    }
    element.addEventListener('wheel', onWheel, { passive: false })
    return () => element.removeEventListener('wheel', onWheel)
  }, [])

  const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (event.button !== 0) return
    const rect = event.currentTarget.getBoundingClientRect()
    dragRef.current = { x: event.clientX, y: event.clientY, w: rect.width, h: rect.height }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current
    if (!drag) return
    const dx = (event.clientX - drag.x) / drag.w
    const dy = (event.clientY - drag.y) / drag.h
    if (dx === 0 && dy === 0) return
    drag.x = event.clientX
    drag.y = event.clientY
    frame.panByFrame(-dx, -dy)
  }

  const endDrag = (event: PointerEvent<HTMLDivElement>) => {
    dragRef.current = null
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
  }

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const step = event.shiftKey ? PAN_STEP * 5 : PAN_STEP
    const actions: Record<string, () => void> = {
      ArrowLeft: () => frame.panByFrame(-step, 0),
      ArrowRight: () => frame.panByFrame(step, 0),
      ArrowUp: () => frame.panByFrame(0, -step),
      ArrowDown: () => frame.panByFrame(0, step),
      '+': () => frame.setZoom(frame.zoom * ZOOM_STEP),
      '=': () => frame.setZoom(frame.zoom * ZOOM_STEP),
      '-': () => frame.setZoom(frame.zoom / ZOOM_STEP),
      _: () => frame.setZoom(frame.zoom / ZOOM_STEP),
      '[': () => reorient(rotation - 90, flipH, flipV),
      ']': () => reorient(rotation + 90, flipH, flipV),
      '0': () => frame.reset(),
    }
    const action = actions[event.key]
    if (!action) return
    event.preventDefault()
    action()
  }

  const upscaleFor = (size: ImageSizeConfig): boolean => {
    if (!natural) return false
    const crop = sourceCropFor(size)
    const width = rotatedWidth * crop.w
    const height = rotatedHeight * crop.h
    return size.mode === 'crop'
      ? width < size.width || height < size.height
      : width < size.width && height < size.height
  }

  const overrideCount = useMemo(
    () => sizes.filter((size) => overrides[size.prefix] != null).length,
    [sizes, overrides],
  )

  const dirty =
    rotation !== 0 || flipH || flipV || !isFullCrop(baseCrop) || Object.keys(overrides).length > 0

  const apply = () => {
    const baked = !isFullCrop(baseCrop) || rotation !== 0 || flipH || flipV
    const nextEdit: MediaEdit | null = baked
      ? { rotation, flipH, flipV, crop: isFullCrop(baseCrop) ? null : roundCrop(baseCrop) }
      : null
    const nextOverrides: Record<string, { crop: CropRect }> = {}
    for (const size of sizes) {
      const crop = overrides[size.prefix]
      if (crop && !isFullCrop(crop)) {
        nextOverrides[size.prefix] = { crop: roundCrop(crop) }
      }
    }
    onApply({ edit: nextEdit, overrides: nextOverrides })
  }

  const sourceLayer = (
    <SourceImage
      src={sourceUrl}
      naturalAspect={naturalAspect}
      rotation={rotation}
      flipH={flipH}
      flipV={flipV}
      onLoad={(w, h) => {
        setNatural({ w, h })
        setFailed(false)
      }}
      onError={() => setFailed(true)}
    />
  )

  return (
    <div className="space-y-3">
      <div role="tablist" className="flex flex-wrap gap-1 border-b border-border pb-2">
        <TabButton active={tab === BASE_TAB} onClick={() => setTab(BASE_TAB)}>
          {t('media.tabOriginal')}
        </TabButton>
        {sizes.map((size) => (
          <TabButton
            key={size.prefix}
            active={tab === size.prefix}
            onClick={() => setTab(size.prefix)}
          >
            {size.prefix}
            {overrides[size.prefix] ? (
              <span className="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-primary" />
            ) : null}
            {upscaleFor(size) ? (
              <span className="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-amber-500" />
            ) : null}
          </TabButton>
        ))}
      </div>

      <div
        ref={frameRef}
        role="application"
        tabIndex={0}
        aria-label={t('media.editTitle')}
        className={cn(
          'relative mx-auto touch-none select-none overflow-hidden rounded-md border border-border bg-muted',
          'cursor-grab active:cursor-grabbing focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        )}
        style={{
          width: `min(100%, calc(${FRAME_MAX_HEIGHT} * ${activeAspect}))`,
          aspectRatio: `${activeAspect}`,
        }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
        onKeyDown={onKeyDown}
      >
        <CropLayer crop={frame.crop}>
          {activeSize ? <CropLayer crop={baseCrop}>{sourceLayer}</CropLayer> : sourceLayer}
        </CropLayer>
        {grid ? (
          <div className="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3">
            {Array.from({ length: 9 }, (_, i) => (
              <div key={i} className="border border-white/25" />
            ))}
          </div>
        ) : null}
        {failed ? (
          <div className="absolute inset-0 flex items-center justify-center bg-background/80 text-sm text-destructive">
            {t('media.imageLoadFailed')}
          </div>
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          size="sm"
          variant="outline"
          title={t('media.rotateLeft')}
          onClick={() => reorient(rotation - 90, flipH, flipV)}
        >
          <RotateCcw className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          title={t('media.rotateRight')}
          onClick={() => reorient(rotation + 90, flipH, flipV)}
        >
          <RotateCw className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={flipH ? 'secondary' : 'outline'}
          title={t('media.flipH')}
          onClick={() => reorient(rotation, !flipH, flipV)}
        >
          <FlipHorizontal className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={flipV ? 'secondary' : 'outline'}
          title={t('media.flipV')}
          onClick={() => reorient(rotation, flipH, !flipV)}
        >
          <FlipVertical className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={grid ? 'secondary' : 'outline'}
          title={t('media.grid')}
          onClick={() => setGrid(!grid)}
        >
          <Grid3x3 className="h-3.5 w-3.5" />
        </Button>

        <div className="flex min-w-48 flex-1 items-center gap-2">
          <ZoomOut className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <input
            type="range"
            aria-label={t('media.zoom')}
            className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-input accent-primary"
            min={MIN_ZOOM}
            max={MAX_ZOOM}
            step={0.01}
            value={frame.zoom}
            onChange={(e) => frame.setZoom(Number(e.target.value))}
          />
          <ZoomIn className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        </div>

        <Button type="button" size="sm" variant="ghost" onClick={() => frame.reset()}>
          <Undo2 className="h-3.5 w-3.5" />
          {t('media.resetFrame')}
        </Button>
      </div>

      {activeSize ? (
        <div className="flex flex-wrap items-center gap-3 rounded-md border border-border p-3">
          <label className="flex items-center gap-2 text-sm">
            <Switch
              checked={overrides[activeSize.prefix] != null}
              onCheckedChange={(checked) =>
                setOverrides((prev) => {
                  const next = { ...prev }
                  if (checked) {
                    next[activeSize.prefix] = activeCrop
                  } else {
                    delete next[activeSize.prefix]
                  }
                  return next
                })
              }
            />
            {t('media.customCrop')}
          </label>
          <span className="text-xs text-muted-foreground">
            {activeSize.width}×{activeSize.height} · {activeSize.mode}
          </span>
          {upscaleFor(activeSize) ? (
            <span className="text-xs text-amber-600">
              {t('media.upscaleWarning', {
                width: String(activeSize.width),
                height: String(activeSize.height),
              })}
            </span>
          ) : null}
        </div>
      ) : null}

      {sizes.length > 0 && natural ? (
        <div className="space-y-1">
          <div className="text-xs text-muted-foreground">{t('media.previews')}</div>
          <div className="flex flex-wrap gap-3">
            {sizes.map((size) => {
              const crop = sourceCropFor(size)
              const previewAspect = (rotatedWidth * crop.w) / Math.max(rotatedHeight * crop.h, 1e-6)
              return (
                <button
                  key={size.prefix}
                  type="button"
                  className="space-y-1 text-left"
                  onClick={() => setTab(size.prefix)}
                >
                  <div
                    className="relative h-16 overflow-hidden rounded border border-border bg-muted"
                    style={{ aspectRatio: `${previewAspect}` }}
                  >
                    <CropLayer crop={crop}>
                      <SourceImage
                        src={sourceUrl}
                        naturalAspect={naturalAspect}
                        rotation={rotation}
                        flipH={flipH}
                        flipV={flipV}
                      />
                    </CropLayer>
                  </div>
                  <div className="text-xs text-muted-foreground">{size.prefix}</div>
                </button>
              )
            })}
          </div>
        </div>
      ) : null}

      <p className="text-xs text-muted-foreground">{t('media.editHotkeys')}</p>

      <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-3">
        {overrideCount > 0 ? (
          <Button type="button" size="sm" variant="ghost" onClick={() => setOverrides({})}>
            {t('media.resetSizeCrops')}
          </Button>
        ) : null}
        {dirty ? (
          <Button type="button" size="sm" variant="ghost" onClick={resetAll}>
            {t('media.resetAll')}
          </Button>
        ) : null}
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={busy}
          onClick={() => onOpenChange(false)}
        >
          {t('common.cancel')}
        </Button>
        <Button type="button" size="sm" disabled={busy || !natural} onClick={apply}>
          {busy ? t('media.applying') : t('media.apply')}
        </Button>
      </div>
    </div>
  )
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={cn(
        'inline-flex cursor-pointer items-center rounded-md px-3 py-1.5 text-sm transition-colors',
        active ? 'bg-secondary text-secondary-foreground' : 'text-muted-foreground hover:bg-accent',
      )}
    >
      {children}
    </button>
  )
}
