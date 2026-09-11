/* Association with a control is provided by consumers via htmlFor / nesting. */
/* eslint-disable jsx-a11y/label-has-associated-control */
import { cn } from '@/lib/utils'
import styles from './Label.module.css'
import type { LabelHTMLAttributes } from 'react'

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn(styles.root, className)} {...props} />
}
