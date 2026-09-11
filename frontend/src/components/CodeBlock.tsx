import { clsx } from 'clsx'
import { Check, Copy } from 'lucide-react'
import { useRef, useState } from 'react'
import { highlight, type LanguageName } from 'sugar-high'
import { Button } from '@/components/ui/button'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'
import styles from './CodeBlock.module.css'

type DocLanguage = 'bash' | 'js' | 'http'

const LANG_MAP: Record<DocLanguage, LanguageName> = {
  bash: 'shell',
  js: 'javascript',
  http: 'plaintext',
}

interface CodeBlockProps {
  code: string
  /** Clipboard payload; defaults to `code`. Use when display includes extra context (e.g. crontab schedule). */
  copyCode?: string
  label?: string
  language?: DocLanguage
  editable?: boolean
  onChange?: (value: string) => void
  rows?: number
  id?: string
}

export function CodeBlock({
  code,
  copyCode,
  label,
  language = 'js',
  editable = false,
  onChange,
  rows = 10,
  id,
}: CodeBlockProps) {
  const { t } = useI18n()
  const [copied, setCopied] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const html = editable ? '' : highlight(code, { lang: LANG_MAP[language] })

  async function copy() {
    try {
      await copyToClipboard(copyCode ?? code)
      setCopied(true)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  const title = copied ? t('docs.copied') : t('docs.copy')

  return (
    <div className={clsx(styles.root)}>
      {label ? (
        <label htmlFor={id} className={clsx(styles.label)}>
          {label}
        </label>
      ) : null}
      <div className={clsx(styles.frame)}>
        <Button
          type="button"
          size="icon"
          variant="outline"
          className={clsx(styles.copyBtn)}
          onClick={() => void copy()}
          title={title}
          aria-label={title}
        >
          {copied ? (
            <Check className={clsx(styles.icon)} />
          ) : (
            <Copy className={clsx(styles.icon)} />
          )}
        </Button>
        {editable ? (
          <textarea
            id={id}
            rows={rows}
            value={code}
            spellCheck={false}
            onChange={(e) => onChange?.(e.target.value)}
            className={clsx('docs-code', styles.code, styles.textarea)}
          />
        ) : (
          <pre className={clsx('docs-code', styles.code)}>
            <code dangerouslySetInnerHTML={{ __html: html }} />
          </pre>
        )}
      </div>
    </div>
  )
}
