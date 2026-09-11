import * as React from 'react'
import { cn } from '@/lib/utils'
import styles from './Button.module.css'

const variantClass = {
  default: styles.variantDefault,
  secondary: styles.variantSecondary,
  outline: styles.variantOutline,
  ghost: styles.variantGhost,
  destructive: styles.variantDestructive,
  link: styles.variantLink,
} as const

const sizeClass = {
  default: styles.sizeDefault,
  sm: styles.sizeSm,
  lg: styles.sizeLg,
  icon: styles.sizeIcon,
} as const

export type ButtonVariant = keyof typeof variantClass
export type ButtonSize = keyof typeof sizeClass

export interface ButtonVariantsProps {
  variant?: ButtonVariant | null
  size?: ButtonSize | null
  className?: string
}

export function buttonVariants({
  variant = 'default',
  size = 'default',
  className,
}: ButtonVariantsProps = {}) {
  return cn(styles.btn, variantClass[variant ?? 'default'], sizeClass[size ?? 'default'], className)
}

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>, ButtonVariantsProps {}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, type = 'button', ...props }, ref) => (
    <button
      type={type}
      className={buttonVariants({ variant, size, className })}
      ref={ref}
      {...props}
    />
  ),
)
Button.displayName = 'Button'
