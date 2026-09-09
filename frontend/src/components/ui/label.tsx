import type { LabelHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'
import styles from './Label.module.css'

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn(styles.root, className)} {...props} />
}
