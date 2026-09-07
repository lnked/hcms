/** Minimal markdown → safe HTML for richtext preview. */
export function markdownToHtml(source: string): string {
  const escaped = escapeHtml(source)
  const blocks = escaped.replace(/\r\n/g, '\n').split(/\n{2,}/)
  const html = blocks
    .map((block) => {
      const trimmed = block.trim()
      if (!trimmed) return ''

      const fence = trimmed.match(/^```(?:\w+)?\n?([\s\S]*?)```$/)
      if (fence) {
        return `<pre><code>${fence[1].replace(/\n$/, '')}</code></pre>`
      }

      if (/^#{1,3} /.test(trimmed)) {
        const level = trimmed.match(/^(#{1,3}) /)?.[1].length ?? 1
        const text = inlineMarkdown(trimmed.replace(/^#{1,3} /, ''))
        return `<h${level}>${text}</h${level}>`
      }

      const lines = trimmed.split('\n')
      if (lines.every((line) => /^[-*] /.test(line))) {
        const items = lines
          .map((line) => `<li>${inlineMarkdown(line.replace(/^[-*] /, ''))}</li>`)
          .join('')
        return `<ul>${items}</ul>`
      }

      return `<p>${inlineMarkdown(lines.join('<br />'))}</p>`
    })
    .filter(Boolean)
    .join('')

  return html
}

function inlineMarkdown(text: string): string {
  return text
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
    .replace(
      /\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    )
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
