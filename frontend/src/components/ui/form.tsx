import type { FormEvent, FormHTMLAttributes, ReactNode } from 'react'

export type FormProps = Omit<FormHTMLAttributes<HTMLFormElement>, 'onSubmit'> & {
  onSubmit: () => void
  children: ReactNode
}

/** Native form wrapper: Enter in inputs submits; textarea keeps newlines. */
export function Form({ onSubmit, children, ...props }: FormProps) {
  return (
    <form
      {...props}
      onSubmit={(event: FormEvent<HTMLFormElement>) => {
        event.preventDefault()
        onSubmit()
      }}
    >
      {children}
    </form>
  )
}
