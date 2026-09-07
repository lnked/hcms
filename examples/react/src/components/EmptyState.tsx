type Props = {
  title?: string
  children?: string
}

export function EmptyState({ title = 'Nothing here', children }: Props) {
  return (
    <div className="empty">
      <strong>{title}</strong>
      {children ? <p>{children}</p> : null}
    </div>
  )
}
