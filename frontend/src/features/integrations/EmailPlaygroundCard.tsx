import { clsx } from 'clsx'
import { CodeBlock } from '@/components/CodeBlock'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { ApiError } from '@/lib/api'
import styles from './IntegrationsPage.module.css'
import type { useEmailPlayground } from './useEmailPlayground'

type EmailPlayground = ReturnType<typeof useEmailPlayground>

export function EmailPlaygroundCard({
  playground,
  pathOptions,
}: {
  playground: EmailPlayground
  pathOptions: string[]
}) {
  const { t } = useI18n()
  const {
    playgroundPath,
    setPlaygroundPath,
    playgroundToken,
    setPlaygroundToken,
    playgroundBody,
    setPlaygroundBody,
    playgroundResult,
    runPlayground,
  } = playground

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('integrations.email.playground.title')}</CardTitle>
        <CardDescription>{t('integrations.email.playground.hint')}</CardDescription>
      </CardHeader>
      <CardContent>
        <Form className={clsx(styles.stack)} onSubmit={() => runPlayground.mutate()}>
          <div className={clsx(styles.grid2Sm)}>
            <div className={clsx(styles.field)}>
              <Label htmlFor="email-pg-path">{t('integrations.email.playground.path')}</Label>
              <Select
                id="email-pg-path"
                value={playgroundPath}
                onChange={(e) => setPlaygroundPath(e.target.value)}
              >
                {pathOptions.map((path) => (
                  <option key={path} value={path}>
                    {path}
                  </option>
                ))}
                {!pathOptions.includes(playgroundPath) ? (
                  <option value={playgroundPath}>{playgroundPath}</option>
                ) : null}
              </Select>
            </div>
            <div className={clsx(styles.field)}>
              <Label htmlFor="email-pg-token">{t('integrations.email.playground.token')}</Label>
              <Input
                id="email-pg-token"
                type="password"
                value={playgroundToken}
                onChange={(e) => setPlaygroundToken(e.target.value)}
                placeholder="hcms_…"
                autoComplete="off"
              />
            </div>
          </div>
          <div className={clsx(styles.field)}>
            <CodeBlock
              id="email-pg-body"
              label={t('integrations.email.playground.body')}
              code={playgroundBody}
              language="js"
              editable
              rows={10}
              onChange={setPlaygroundBody}
            />
          </div>
          <Button
            type="submit"
            className={clsx(styles.playgroundRun)}
            disabled={runPlayground.isPending}
          >
            {runPlayground.isPending
              ? t('integrations.email.playground.running')
              : t('integrations.email.playground.run')}
          </Button>
          {playgroundResult ? <CodeBlock code={playgroundResult} language="js" /> : null}
          {runPlayground.error instanceof ApiError ? (
            <p className={clsx(styles.error)}>{runPlayground.error.message}</p>
          ) : null}
        </Form>
      </CardContent>
    </Card>
  )
}
