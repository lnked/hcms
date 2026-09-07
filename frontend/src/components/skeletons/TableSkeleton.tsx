import { Skeleton } from '@/components/ui/skeleton'

interface TableSkeletonProps {
  columns?: number
  rows?: number
  className?: string
}

export function TableSkeleton({ columns = 5, rows = 6, className }: TableSkeletonProps) {
  return (
    <div className={className} role="status" aria-busy="true">
      <div className="mb-3 flex gap-3 border-b pb-3">
        {Array.from({ length: columns }, (_, i) => (
          <Skeleton key={`h-${i}`} className="h-4 flex-1" />
        ))}
      </div>
      <div className="space-y-3">
        {Array.from({ length: rows }, (_, row) => (
          <div key={row} className="flex gap-3">
            {Array.from({ length: columns }, (_, col) => (
              <Skeleton
                key={`${row}-${col}`}
                className={col === columns - 1 ? 'ml-auto h-4 w-16' : 'h-4 flex-1'}
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
