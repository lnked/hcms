import { clsx } from 'clsx'
import { Copy, Eye, EyeOff, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'
import { generatePassword } from '@/lib/password'
import { showError } from '@/lib/toast'
import styles from './PasswordField.module.css'

type PasswordFieldProps = {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  placeholder?: string
  autoComplete?: string
  autoFocus?: boolean
  required?: boolean
  /** Generate button + auto-copy on generate. */
  allowGenerate?: boolean
  /** Called after a password is generated (value already updated via onChange). */
  onGenerate?: (password: string) => void
  showCopy?: boolean
  reveal?: boolean
  onRevealChange?: (reveal: boolean) => void
  hint?: string
  className?: string
  'aria-invalid'?: boolean
}

export function PasswordField({
  id,
  label,
  value,
  onChange,
  placeholder,
  autoComplete = 'new-password',
  autoFocus,
  required,
  allowGenerate = false,
  onGenerate,
  showCopy = false,
  reveal: revealControlled,
  onRevealChange,
  hint,
  className,
  'aria-invalid': ariaInvalid,
}: PasswordFieldProps) {
  const { t } = useI18n()
  const [revealInternal, setRevealInternal] = useState(false)
  const reveal = revealControlled ?? revealInternal
  const setReveal = onRevealChange ?? setRevealInternal
  const copyEnabled = showCopy || allowGenerate
  const trailingCount = 1 + (copyEnabled ? 1 : 0)

  function suggest(): void {
    const generated = generatePassword()
    onChange(generated)
    setReveal(true)
    onGenerate?.(generated)
    void copyToClipboard(generated).catch(() => showError(t('common.copyFailed')))
  }

  return (
    <div className={clsx(styles.root, className)}>
      <div className={styles.header}>
        <Label htmlFor={id}>{label}</Label>
        {allowGenerate ? (
          <Button type="button" variant="ghost" size="sm" onClick={suggest}>
            <Wand2 className={styles.generateIcon} />
            {t('account.generate')}
          </Button>
        ) : null}
      </div>
      <div className={styles.field}>
        <Input
          id={id}
          type={reveal ? 'text' : 'password'}
          autoComplete={autoComplete}
          // Intentional: focus password field when dialog/form opens.
          // eslint-disable-next-line jsx-a11y/no-autofocus -- dialog/password entry UX
          autoFocus={autoFocus}
          className={trailingCount > 1 ? styles.inputPadTwo : styles.inputPadOne}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          required={required}
          aria-invalid={ariaInvalid}
        />
        <div className={styles.trailing}>
          {copyEnabled ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className={styles.iconBtn}
              disabled={value === ''}
              title={t('account.copy')}
              aria-label={t('account.copy')}
              onClick={() => {
                void copyToClipboard(value).catch(() => showError(t('common.copyFailed')))
              }}
            >
              <Copy className={styles.icon} />
            </Button>
          ) : null}
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={styles.iconBtn}
            title={reveal ? t('account.hidePassword') : t('account.showPassword')}
            aria-label={reveal ? t('account.hidePassword') : t('account.showPassword')}
            onClick={() => setReveal(!reveal)}
          >
            {reveal ? <EyeOff className={styles.icon} /> : <Eye className={styles.icon} />}
          </Button>
        </div>
      </div>
      {hint ? <p className={styles.hint}>{hint}</p> : null}
    </div>
  )
}
