import { cn } from '@/lib/utils'
import styles from './Badge.module.css'
import type { HTMLAttributes } from 'react'

const variantClass = {
  default: styles.variantDefault,
  secondary: styles.variantSecondary,
  outline: styles.variantOutline,
  destructive: styles.variantDestructive,
} as const

export type BadgeVariant = keyof typeof variantClass

export function Badge({
  className,
  variant = 'default',
  ...props
}: HTMLAttributes<HTMLDivElement> & { variant?: BadgeVariant | null }) {
  return (
    <div className={cn(styles.badge, variantClass[variant ?? 'default'], className)} {...props} />
  )
}
