import { clsx } from 'clsx'
import styles from './FieldError.module.css'

export function FieldError({ messages }: { messages?: string[] }) {
  if (!messages || messages.length === 0) {
    return null
  }

  return <p className={clsx(styles.root)}>{messages.join(' ')}</p>
}
