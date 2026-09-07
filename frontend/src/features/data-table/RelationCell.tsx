import { ExternalLink } from 'lucide-react'
import { Link } from 'react-router-dom'
import { relationId, type RelationTarget } from './useRelationLabels'

interface RelationCellProps {
  value: unknown
  target?: RelationTarget
}

/** Stored id plus the related record's label, linking to its editor in a new tab. */
export function RelationCell({ value, target }: RelationCellProps) {
  const id = relationId(value)
  if (id === null) return <span className="text-muted-foreground">—</span>

  const label = target?.labels[id]
  if (!target) {
    return <span className="font-mono text-xs text-muted-foreground">#{id}</span>
  }

  return (
    <span className="flex min-w-0 items-center gap-1.5">
      {/* The id is only worth its own column space when a label sits next to it. */}
      {label ? <span className="font-mono text-xs text-muted-foreground">#{id}</span> : null}
      <Link
        to={`/resources/${target.resourceId}/data/${id}`}
        target="_blank"
        rel="noreferrer"
        className="inline-flex min-w-0 items-center gap-1 text-primary hover:underline"
      >
        <span className="truncate">{label ?? `#${id}`}</span>
        <ExternalLink className="h-3 w-3 shrink-0" />
      </Link>
    </span>
  )
}
