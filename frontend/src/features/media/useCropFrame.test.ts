import { describe, expect, it } from 'vitest'
import {
  anchorCrop,
  baseCropSize,
  clampState,
  cropFromState,
  cropWithin,
  isFullCrop,
  MAX_ZOOM,
  stateFromCrop,
} from './useCropFrame'

const WIDE = 2 // 400×200 source

describe('baseCropSize', () => {
  it('takes the full width when the frame is wider than the source', () => {
    expect(baseCropSize(WIDE, 4)).toEqual({ w: 1, h: 0.5 })
  })

  it('takes the full height when the frame is taller than the source', () => {
    expect(baseCropSize(WIDE, 1)).toEqual({ w: 0.5, h: 1 })
  })

  it('falls back to the whole image without a frame aspect', () => {
    expect(baseCropSize(WIDE, null)).toEqual({ w: 1, h: 1 })
  })
})

describe('clampState', () => {
  it('never zooms below cover', () => {
    expect(clampState({ zoom: 0.2, cx: 0.5, cy: 0.5 }, WIDE, 1).zoom).toBe(1)
  })

  it('caps zoom', () => {
    expect(clampState({ zoom: 1000, cx: 0.5, cy: 0.5 }, WIDE, 1).zoom).toBe(MAX_ZOOM)
  })

  it('keeps the window inside the image', () => {
    const state = clampState({ zoom: 1, cx: 0, cy: 0 }, WIDE, 1)
    expect(state.cx).toBeCloseTo(0.25)
    expect(state.cy).toBeCloseTo(0.5)
  })
})

describe('cropFromState', () => {
  it('centres the largest frame-shaped window at zoom 1', () => {
    expect(cropFromState({ zoom: 1, cx: 0.5, cy: 0.5 }, WIDE, 1)).toEqual({
      x: 0.25,
      y: 0,
      w: 0.5,
      h: 1,
    })
  })

  it('shrinks the window as zoom grows', () => {
    const crop = cropFromState({ zoom: 2, cx: 0.5, cy: 0.5 }, WIDE, 1)
    expect(crop.w).toBeCloseTo(0.25)
    expect(crop.h).toBeCloseTo(0.5)
  })
})

describe('stateFromCrop', () => {
  it('round-trips a crop through zoom and centre', () => {
    const crop = { x: 0.1, y: 0.2, w: 0.25, h: 0.5 }
    const back = cropFromState(stateFromCrop(crop, WIDE, 1), WIDE, 1)
    expect(back.x).toBeCloseTo(crop.x)
    expect(back.y).toBeCloseTo(crop.y)
    expect(back.w).toBeCloseTo(crop.w)
    expect(back.h).toBeCloseTo(crop.h)
  })
})

describe('anchorCrop', () => {
  it('pins to the left edge for west anchors', () => {
    expect(anchorCrop(WIDE, 1, 'nw')).toEqual({ x: 0, y: 0, w: 0.5, h: 1 })
  })

  it('pins to the right edge for east anchors', () => {
    expect(anchorCrop(WIDE, 1, 'se')).toEqual({ x: 0.5, y: 0, w: 0.5, h: 1 })
  })

  it('centres by default', () => {
    expect(anchorCrop(WIDE, 1, 'c')).toEqual({ x: 0.25, y: 0, w: 0.5, h: 1 })
  })
})

describe('cropWithin', () => {
  it('maps an inner rect into the outer rect space', () => {
    const outer = { x: 0.25, y: 0, w: 0.5, h: 1 }
    const inner = { x: 0.5, y: 0.5, w: 0.5, h: 0.5 }
    expect(cropWithin(outer, inner)).toEqual({ x: 0.5, y: 0.5, w: 0.25, h: 0.5 })
  })
})

describe('isFullCrop', () => {
  it('treats a missing crop as full frame', () => {
    expect(isFullCrop(null)).toBe(true)
    expect(isFullCrop({ x: 0, y: 0, w: 1, h: 1 })).toBe(true)
    expect(isFullCrop({ x: 0, y: 0, w: 0.9, h: 1 })).toBe(false)
  })
})
