type Props = {
  value: unknown
}

export function JsonBlock({ value }: Props) {
  const text =
    typeof value === 'string' ? value : JSON.stringify(value, null, 2)

  return <pre className="json-block">{text}</pre>
}
