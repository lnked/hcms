/** Shared shell for every form control: input, select, textarea and custom wrappers. */
export const controlShellClass =
  'flex w-full rounded-md border border-input bg-transparent text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'

/** Single-line control metrics. */
export const controlFieldClass = `${controlShellClass} h-9 px-3 py-1`

/** Multi-line control metrics. */
export const controlAreaClass = `${controlShellClass} min-h-[120px] px-3 py-2`

/** Opt-out of full width: control hugs its intrinsic content (dates, enums, relations). */
export const controlHugClass = 'w-auto min-w-0 max-w-full'

/** Wrapper counterpart for `controlHugClass` (Select positioning container). */
export const controlHugContainerClass = 'w-fit max-w-full'
