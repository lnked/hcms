import { clsx } from 'clsx'
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
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
import styles from './ImageEditorDialog.module.css'
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
import type { CropRect, ImageSizeConfig, MediaEdit } from '@/types/field'
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react'

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
      className={clsx(styles.cropLayer)}
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
      className={clsx(styles.sourceImg)}
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
      <DialogContent className={clsx(styles.dialogWide)}>
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
    <div className={clsx(styles.root)}>
      <div role="tablist" className={clsx(styles.tabList)}>
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
            {overrides[size.prefix] ? <span className={clsx(styles.tabDot)} /> : null}
            {upscaleFor(size) ? <span className={clsx(styles.tabDotWarn)} /> : null}
          </TabButton>
        ))}
      </div>

      {/* Crop canvas: pointer/keyboard handlers are intentional for the overlay pattern. */}
      {/* eslint-disable jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/no-noninteractive-tabindex -- crop canvas */}
      <div
        ref={frameRef}
        role="application"
        tabIndex={0}
        aria-label={t('media.editTitle')}
        className={clsx(styles.frame)}
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
          <div className={clsx(styles.gridOverlay)}>
            {Array.from({ length: 9 }, (_, i) => (
              <div key={i} className={clsx(styles.gridCell)} />
            ))}
          </div>
        ) : null}
        {failed ? <div className={clsx(styles.loadError)}>{t('media.imageLoadFailed')}</div> : null}
      </div>
      {/* eslint-enable jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/no-noninteractive-tabindex */}

      <div className={clsx(styles.toolbar)}>
        <Button
          type="button"
          size="sm"
          variant="outline"
          title={t('media.rotateLeft')}
          onClick={() => reorient(rotation - 90, flipH, flipV)}
        >
          <RotateCcw className={clsx(styles.iconSm)} />
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          title={t('media.rotateRight')}
          onClick={() => reorient(rotation + 90, flipH, flipV)}
        >
          <RotateCw className={clsx(styles.iconSm)} />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={flipH ? 'secondary' : 'outline'}
          title={t('media.flipH')}
          onClick={() => reorient(rotation, !flipH, flipV)}
        >
          <FlipHorizontal className={clsx(styles.iconSm)} />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={flipV ? 'secondary' : 'outline'}
          title={t('media.flipV')}
          onClick={() => reorient(rotation, flipH, !flipV)}
        >
          <FlipVertical className={clsx(styles.iconSm)} />
        </Button>
        <Button
          type="button"
          size="sm"
          variant={grid ? 'secondary' : 'outline'}
          title={t('media.grid')}
          onClick={() => setGrid(!grid)}
        >
          <Grid3x3 className={clsx(styles.iconSm)} />
        </Button>

        <div className={clsx(styles.zoomRow)}>
          <ZoomOut className={clsx(styles.iconMuted)} />
          <input
            type="range"
            aria-label={t('media.zoom')}
            className={clsx(styles.zoomSlider)}
            min={MIN_ZOOM}
            max={MAX_ZOOM}
            step={0.01}
            value={frame.zoom}
            onChange={(e) => frame.setZoom(Number(e.target.value))}
          />
          <ZoomIn className={clsx(styles.iconMuted)} />
        </div>

        <Button type="button" size="sm" variant="ghost" onClick={() => frame.reset()}>
          <Undo2 className={clsx(styles.iconSm)} />
          {t('media.resetFrame')}
        </Button>
      </div>

      {activeSize ? (
        <div className={clsx(styles.sizePanel)}>
          <label className={clsx(styles.switchLabel)}>
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
          <span className={clsx(styles.sizeMeta)}>
            {activeSize.width}×{activeSize.height} · {activeSize.mode}
          </span>
          {upscaleFor(activeSize) ? (
            <span className={clsx(styles.upscaleWarn)}>
              {t('media.upscaleWarning', {
                width: String(activeSize.width),
                height: String(activeSize.height),
              })}
            </span>
          ) : null}
        </div>
      ) : null}

      {sizes.length > 0 && natural ? (
        <div className={clsx(styles.previews)}>
          <div className={clsx(styles.previewsLabel)}>{t('media.previews')}</div>
          <div className={clsx(styles.previewsRow)}>
            {sizes.map((size) => {
              const crop = sourceCropFor(size)
              const previewAspect = (rotatedWidth * crop.w) / Math.max(rotatedHeight * crop.h, 1e-6)
              return (
                <button
                  key={size.prefix}
                  type="button"
                  className={clsx(styles.previewBtn)}
                  onClick={() => setTab(size.prefix)}
                >
                  <div
                    className={clsx(styles.previewFrame)}
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
                  <div className={clsx(styles.previewLabel)}>{size.prefix}</div>
                </button>
              )
            })}
          </div>
        </div>
      ) : null}

      <p className={clsx(styles.hotkeys)}>{t('media.editHotkeys')}</p>

      <div className={clsx(styles.footer)}>
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
      className={clsx(styles.tabBtn, active && styles.tabBtnActive)}
    >
      {children}
    </button>
  )
}
