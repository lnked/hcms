import { clsx } from 'clsx'
import { Bold, Code2, Italic, Link2, List } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import { highlight } from 'sugar-high'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { markdownToHtml } from '@/lib/markdown'
import styles from './RichTextEditor.module.css'

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
    <div className={styles.root}>
      <div className={styles.toolbar}>
        <div className={styles.tabs}>
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
              <Bold className={styles.toolIcon} />
            </ToolBtn>
            <ToolBtn
              label={t('richtext.italic')}
              disabled={disabled}
              onClick={() => wrapSelection('*', '*', 'italic')}
            >
              <Italic className={styles.toolIcon} />
            </ToolBtn>
            <ToolBtn label={t('richtext.link')} disabled={disabled} onClick={insertLink}>
              <Link2 className={styles.toolIcon} />
            </ToolBtn>
            <ToolBtn label={t('richtext.list')} disabled={disabled} onClick={insertList}>
              <List className={styles.toolIcon} />
            </ToolBtn>
            <ToolBtn
              label={t('richtext.code')}
              disabled={disabled}
              onClick={() => wrapSelection('`', '`', 'code')}
            >
              <Code2 className={styles.toolIcon} />
            </ToolBtn>
          </>
        ) : null}
      </div>
      {tab === 'edit' ? (
        <textarea
          ref={textareaRef}
          id={id}
          className={styles.textarea}
          disabled={disabled}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          spellCheck={false}
        />
      ) : previewHtml ? (
        <div
          className={clsx(styles.preview, 'hcms-richtext-preview')}
          dangerouslySetInnerHTML={{ __html: previewHtml }}
        />
      ) : (
        <p className={styles.emptyPreview}>{t('richtext.emptyPreview')}</p>
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
      className={styles.toolBtn}
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
