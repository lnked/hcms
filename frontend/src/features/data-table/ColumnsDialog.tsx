import { clsx } from 'clsx'
import { GripVertical } from 'lucide-react'
import { useRef, useState, type DragEvent } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { useI18n } from '@/i18n'
import { listableFields, mergeColumns } from './columns'
import styles from './ColumnsDialog.module.css'
import type { SchemaField } from '@/types/field'
import type { ResourceListColumn } from '@/types/resource'

/** Unsaved layout tied to the schema + settings it was opened with. */
interface ColumnsDraft {
  key: string
  items: ResourceListColumn[]
}

interface ColumnsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  fields: SchemaField[]
  columns: ResourceListColumn[] | undefined
  saving?: boolean
  onSave: (columns: ResourceListColumn[]) => void
}

export function ColumnsDialog({
  open,
  onOpenChange,
  fields,
  columns,
  saving = false,
  onSave,
}: ColumnsDialogProps) {
  const { t } = useI18n()
  const [draft, setDraft] = useState<ColumnsDraft | null>(null)
  const [dragIndex, setDragIndex] = useState<number | null>(null)
  const [overIndex, setOverIndex] = useState<number | null>(null)
  const dragIndexRef = useRef<number | null>(null)

  const available = listableFields(fields)
  // The draft is keyed by schema + saved layout, so reopening after a schema or
  // settings change drops stale input without an effect.
  const draftKey = `${open}|${available.map((f) => f.name).join(',')}|${JSON.stringify(columns ?? null)}`
  const items = draft?.key === draftKey ? draft.items : mergeColumns(fields, columns)
  const labels = new Map(available.map((field) => [field.name, field]))

  function edit(next: ResourceListColumn[]) {
    setDraft({ key: draftKey, items: next })
  }

  function patchAt(index: number, patch: Partial<ResourceListColumn>) {
    edit(items.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function reorder(from: number, to: number) {
    if (from === to || to < 0 || to >= items.length) return
    const next = [...items]
    const [moved] = next.splice(from, 1)
    if (moved === undefined) return
    next.splice(to, 0, moved)
    edit(next)
  }

  function onDragStart(index: number, event: DragEvent<HTMLElement>) {
    dragIndexRef.current = index
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', String(index))
    // Defer paint so the React re-render does not cancel the native drag.
    requestAnimationFrame(() => {
      setDragIndex(index)
      setOverIndex(index)
    })
  }

  function onDragEnd() {
    dragIndexRef.current = null
    setDragIndex(null)
    setOverIndex(null)
  }

  function onDragOver(index: number, event: DragEvent<HTMLLIElement>) {
    if (dragIndexRef.current === null) return
    event.preventDefault()
    event.dataTransfer.dropEffect = 'move'
    setOverIndex(index)
  }

  function onDrop(index: number, event: DragEvent<HTMLLIElement>) {
    const from = dragIndexRef.current
    if (from === null) return
    event.preventDefault()
    onDragEnd()
    reorder(from, index)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader className={clsx(styles.header)}>
          <DialogTitle>{t('entries.columnsTitle')}</DialogTitle>
          <DialogDescription>{t('entries.columnsHint')}</DialogDescription>
        </DialogHeader>
        <Form className={clsx(styles.root)} onSubmit={() => onSave(items)}>
          <ul className={clsx(styles.list)}>
            {items.map((item, index) => {
              const field = labels.get(item.field)
              return (
                <li
                  key={item.field}
                  onDragOver={(e) => onDragOver(index, e)}
                  onDrop={(e) => onDrop(index, e)}
                  className={clsx(
                    styles.row,
                    dragIndex === index && styles.rowDragging,
                    overIndex === index &&
                      dragIndex !== null &&
                      dragIndex !== index &&
                      styles.rowOver,
                  )}
                >
                  <span
                    draggable
                    role="button"
                    tabIndex={0}
                    aria-label={t('entries.columnsReorder')}
                    title={t('entries.columnsReorder')}
                    className={clsx(styles.handle)}
                    onDragStart={(e) => onDragStart(index, e)}
                    onDragEnd={onDragEnd}
                    onKeyDown={(e) => {
                      if (e.key === 'ArrowUp') {
                        e.preventDefault()
                        reorder(index, index - 1)
                      } else if (e.key === 'ArrowDown') {
                        e.preventDefault()
                        reorder(index, index + 1)
                      }
                    }}
                  >
                    <GripVertical className={clsx(styles.handleIcon)} />
                  </span>
                  <label className={clsx(styles.fieldLabel)}>
                    <input
                      type="checkbox"
                      checked={item.visible}
                      onChange={(e) => patchAt(index, { visible: e.target.checked })}
                      aria-label={t('entries.columnsVisible', { field: item.field })}
                    />
                    <span className={clsx(styles.fieldName)}>{item.field}</span>
                  </label>
                  <Input
                    className={clsx(styles.labelInput)}
                    value={item.label ?? ''}
                    placeholder={field?.label || item.field}
                    aria-label={t('entries.columnsLabel', { field: item.field })}
                    onChange={(e) => patchAt(index, { label: e.target.value || null })}
                  />
                  <Input
                    className={clsx(styles.widthInput)}
                    type="number"
                    min={40}
                    max={2000}
                    value={item.width ?? ''}
                    placeholder={t('entries.columnsWidth')}
                    aria-label={t('entries.columnsWidthOf', { field: item.field })}
                    onChange={(e) => patchAt(index, { width: Number(e.target.value) || null })}
                  />
                </li>
              )
            })}
          </ul>
          <div className={clsx(styles.actions)}>
            <Button variant="outline" onClick={() => edit(mergeColumns(fields, undefined))}>
              {t('entries.columnsReset')}
            </Button>
            <Button type="submit" disabled={saving}>
              {saving ? t('common.saving') : t('common.save')}
            </Button>
          </div>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
