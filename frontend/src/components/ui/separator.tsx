import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'
import styles from './Separator.module.css'

export function Separator({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(styles.root, className)} {...props} />
}
