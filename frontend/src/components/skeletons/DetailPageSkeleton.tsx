import { Skeleton } from '@/components/ui/skeleton'

export function DetailPageSkeleton() {
  return (
    <div className="space-y-6" role="status" aria-busy="true">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-2">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-4 w-72" />
        </div>
        <div className="flex gap-2">
          <Skeleton className="h-9 w-24" />
          <Skeleton className="h-9 w-24" />
        </div>
      </div>
      <div className="flex gap-2 border-b pb-2">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-8 w-20" />
        ))}
      </div>
      <div className="space-y-3 rounded-lg border p-4">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-4/5 max-w-md" />
        <Skeleton className="h-4 w-3/5 max-w-sm" />
        <div className="pt-4">
          <TableishRows />
        </div>
      </div>
    </div>
  )
}

function TableishRows() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 4 }, (_, row) => (
        <div key={row} className="flex gap-3">
          <Skeleton className="h-4 flex-1" />
          <Skeleton className="h-4 flex-1" />
          <Skeleton className="h-4 w-20" />
        </div>
      ))}
    </div>
  )
}
