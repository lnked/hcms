import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { installApi } from '@/lib/api'
import type { InstallStatus } from '@/types/system'

const steps = ['Files', 'Database', 'Application', 'Administrator'] as const

export function InstallPage() {
  const [step, setStep] = useState(0)
  const [status, setStatus] = useState<InstallStatus | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const [db, setDb] = useState({
    host: '127.0.0.1',
    port: 3306,
    name: 'hcms',
    user: 'root',
    password: '',
    charset: 'utf8mb4',
  })
  const [app, setApp] = useState({
    name: 'HCMS',
    url: window.location.origin,
    timezone: 'UTC',
    language: 'en',
  })
  const [admin, setAdmin] = useState({
    name: '',
    email: '',
    password: '',
    passwordConfirm: '',
  })

  useEffect(() => {
    void installApi<InstallStatus>('status').then(setStatus)
  }, [])

  async function download() {
    setMessage('Downloading…')
    try {
      const result = await installApi<{ skipped?: boolean; version?: string }>('download')
      setMessage(result.skipped ? 'Files already present' : `Downloaded ${result.version}`)
      setStep(1)
    } catch (err) {
      setMessage(err instanceof Error ? err.message : 'Download failed')
    }
  }

  async function testConnection() {
    const result = await installApi<{ ok: boolean; error?: string }>('test-connection', {
      database: db,
    })
    setMessage(result.ok ? 'Connection successful' : (result.error ?? 'Failed'))
  }

  async function complete() {
    setMessage('Installing…')
    try {
      await installApi('complete', { database: db, application: app, administrator: admin })
      setDone(true)
    } catch (err) {
      setMessage(err instanceof Error ? err.message : 'Install failed')
    }
  }

  if (done) {
    return (
      <div className="mx-auto max-w-lg py-16">
        <Card>
          <CardHeader>
            <CardTitle>Installation completed</CardTitle>
            <CardDescription>CMS is ready.</CardDescription>
          </CardHeader>
          <CardContent>
            <Button
              onClick={() => {
                window.location.href = '/admin'
              }}
            >
              Open Admin Panel
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-lg py-16">
      <Card>
        <CardHeader>
          <CardTitle>Install HCMS</CardTitle>
          <CardDescription>
            Step {step + 1} / {steps.length}: {steps[step]}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {step === 0 ? (
            <>
              {status ? (
                <ul className="space-y-1 text-sm">
                  {Object.entries(status.requirements.checks).map(([key, ok]) => (
                    <li key={key} className={ok ? 'text-emerald-600' : 'text-destructive'}>
                      {key}: {ok ? 'ok' : 'fail'}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">Checking environment…</p>
              )}
              <Button onClick={() => void download()}>Download latest / continue</Button>
            </>
          ) : null}

          {step === 1 ? (
            <>
              {Object.entries({
                host: 'Host',
                port: 'Port',
                name: 'Database',
                user: 'User',
                password: 'Password',
                charset: 'Charset',
              }).map(([key, label]) => (
                <div key={key} className="space-y-2">
                  <Label>{label}</Label>
                  <Input
                    type={key === 'password' ? 'password' : 'text'}
                    value={String(db[key as keyof typeof db])}
                    onChange={(e) =>
                      setDb((prev) => ({
                        ...prev,
                        [key]: key === 'port' ? Number(e.target.value) : e.target.value,
                      }))
                    }
                  />
                </div>
              ))}
              <div className="flex gap-2">
                <Button type="button" variant="outline" onClick={() => void testConnection()}>
                  Test connection
                </Button>
                <Button type="button" onClick={() => setStep(2)}>
                  Continue
                </Button>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="space-y-2">
                <Label>Name</Label>
                <Input
                  value={app.name}
                  onChange={(e) => setApp({ ...app, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>URL</Label>
                <Input value={app.url} onChange={(e) => setApp({ ...app, url: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Timezone</Label>
                <Input
                  value={app.timezone}
                  onChange={(e) => setApp({ ...app, timezone: e.target.value })}
                />
              </div>
              <Button onClick={() => setStep(3)}>Continue</Button>
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="space-y-2">
                <Label>Name</Label>
                <Input
                  value={admin.name}
                  onChange={(e) => setAdmin({ ...admin, name: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Email</Label>
                <Input
                  type="email"
                  value={admin.email}
                  onChange={(e) => setAdmin({ ...admin, email: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Password</Label>
                <Input
                  type="password"
                  value={admin.password}
                  onChange={(e) => setAdmin({ ...admin, password: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Confirm</Label>
                <Input
                  type="password"
                  value={admin.passwordConfirm}
                  onChange={(e) => setAdmin({ ...admin, passwordConfirm: e.target.value })}
                />
              </div>
              <Button onClick={() => void complete()}>Install</Button>
            </>
          ) : null}

          {message ? <p className="text-sm text-muted-foreground">{message}</p> : null}
        </CardContent>
      </Card>
    </div>
  )
}
