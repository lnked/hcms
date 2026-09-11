import { cn } from '@/lib/utils'
import styles from './Table.module.css'
import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react'

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
  return (
    <div className={styles.wrapper}>
      <table className={cn(styles.table, className)} {...props} />
    </div>
  )
}

export function TableHeader({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={className} {...props} />
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={className} {...props} />
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
  return <tr className={cn(styles.row, className)} {...props} />
}

export function TableHead({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return <th className={cn(styles.head, className)} {...props} />
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn(styles.cell, className)} {...props} />
}
