import { Pencil, Trash2 } from 'lucide-react'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n, type MessageKey } from '@/i18n'
import type { SchemaField } from '@/types/field'

export type EntryRow = Record<string, unknown> & { id: number }

interface DataTableProps {
  fields: SchemaField[]
  rows: EntryRow[]
  onEdit: (row: EntryRow) => void
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
  rows,
  onEdit,
  onDelete,
  sort,
  onSort,
  filters,
  onFilterChange,
  selectedIds,
  onSelectionChange,
}: DataTableProps) {
  const { t } = useI18n()
  const columns = fields
    .filter((f) => f.readable && !f.hidden)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)
    .slice(0, 6)

  const selectionEnabled = typeof onSelectionChange === 'function'
  const selected = selectedIds ?? []
  const allSelected = rows.length > 0 && rows.every((row) => selected.includes(row.id))
  const someSelected = rows.some((row) => selected.includes(row.id))
  const showFilters = typeof onFilterChange === 'function'
  const filterableColumns = columns.filter((col) => col.filterable)
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
            <TableHead className="w-10">
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
          <TableHead className="w-16">ID</TableHead>
          {columns.map((col) => (
            <TableHead key={col.name}>
              {col.sortable && onSort ? (
                <button
                  type="button"
                  className="hover:underline"
                  onClick={() => toggleSort(col.name, true)}
                >
                  {col.label || col.name}
                  {sort === col.name ? ' ↑' : sort === `-${col.name}` ? ' ↓' : ''}
                </button>
              ) : (
                col.label || col.name
              )}
            </TableHead>
          ))}
          <TableHead className="w-24 text-right">{t('common.actions')}</TableHead>
        </TableRow>
        {showFilters && filterableColumns.length > 0 ? (
          <TableRow>
            {selectionEnabled ? <TableHead /> : null}
            <TableHead />
            {columns.map((col) => (
              <TableHead key={`filter-${col.name}`} className="py-2">
                {col.filterable ? (
                  <Input
                    value={filters?.[col.name] ?? ''}
                    onChange={(e) => onFilterChange?.(col.name, e.target.value)}
                    placeholder={t('entries.filterPlaceholder')}
                    aria-label={t('entries.filterField', { field: col.label || col.name })}
                    className="h-8"
                  />
                ) : null}
              </TableHead>
            ))}
            <TableHead />
          </TableRow>
        ) : null}
      </TableHeader>
      <TableBody>
        {rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={colSpan} className="py-8 text-center text-muted-foreground">
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
              <TableCell className="font-mono text-xs">{row.id}</TableCell>
              {columns.map((col) => (
                <TableCell key={col.name} className="max-w-[12rem] truncate">
                  {formatCell(row[col.name], t)}
                </TableCell>
              ))}
              <TableCell className="text-right">
                <div className="inline-flex items-center justify-end gap-1">
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t('common.edit')}
                    title={t('common.edit')}
                    onClick={() => onEdit(row)}
                  >
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t('common.delete')}
                    title={t('common.delete')}
                    onClick={() => onDelete(row)}
                  >
                    <Trash2 className="h-4 w-4 text-destructive" />
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

function formatCell(
  value: unknown,
  t: (key: MessageKey, params?: Record<string, string | number>) => string,
): string {
  if (value == null) return '—'
  if (typeof value === 'boolean') return value ? t('common.yes') : t('common.no')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}
