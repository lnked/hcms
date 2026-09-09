import { ExternalLink } from 'lucide-react'
import { clsx } from 'clsx'
import { Link } from 'react-router-dom'
import { relationId, type RelationTarget } from './useRelationLabels'
import styles from './RelationCell.module.css'

interface RelationCellProps {
  value: unknown
  target?: RelationTarget
}

/** Stored id plus the related record's label, linking to its editor in a new tab. */
export function RelationCell({ value, target }: RelationCellProps) {
  const id = relationId(value)
  if (id === null) return <span className={clsx(styles.empty)}>—</span>

  const label = target?.labels[id]
  if (!target) {
    return <span className={clsx(styles.idOnly)}>#{id}</span>
  }

  return (
    <span className={clsx(styles.root)}>
      {/* The id is only worth its own column space when a label sits next to it. */}
      {label ? <span className={clsx(styles.idOnly)}>#{id}</span> : null}
      <Link
        to={`/resources/${target.resourceId}/data/${id}`}
        target="_blank"
        rel="noreferrer"
        className={clsx(styles.link)}
      >
        <span className={clsx(styles.label)}>{label ?? `#${id}`}</span>
        <ExternalLink className={clsx(styles.icon)} />
      </Link>
    </span>
  )
}
