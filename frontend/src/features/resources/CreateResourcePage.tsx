import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
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
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  const autoSlug = useMemo(() => slugify(name || label), [name, label])

  function onLabelChange(value: string) {
    setLabel(value)
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
    setError(null)
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
      navigate(`/resources/${resource.id}/overview`)
    } catch (err) {
      setError(err instanceof Error ? err.message : t('common.createFailed'))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('resources.createTitle')}</h1>
        <p className="text-sm text-muted-foreground">{t('resources.createSubtitle')}</p>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>{t('resources.general')}</CardTitle>
          <CardDescription>{t('resources.generalHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={onSubmit}>
            <div className="space-y-2">
              <Label htmlFor="label">{t('common.label')}</Label>
              <Input
                id="label"
                value={label}
                onChange={(e) => onLabelChange(e.target.value)}
                placeholder="Articles"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">{t('common.name')}</Label>
              <Input
                id="name"
                value={name}
                onChange={(e) => onNameChange(e.target.value)}
                placeholder="articles"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="slug">{t('common.slug')}</Label>
              <Input
                id="slug"
                value={slug}
                onChange={(e) => {
                  setSlugTouched(true)
                  setSlug(e.target.value)
                  if (!endpointTouched) {
                    setEndpoint(e.target.value ? `/api/${e.target.value}` : '')
                  }
                }}
                placeholder={autoSlug || 'articles'}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="endpoint">{t('common.endpoint')}</Label>
              <Input
                id="endpoint"
                value={endpoint}
                onChange={(e) => {
                  setEndpointTouched(true)
                  setEndpoint(e.target.value)
                }}
                placeholder="/api/articles"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="description">{t('common.description')}</Label>
              <Input
                id="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <div className="flex gap-2">
              <Button type="submit" disabled={pending}>
                {pending ? t('common.creating') : t('common.create')}
              </Button>
              <Button type="button" variant="outline" onClick={() => navigate('/resources')}>
                {t('common.cancel')}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
