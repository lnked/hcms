import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { DataTable, type EntryRow } from '@/features/data-table/DataTable'
import { emptyValues, FormRenderer, type EntryValues } from '@/features/form-renderer/FormRenderer'
import { api, apiPage } from '@/lib/api'
import type { SchemaField } from '@/types/field'

interface ResourceEntriesPanelProps {
  resourceId: number
  fields: SchemaField[]
  published: boolean
}

export function ResourceEntriesPanel({ resourceId, fields, published }: ResourceEntriesPanelProps) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [sort, setSort] = useState('id')
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<EntryRow | null>(null)
  const [values, setValues] = useState<EntryValues>({})
  const [error, setError] = useState<string | null>(null)

  const queryKey = useMemo(
    () => ['resource-entries', resourceId, page, search, sort] as const,
    [resourceId, page, search, sort],
  )

  const list = useQuery({
    queryKey,
    enabled: published,
    queryFn: () => {
      const params = new URLSearchParams({
        page: String(page),
        limit: '20',
        sort,
      })
      if (search) params.set('search', search)
      return apiPage<EntryRow>(`/admin/api/resources/${resourceId}/entries?${params}`)
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      if (editing) {
        return api<EntryRow>(`/admin/api/resources/${resourceId}/entries/${editing.id}`, {
          method: 'PATCH',
          body: JSON.stringify(values),
        })
      }
      return api<EntryRow>(`/admin/api/resources/${resourceId}/entries`, {
        method: 'POST',
        body: JSON.stringify(values),
      })
    },
    onSuccess: () => {
      setEditorOpen(false)
      setEditing(null)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : 'Save failed'),
  })

  const remove = useMutation({
    mutationFn: (row: EntryRow) =>
      api<void>(`/admin/api/resources/${resourceId}/entries/${row.id}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['resource-entries', resourceId] })
    },
  })

  function openCreate() {
    setEditing(null)
    setValues(emptyValues(fields))
    setError(null)
    setEditorOpen(true)
  }

  function openEdit(row: EntryRow) {
    const next = emptyValues(fields)
    for (const field of fields) {
      if (field.name in row) next[field.name] = row[field.name]
    }
    setEditing(row)
    setValues(next)
    setError(null)
    setEditorOpen(true)
  }

  if (!published) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Data</CardTitle>
          <CardDescription>
            Publish the resource to create its table and manage entries.
          </CardDescription>
        </CardHeader>
      </Card>
    )
  }

  const meta = list.data?.meta
  const rows = list.data?.data ?? []

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
        <div>
          <CardTitle>Data</CardTitle>
          <CardDescription>Entries stored in the resource table.</CardDescription>
        </div>
        <Button onClick={openCreate}>New entry</Button>
      </CardHeader>
      <CardContent className="space-y-4">
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setPage(1)
            setSearch(searchInput.trim())
          }}
        >
          <Input
            placeholder="Search…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            className="max-w-xs"
          />
          <Button type="submit" variant="outline">
            Search
          </Button>
        </form>

        {list.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : list.isError ? (
          <p className="text-sm text-destructive">
            {list.error instanceof Error ? list.error.message : 'Failed to load entries'}
          </p>
        ) : (
          <DataTable
            fields={fields}
            rows={rows}
            sort={sort}
            onSort={(next) => {
              setSort(next)
              setPage(1)
            }}
            onEdit={openEdit}
            onDelete={(row) => {
              if (confirm(`Delete entry #${row.id}?`)) remove.mutate(row)
            }}
          />
        )}

        {meta ? (
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Page {meta.page} / {meta.totalPages} · {meta.total} total
            </span>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Prev
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={page >= meta.totalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        ) : null}
      </CardContent>

      <Dialog open={editorOpen} onOpenChange={setEditorOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? `Edit #${editing.id}` : 'New entry'}</DialogTitle>
            <DialogDescription>Values are validated against the resource schema.</DialogDescription>
          </DialogHeader>
          <FormRenderer
            fields={fields}
            values={values}
            onChange={setValues}
            disabled={save.isPending}
          />
          {error ? <p className="mt-3 text-sm text-destructive">{error}</p> : null}
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="outline" onClick={() => setEditorOpen(false)}>
              Cancel
            </Button>
            <Button disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
