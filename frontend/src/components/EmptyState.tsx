import { clsx } from 'clsx'
import { InfoIcon } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import styles from './EmptyState.module.css'
import type { ReactNode } from 'react'

interface EmptyStateProps {
  title: string
  description?: string
  action?: ReactNode
  className?: string
}

export function EmptyState({ title, description, action, className }: EmptyStateProps) {
  return (
    <Alert variant="info" className={clsx(styles.root, className)}>
      <InfoIcon className={styles.icon} aria-hidden />
      <div className={styles.body}>
        <AlertTitle>{title}</AlertTitle>
        {description ? <AlertDescription>{description}</AlertDescription> : null}
      </div>
      {action ? <div className={styles.action}>{action}</div> : null}
    </Alert>
  )
}
