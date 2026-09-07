import { useRef, useState, type ReactNode } from 'react'
import { Bold, Code2, Italic, Link2, List } from 'lucide-react'
import { highlight } from 'sugar-high'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { markdownToHtml } from '@/lib/markdown'
import { cn } from '@/lib/utils'

interface RichTextEditorProps {
  id: string
  value: string
  disabled?: boolean
  onChange: (value: string) => void
}

type Tab = 'edit' | 'preview'

export function RichTextEditor({ id, value, disabled, onChange }: RichTextEditorProps) {
  const { t } = useI18n()
  const [tab, setTab] = useState<Tab>('edit')
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  function wrapSelection(before: string, after = before, placeholder = '') {
    const el = textareaRef.current
    if (!el || disabled) return
    const start = el.selectionStart
    const end = el.selectionEnd
    const selected = value.slice(start, end) || placeholder
    const next = value.slice(0, start) + before + selected + after + value.slice(end)
    onChange(next)
    requestAnimationFrame(() => {
      el.focus()
      const cursor = start + before.length + selected.length
      el.setSelectionRange(cursor, cursor)
    })
  }

  function insertList() {
    const el = textareaRef.current
    if (!el || disabled) return
    const start = el.selectionStart
    const end = el.selectionEnd
    const selected = value.slice(start, end)
    const lines = (selected || 'item').split('\n').map((line) => `- ${line.replace(/^[-*] /, '')}`)
    const block = lines.join('\n')
    const next = value.slice(0, start) + block + value.slice(end)
    onChange(next)
    requestAnimationFrame(() => {
      el.focus()
      el.setSelectionRange(start, start + block.length)
    })
  }

  function insertLink() {
    const el = textareaRef.current
    if (!el || disabled) return
    const start = el.selectionStart
    const end = el.selectionEnd
    const selected = value.slice(start, end) || t('richtext.linkLabel')
    const url = window.prompt(t('richtext.linkPrompt'), 'https://')
    if (!url) return
    const snippet = `[${selected}](${url})`
    onChange(value.slice(0, start) + snippet + value.slice(end))
  }

  const previewHtml = enhanceCodeBlocks(markdownToHtml(value))

  return (
    <div className="overflow-hidden rounded-md border border-input shadow-sm">
      <div className="flex flex-wrap items-center gap-1 border-b bg-muted/40 px-2 py-1.5">
        <div className="mr-2 flex gap-1">
          <Button
            type="button"
            size="sm"
            variant={tab === 'edit' ? 'secondary' : 'ghost'}
            disabled={disabled}
            onClick={() => setTab('edit')}
          >
            {t('common.edit')}
          </Button>
          <Button
            type="button"
            size="sm"
            variant={tab === 'preview' ? 'secondary' : 'ghost'}
            disabled={disabled}
            onClick={() => setTab('preview')}
          >
            {t('richtext.preview')}
          </Button>
        </div>
        {tab === 'edit' ? (
          <>
            <ToolBtn
              label={t('richtext.bold')}
              disabled={disabled}
              onClick={() => wrapSelection('**', '**', 'bold')}
            >
              <Bold className="h-3.5 w-3.5" />
            </ToolBtn>
            <ToolBtn
              label={t('richtext.italic')}
              disabled={disabled}
              onClick={() => wrapSelection('*', '*', 'italic')}
            >
              <Italic className="h-3.5 w-3.5" />
            </ToolBtn>
            <ToolBtn label={t('richtext.link')} disabled={disabled} onClick={insertLink}>
              <Link2 className="h-3.5 w-3.5" />
            </ToolBtn>
            <ToolBtn label={t('richtext.list')} disabled={disabled} onClick={insertList}>
              <List className="h-3.5 w-3.5" />
            </ToolBtn>
            <ToolBtn
              label={t('richtext.code')}
              disabled={disabled}
              onClick={() => wrapSelection('`', '`', 'code')}
            >
              <Code2 className="h-3.5 w-3.5" />
            </ToolBtn>
          </>
        ) : null}
      </div>
      {tab === 'edit' ? (
        <textarea
          ref={textareaRef}
          id={id}
          className={cn(
            'min-h-40 w-full resize-y bg-transparent px-3 py-2 font-mono text-sm',
            'focus-visible:outline-none',
          )}
          disabled={disabled}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          spellCheck={false}
        />
      ) : (
        <div
          className="richtext-preview min-h-40 px-3 py-2 text-sm prose-sm [&_a]:text-primary [&_a]:underline [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_h1]:mb-2 [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:font-semibold [&_li]:ml-4 [&_li]:list-disc [&_p]:mb-2 [&_pre]:mb-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-2"
          dangerouslySetInnerHTML={{
            __html:
              previewHtml || `<p class="text-muted-foreground">${t('richtext.emptyPreview')}</p>`,
          }}
        />
      )}
    </div>
  )
}

function ToolBtn({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string
  disabled?: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <Button
      type="button"
      size="icon"
      variant="ghost"
      className="h-7 w-7"
      title={label}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
    >
      {children}
    </Button>
  )
}

function enhanceCodeBlocks(html: string): string {
  return html.replace(/<pre><code>([\s\S]*?)<\/code><\/pre>/g, (_match, code: string) => {
    const decoded = code
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&amp;/g, '&')
    try {
      return `<pre><code>${highlight(decoded)}</code></pre>`
    } catch {
      return `<pre><code>${code}</code></pre>`
    }
  })
}
