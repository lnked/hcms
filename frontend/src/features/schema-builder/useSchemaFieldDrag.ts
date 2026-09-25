import { useRef, useState, type DragEvent, type RefObject } from 'react'

const LIST_GAP_PX = 8

interface UseSchemaFieldDragArgs {
  itemCount: number
  onReorder: (from: number, to: number) => void
}

export function useSchemaFieldDrag({ itemCount, onReorder }: UseSchemaFieldDragArgs) {
  const [dragIndex, setDragIndex] = useState<number | null>(null)
  const [overIndex, setOverIndex] = useState<number | null>(null)
  const [dragHeight, setDragHeight] = useState(0)
  const dragIndexRef = useRef<number | null>(null)
  const overIndexRef = useRef<number | null>(null)
  const dragImageRef = useRef<HTMLElement | null>(null)
  const rowRefs = useRef<(HTMLLIElement | null)[]>([])
  const listRef = useRef<HTMLUListElement>(null)
  // Geometry captured at drag start: rows shift via transform while dragging,
  // so live hit-testing would flip-flop and resolve back to the source index.
  const startRowsRef = useRef<{ top: number; height: number }[]>([])
  const startListTopRef = useRef(0)

  function clearDragState() {
    dragIndexRef.current = null
    overIndexRef.current = null
    startRowsRef.current = []
    dragImageRef.current?.remove()
    dragImageRef.current = null
    setDragIndex(null)
    setOverIndex(null)
    setDragHeight(0)
  }

  function targetIndexAt(clientY: number, from: number): number {
    const rows = startRowsRef.current
    if (rows.length === 0) return from
    const listTop = listRef.current?.getBoundingClientRect().top ?? startListTopRef.current
    const y = clientY - (listTop - startListTopRef.current)

    let insertBefore = rows.length
    for (let i = 0; i < rows.length; i += 1) {
      const row = rows[i]
      if (row === undefined) continue
      if (y < row.top + row.height / 2) {
        insertBefore = i
        break
      }
    }
    const to = insertBefore > from ? insertBefore - 1 : insertBefore
    return Math.min(Math.max(to, 0), rows.length - 1)
  }

  function rowShiftY(index: number): number {
    if (dragIndex === null || overIndex === null || dragIndex === overIndex || dragHeight <= 0) {
      return 0
    }
    const delta = dragHeight + LIST_GAP_PX
    if (dragIndex < overIndex) {
      if (index > dragIndex && index <= overIndex) return -delta
    } else if (index >= overIndex && index < dragIndex) {
      return delta
    }
    return 0
  }

  function onGripDragStart(index: number, event: DragEvent<HTMLButtonElement>) {
    dragIndexRef.current = index
    overIndexRef.current = index
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', String(index))

    startListTopRef.current = listRef.current?.getBoundingClientRect().top ?? 0
    startRowsRef.current = Array.from({ length: itemCount }, (_, i) => {
      const rect = rowRefs.current[i]?.getBoundingClientRect()
      return { top: rect?.top ?? 0, height: rect?.height ?? 0 }
    })

    const row = rowRefs.current[index]
    if (row) {
      const rect = row.getBoundingClientRect()
      const clone = row.cloneNode(true) as HTMLElement
      clone.style.width = `${rect.width}px`
      clone.style.position = 'fixed'
      clone.style.top = '-9999px'
      clone.style.left = '-9999px'
      clone.style.margin = '0'
      clone.style.opacity = '0.96'
      clone.style.boxShadow = '0 16px 40px rgba(15, 23, 42, 0.18)'
      clone.style.pointerEvents = 'none'
      clone.style.transform = 'rotate(1.5deg)'
      clone.style.zIndex = '9999'
      document.body.appendChild(clone)
      dragImageRef.current = clone
      event.dataTransfer.setDragImage(clone, event.clientX - rect.left, event.clientY - rect.top)
      setDragHeight(rect.height)
    }

    // Defer paint so React re-render does not cancel the native drag.
    requestAnimationFrame(() => {
      setDragIndex(index)
      setOverIndex(index)
    })
  }

  function onGripDragEnd() {
    clearDragState()
  }

  function onListDragOver(event: DragEvent<HTMLUListElement>) {
    const from = dragIndexRef.current
    if (from === null) return
    event.preventDefault()
    event.dataTransfer.dropEffect = 'move'
    const to = targetIndexAt(event.clientY, from)
    if (overIndexRef.current !== to) {
      overIndexRef.current = to
      setOverIndex(to)
    }
  }

  function onListDrop(event: DragEvent<HTMLUListElement>) {
    const from = dragIndexRef.current
    if (from === null) return
    event.preventDefault()
    const to = targetIndexAt(event.clientY, from)
    clearDragState()
    onReorder(from, to)
  }

  return {
    listRef: listRef as RefObject<HTMLUListElement>,
    rowRefs,
    dragIndex,
    overIndex,
    rowShiftY,
    onGripDragStart,
    onGripDragEnd,
    onListDragOver,
    onListDrop,
  }
}
