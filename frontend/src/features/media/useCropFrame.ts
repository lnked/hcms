import { useCallback, useMemo } from 'react'
import type { CropRect } from '@/types/field'

/**
 * Crop window expressed as zoom + centre point instead of a raw rect.
 *
 * At `zoom = 1` the window is the largest rect of the frame's aspect that still
 * fits the source, so panning and zooming can never expose empty space and the
 * clamping stays a one-liner.
 */
export type CropState = {
  zoom: number
  cx: number
  cy: number
}

export const MIN_ZOOM = 1
export const MAX_ZOOM = 8

export const FULL_CROP: CropRect = { x: 0, y: 0, w: 1, h: 1 }

function clamp(value: number, min: number, max: number): number {
  if (!Number.isFinite(value)) return min
  return Math.min(max, Math.max(min, value))
}

/** Largest rect of `frameAspect` that fits a source of `sourceAspect`, in 0..1 fractions. */
export function baseCropSize(
  sourceAspect: number,
  frameAspect: number | null,
): { w: number; h: number } {
  if (frameAspect == null || !Number.isFinite(frameAspect) || frameAspect <= 0) {
    return { w: 1, h: 1 }
  }
  if (!Number.isFinite(sourceAspect) || sourceAspect <= 0) {
    return { w: 1, h: 1 }
  }
  return frameAspect >= sourceAspect
    ? { w: 1, h: sourceAspect / frameAspect }
    : { w: frameAspect / sourceAspect, h: 1 }
}

export function clampState(
  state: CropState,
  sourceAspect: number,
  frameAspect: number | null,
): CropState {
  const zoom = clamp(state.zoom, MIN_ZOOM, MAX_ZOOM)
  const base = baseCropSize(sourceAspect, frameAspect)
  const w = base.w / zoom
  const h = base.h / zoom
  return {
    zoom,
    cx: clamp(state.cx, w / 2, 1 - w / 2),
    cy: clamp(state.cy, h / 2, 1 - h / 2),
  }
}

export function cropFromState(
  state: CropState,
  sourceAspect: number,
  frameAspect: number | null,
): CropRect {
  const safe = clampState(state, sourceAspect, frameAspect)
  const base = baseCropSize(sourceAspect, frameAspect)
  const w = base.w / safe.zoom
  const h = base.h / safe.zoom
  return { x: safe.cx - w / 2, y: safe.cy - h / 2, w, h }
}

export function stateFromCrop(
  crop: CropRect,
  sourceAspect: number,
  frameAspect: number | null,
): CropState {
  const base = baseCropSize(sourceAspect, frameAspect)
  const zoom = Math.max(base.w / Math.max(crop.w, 1e-6), base.h / Math.max(crop.h, 1e-6))
  return clampState(
    { zoom, cx: crop.x + crop.w / 2, cy: crop.y + crop.h / 2 },
    sourceAspect,
    frameAspect,
  )
}

export function isFullCrop(crop: CropRect | null | undefined): boolean {
  if (!crop) return true
  return crop.x <= 1e-6 && crop.y <= 1e-6 && crop.w >= 1 - 1e-6 && crop.h >= 1 - 1e-6
}

/** Round to 4 decimals so identical crops stay byte-identical across save cycles. */
export function roundCrop(crop: CropRect): CropRect {
  const round = (v: number): number => Math.round(v * 1e4) / 1e4
  return { x: round(crop.x), y: round(crop.y), w: round(crop.w), h: round(crop.h) }
}

/** Where the server's crop-cover would land for a 9-cell anchor, as a source-space rect. */
export function anchorCrop(
  sourceAspect: number,
  frameAspect: number | null,
  position: string,
): CropRect {
  const { w, h } = baseCropSize(sourceAspect, frameAspect)
  const x = position.includes('w') ? 0 : position.includes('e') ? 1 - w : (1 - w) / 2
  const y = position.startsWith('n') ? 0 : position.startsWith('s') ? 1 - h : (1 - h) / 2
  return { x, y, w, h }
}

/** Map a rect expressed in `outer`'s space into the space of the `inner` rect cut from it. */
export function cropWithin(outer: CropRect, inner: CropRect): CropRect {
  return {
    x: outer.x + inner.x * outer.w,
    y: outer.y + inner.y * outer.h,
    w: inner.w * outer.w,
    h: inner.h * outer.h,
  }
}

type UseCropFrameOptions = {
  sourceAspect: number
  frameAspect: number | null
  crop: CropRect | null
  onChange: (crop: CropRect) => void
}

/**
 * Controlled crop window. State lives with the caller so switching editor tabs
 * keeps every crop instead of remounting them away.
 */
export function useCropFrame({ sourceAspect, frameAspect, crop, onChange }: UseCropFrameOptions) {
  const state = useMemo(
    () =>
      crop
        ? stateFromCrop(crop, sourceAspect, frameAspect)
        : clampState({ zoom: MIN_ZOOM, cx: 0.5, cy: 0.5 }, sourceAspect, frameAspect),
    [crop, sourceAspect, frameAspect],
  )

  const effective = useMemo(
    () => cropFromState(state, sourceAspect, frameAspect),
    [state, sourceAspect, frameAspect],
  )

  const commit = useCallback(
    (next: CropState) => {
      onChange(roundCrop(cropFromState(next, sourceAspect, frameAspect)))
    },
    [onChange, sourceAspect, frameAspect],
  )

  const setZoom = useCallback((zoom: number) => commit({ ...state, zoom }), [commit, state])

  /** Pan by a fraction of the frame; positive values move the window right/down. */
  const panByFrame = useCallback(
    (dxFrame: number, dyFrame: number) =>
      commit({
        ...state,
        cx: state.cx + dxFrame * effective.w,
        cy: state.cy + dyFrame * effective.h,
      }),
    [commit, state, effective],
  )

  const reset = useCallback(() => commit({ zoom: MIN_ZOOM, cx: 0.5, cy: 0.5 }), [commit])

  return { zoom: state.zoom, crop: effective, setZoom, panByFrame, reset }
}
