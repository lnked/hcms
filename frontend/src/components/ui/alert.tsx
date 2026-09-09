import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'
import styles from './Alert.module.css'

const variantClass = {
  default: styles.variantDefault,
  info: styles.variantInfo,
  destructive: styles.variantDestructive,
} as const

export type AlertVariant = keyof typeof variantClass

export function Alert({
  className,
  variant = 'default',
  ...props
}: HTMLAttributes<HTMLDivElement> & { variant?: AlertVariant | null }) {
  return (
    <div
      role="status"
      className={cn(styles.alert, variantClass[variant ?? 'default'], className)}
      {...props}
    />
  )
}

export function AlertTitle({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn(styles.title, className)} {...props} />
}

export function AlertDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn(styles.description, className)} {...props} />
}
