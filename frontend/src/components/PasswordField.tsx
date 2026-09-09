import { useState } from 'react'
import { Copy, Eye, EyeOff, Wand2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { copyToClipboard } from '@/lib/clipboard'
import { generatePassword } from '@/lib/password'
import { showError } from '@/lib/toast'
import { cn } from '@/lib/utils'

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
    <div className={cn('space-y-2', className)}>
      <div className="flex items-center justify-between gap-2">
        <Label htmlFor={id}>{label}</Label>
        {allowGenerate ? (
          <Button type="button" variant="ghost" size="sm" onClick={suggest}>
            <Wand2 className="mr-1 size-3.5" />
            {t('account.generate')}
          </Button>
        ) : null}
      </div>
      <div className="relative">
        <Input
          id={id}
          type={reveal ? 'text' : 'password'}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          className={trailingCount > 1 ? 'pr-20' : 'pr-10'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          required={required}
        />
        <div className="absolute top-1/2 right-1 flex -translate-y-1/2 items-center">
          {copyEnabled ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              disabled={value === ''}
              title={t('account.copy')}
              aria-label={t('account.copy')}
              onClick={() => {
                void copyToClipboard(value).catch(() => showError(t('common.copyFailed')))
              }}
            >
              <Copy className="size-4" />
            </Button>
          ) : null}
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            title={reveal ? t('account.hidePassword') : t('account.showPassword')}
            aria-label={reveal ? t('account.hidePassword') : t('account.showPassword')}
            onClick={() => setReveal(!reveal)}
          >
            {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
          </Button>
        </div>
      </div>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  )
}
