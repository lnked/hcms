import { cn } from '@/lib/utils'
import styles from './Skeleton.module.css'
import type { ComponentProps } from 'react'

function Skeleton({ className, ...props }: ComponentProps<'div'>) {
  return <div className={cn(styles.root, className)} {...props} />
}

export { Skeleton }
