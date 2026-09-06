import { Button } from '@/components/ui/button'
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
}

export function DataTable({ fields, rows, onEdit, onDelete, sort, onSort }: DataTableProps) {
  const { t } = useI18n()
  const columns = fields
    .filter((f) => f.readable && !f.hidden)
    .slice()
    .sort((a, b) => a.sortOrder - b.sortOrder)
    .slice(0, 6)

  function toggleSort(name: string, sortable: boolean) {
    if (!sortable || !onSort) return
    if (sort === name) onSort(`-${name}`)
    else if (sort === `-${name}`) onSort('id')
    else onSort(name)
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
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
          <TableHead className="w-40 text-right">{t('common.actions')}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={columns.length + 2} className="text-muted-foreground">
              {t('entries.empty')}
            </TableCell>
          </TableRow>
        ) : (
          rows.map((row) => (
            <TableRow key={row.id}>
              <TableCell className="font-mono text-xs">{row.id}</TableCell>
              {columns.map((col) => (
                <TableCell key={col.name} className="max-w-[12rem] truncate">
                  {formatCell(row[col.name], t)}
                </TableCell>
              ))}
              <TableCell className="space-x-2 text-right">
                <Button size="sm" variant="outline" onClick={() => onEdit(row)}>
                  {t('common.edit')}
                </Button>
                <Button size="sm" variant="destructive" onClick={() => onDelete(row)}>
                  {t('common.delete')}
                </Button>
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
