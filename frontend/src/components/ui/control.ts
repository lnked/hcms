import { clsx } from 'clsx'
import styles from './Control.module.css'

/** Shared shell for every form control: input, select, textarea and custom wrappers. */
export const controlShellClass = styles.shell

/** Single-line control metrics. */
export const controlFieldClass = clsx(styles.shell, styles.field)

/** Multi-line control metrics. */
export const controlAreaClass = clsx(styles.shell, styles.area)

/** Opt-out of full width: control hugs its intrinsic content (dates, enums, relations). */
export const controlHugClass = styles.hug

/** Wrapper counterpart for `controlHugClass` (Select positioning container). */
export const controlHugContainerClass = styles.hugContainer
