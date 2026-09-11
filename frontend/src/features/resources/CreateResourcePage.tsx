import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { FieldError } from '@/components/FieldError'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './CreateResourcePage.module.css'
import type { Resource } from '@/types/resource'

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9_]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^([^a-z].*)$/, 'r_$1')
    .slice(0, 48)
}

export function CreateResourcePage() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const [label, setLabel] = useState('')
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [endpoint, setEndpoint] = useState('')
  const [description, setDescription] = useState('')
  const [slugTouched, setSlugTouched] = useState(false)
  const [endpointTouched, setEndpointTouched] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [pending, setPending] = useState(false)

  const autoSlug = useMemo(() => slugify(name || label), [name, label])

  function onLabelChange(value: string) {
    setLabel(value)
    setFieldErrors((prev) => clearFieldError(prev, 'label'))
    if (!slugTouched) {
      const next = slugify(name || value)
      setSlug(next)
      if (!endpointTouched) {
        setEndpoint(next ? `/api/${next}` : '')
      }
    }
  }

  function onNameChange(value: string) {
    setName(value)
    setFieldErrors((prev) => clearFieldError(prev, 'name'))
    if (!slugTouched) {
      const next = slugify(value || label)
      setSlug(next)
      if (!endpointTouched) {
        setEndpoint(next ? `/api/${next}` : '')
      }
    }
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setFieldErrors({})
    try {
      const resource = await api<Resource>('/admin/api/resources', {
        method: 'POST',
        body: JSON.stringify({
          label,
          name: name || undefined,
          slug: slug || undefined,
          endpoint: endpoint || undefined,
          description: description || undefined,
        }),
      })
      showSuccess(t('common.saved'))
      void navigate(`/resources/${resource.id}/overview`)
    } catch (err) {
      setFieldErrors(apiFieldErrors(err))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className={styles.root}>
      <div>
        <h1 className={styles.title}>{t('resources.createTitle')}</h1>
        <p className={styles.subtitle}>{t('resources.createSubtitle')}</p>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>{t('resources.general')}</CardTitle>
          <CardDescription>{t('resources.generalHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          <form
            className={styles.form}
            onSubmit={(e) => {
              void onSubmit(e)
            }}
          >
            <div className={styles.field}>
              <Label htmlFor="label">{t('common.label')}</Label>
              <Input
                id="label"
                value={label}
                aria-invalid={hasFieldError(fieldErrors, 'label') || undefined}
                onChange={(e) => onLabelChange(e.target.value)}
                placeholder="Articles"
                required
              />
              <FieldError messages={fieldErrors.label} />
            </div>
            <div className={styles.field}>
              <Label htmlFor="name">{t('common.name')}</Label>
              <Input
                id="name"
                value={name}
                aria-invalid={hasFieldError(fieldErrors, 'name') || undefined}
                onChange={(e) => onNameChange(e.target.value)}
                placeholder="articles"
              />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={styles.field}>
              <Label htmlFor="slug">{t('common.slug')}</Label>
              <Input
                id="slug"
                value={slug}
                aria-invalid={hasFieldError(fieldErrors, 'slug') || undefined}
                onChange={(e) => {
                  setSlugTouched(true)
                  setSlug(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'slug'))
                  if (!endpointTouched) {
                    setEndpoint(e.target.value ? `/api/${e.target.value}` : '')
                  }
                }}
                placeholder={autoSlug || 'articles'}
                required
              />
              <FieldError messages={fieldErrors.slug} />
            </div>
            <div className={styles.field}>
              <Label htmlFor="endpoint">{t('common.endpoint')}</Label>
              <Input
                id="endpoint"
                value={endpoint}
                aria-invalid={hasFieldError(fieldErrors, 'endpoint') || undefined}
                onChange={(e) => {
                  setEndpointTouched(true)
                  setEndpoint(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'endpoint'))
                }}
                placeholder="/api/articles"
                required
              />
              <FieldError messages={fieldErrors.endpoint} />
            </div>
            <div className={styles.field}>
              <Label htmlFor="description">{t('common.description')}</Label>
              <Input
                id="description"
                value={description}
                aria-invalid={hasFieldError(fieldErrors, 'description') || undefined}
                onChange={(e) => {
                  setDescription(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'description'))
                }}
              />
              <FieldError messages={fieldErrors.description} />
            </div>
            <div className={styles.actions}>
              <Button type="submit" disabled={pending}>
                {pending ? t('common.creating') : t('common.create')}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  void navigate('/resources')
                }}
              >
                {t('common.cancel')}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
