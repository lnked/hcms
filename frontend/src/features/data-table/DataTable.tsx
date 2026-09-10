import { Pencil, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { Link } from 'react-router-dom'
import { EmptyState } from '@/components/EmptyState'
import { Button, buttonVariants } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n, type MessageKey } from '@/i18n'
import { formatDateValue } from '@/lib/dateFormat'
import type { SchemaField } from '@/types/field'
import type { ResourceListColumn } from '@/types/resource'
import { resolveColumns, type TableColumn } from './columns'
import { FilterControl } from './FilterControl'
import { isFilterable } from './filters'
import { MediaCell } from './MediaCell'
import { RelationCell } from './RelationCell'
import { isManyToOneRelation, type RelationTarget } from './useRelationLabels'
import styles from './DataTable.module.css'

export type EntryRow = Record<string, unknown> & { id: number }

interface DataTableProps {
  fields: SchemaField[]
  /** Saved column layout; omit to show the schema defaults. */
  columns?: ResourceListColumn[]
  /** Resolved relation targets keyed by field name; omit to show bare ids. */
  relations?: Record<string, RelationTarget>
  rows: EntryRow[]
  /** Router path of the entry editor card, shareable and openable in a new tab. */
  editHref: (row: EntryRow) => string
  onDelete: (row: EntryRow) => void
  sort?: string
  onSort?: (sort: string) => void
  filters?: Record<string, string>
  onFilterChange?: (field: string, value: string) => void
  selectedIds?: number[]
  onSelectionChange?: (ids: number[]) => void
}

export function DataTable({
  fields,
  columns: layout,
  relations,
  rows,
  editHref,
  onDelete,
  sort,
  onSort,
  filters,
  onFilterChange,
  selectedIds,
  onSelectionChange,
}: DataTableProps) {
  const { t } = useI18n()
  const columns = resolveColumns(fields, layout)
  const titleFieldName = primaryTitleField(columns)

  const selectionEnabled = typeof onSelectionChange === 'function'
  const selected = selectedIds ?? []
  const allSelected = rows.length > 0 && rows.every((row) => selected.includes(row.id))
  const someSelected = rows.some((row) => selected.includes(row.id))
  const showFilters = typeof onFilterChange === 'function'
  const filterableColumns = columns.filter((col) => isFilterable(col.field))
  const colSpan = columns.length + 2 + (selectionEnabled ? 1 : 0)

  function toggleSort(name: string, sortable: boolean) {
    if (!sortable || !onSort) return
    if (sort === name) onSort(`-${name}`)
    else if (sort === `-${name}`) onSort('id')
    else onSort(name)
  }

  function toggleAll() {
    if (!onSelectionChange) return
    if (allSelected) onSelectionChange(selected.filter((id) => !rows.some((row) => row.id === id)))
    else {
      const next = new Set(selected)
      for (const row of rows) next.add(row.id)
      onSelectionChange([...next])
    }
  }

  function toggleOne(id: number) {
    if (!onSelectionChange) return
    if (selected.includes(id)) onSelectionChange(selected.filter((x) => x !== id))
    else onSelectionChange([...selected, id])
  }

  if (rows.length === 0 && !showFilters) {
    return <EmptyState title={t('entries.empty')} />
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          {selectionEnabled ? (
            <TableHead className={clsx(styles.colNarrow)}>
              <input
                type="checkbox"
                checked={allSelected}
                ref={(el) => {
                  if (el) el.indeterminate = someSelected && !allSelected
                }}
                onChange={toggleAll}
                aria-label={t('entries.selectAll')}
              />
            </TableHead>
          ) : null}
          <TableHead className={clsx(styles.colId)}>ID</TableHead>
          {columns.map((col) => (
            <TableHead
              key={col.field.name}
              style={col.width ? { width: col.width, minWidth: col.width } : undefined}
            >
              {col.field.sortable && onSort ? (
                <button
                  type="button"
                  className={clsx(styles.sortBtn)}
                  onClick={() => toggleSort(col.field.name, true)}
                >
                  {col.label}
                  {sort === col.field.name ? ' ↑' : sort === `-${col.field.name}` ? ' ↓' : ''}
                </button>
              ) : (
                col.label
              )}
            </TableHead>
          ))}
          <TableHead className={clsx(styles.colActions)}>{t('common.actions')}</TableHead>
        </TableRow>
        {showFilters && filterableColumns.length > 0 ? (
          <TableRow>
            {selectionEnabled ? <TableHead /> : null}
            <TableHead />
            {columns.map((col) => (
              <TableHead key={`filter-${col.field.name}`} className={clsx(styles.filterHead)}>
                <FilterControl
                  field={col.field}
                  label={col.label}
                  value={filters?.[col.field.name] ?? ''}
                  onChange={(value) => onFilterChange?.(col.field.name, value)}
                />
              </TableHead>
            ))}
            <TableHead />
          </TableRow>
        ) : null}
      </TableHeader>
      <TableBody>
        {rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={colSpan} className={clsx(styles.emptyCell)}>
              {t('entries.empty')}
            </TableCell>
          </TableRow>
        ) : (
          rows.map((row) => (
            <TableRow key={row.id}>
              {selectionEnabled ? (
                <TableCell>
                  <input
                    type="checkbox"
                    checked={selected.includes(row.id)}
                    onChange={() => toggleOne(row.id)}
                    aria-label={t('entries.selectRow', { id: row.id })}
                  />
                </TableCell>
              ) : null}
              <TableCell className={clsx(styles.monoXs)}>{row.id}</TableCell>
              {columns.map((col) =>
                isMediaField(col.field.type) ? (
                  <TableCell key={col.field.name}>
                    <MediaCell value={row[col.field.name]} fieldType={col.field.type} />
                  </TableCell>
                ) : isManyToOneRelation(col.field) ? (
                  <TableCell key={col.field.name} style={{ maxWidth: col.width ?? '14rem' }}>
                    <RelationCell
                      value={row[col.field.name]}
                      target={relations?.[col.field.name]}
                    />
                  </TableCell>
                ) : (
                  <TableCell
                    key={col.field.name}
                    className={clsx(styles.truncate)}
                    style={{ maxWidth: col.width ?? '12rem' }}
                  >
                    {col.field.name === titleFieldName ? (
                      <Link to={editHref(row)} className={clsx(styles.titleLink)}>
                        {formatCell(row[col.field.name], col.field, t)}
                      </Link>
                    ) : (
                      formatCell(row[col.field.name], col.field, t)
                    )}
                  </TableCell>
                ),
              )}
              <TableCell className={clsx(styles.alignRight)}>
                <div className={clsx(styles.rowActions)}>
                  <Link
                    to={editHref(row)}
                    className={buttonVariants({ size: 'icon', variant: 'ghost' })}
                    aria-label={t('common.edit')}
                    title={t('common.edit')}
                  >
                    <Pencil className={clsx(styles.icon)} />
                  </Link>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t('common.delete')}
                    title={t('common.delete')}
                    onClick={() => onDelete(row)}
                  >
                    <Trash2 className={clsx(styles.iconDanger)} />
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  )
}

function isMediaField(type: string): boolean {
  return type === 'image' || type === 'file'
}

const TITLE_FIELD_NAMES = ['title', 'name', 'label'] as const
const TITLE_FIELD_TYPES = new Set(['string', 'slug', 'text', 'email', 'url'])

/** Prefer title/name/label, else first visible text-like column. */
function primaryTitleField(columns: TableColumn[]): string | null {
  for (const preferred of TITLE_FIELD_NAMES) {
    if (columns.some((col) => col.field.name === preferred)) return preferred
  }
  const first = columns.find(
    (col) => TITLE_FIELD_TYPES.has(col.field.type) && !isManyToOneRelation(col.field),
  )
  return first?.field.name ?? null
}

function formatCell(
  value: unknown,
  field: SchemaField,
  t: (key: MessageKey, params?: Record<string, string | number>) => string,
): string {
  if (value == null) return '—'
  if (typeof value === 'boolean') return value ? t('common.yes') : t('common.no')
  if (typeof value === 'object') return JSON.stringify(value)
  if (field.type === 'date' || field.type === 'datetime') {
    const format = typeof field.config.format === 'string' ? field.config.format.trim() : ''
    if (format !== '') {
      return formatDateValue(value, format) ?? String(value)
    }
  }
  return String(value)
}
