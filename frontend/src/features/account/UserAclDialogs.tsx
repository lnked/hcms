import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import {
  ADMIN_SECTIONS,
  RESOURCE_TABS,
  type AdminSection,
  type ResourceTab,
} from '@/lib/rbac'
import type { Resource } from '@/types/resource'

export interface UserAclPayload {
  aclEnabled: boolean
  sections: string[]
  resources: Array<{
    resourceId: number
    canRead: boolean
    canCreate: boolean
    canUpdate: boolean
    canDelete: boolean
    tabs: string[]
  }>
}

type GrantDraft = {
  resourceId: number | ''
  canRead: boolean
  canCreate: boolean
  canUpdate: boolean
  canDelete: boolean
  tabs: ResourceTab[]
}

function emptyGrant(): GrantDraft {
  return {
    resourceId: '',
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    tabs: [...RESOURCE_TABS],
  }
}

function createOnlyGrant(base: GrantDraft): GrantDraft {
  return {
    ...base,
    canRead: true,
    canCreate: true,
    canUpdate: false,
    canDelete: false,
  }
}

function fullGrant(base: GrantDraft): GrantDraft {
  return {
    ...base,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
  }
}

export function UserPermissionsDialog({
  userId,
  userName,
  open,
  onOpenChange,
  onSaved,
}: {
  userId: number | null
  userName: string
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved: () => void
}) {
  const { t } = useI18n()
  const [aclEnabled, setAclEnabled] = useState(false)
  const [sections, setSections] = useState<AdminSection[]>([])
  const [grants, setGrants] = useState<GrantDraft[]>([])
  const [error, setError] = useState<string | null>(null)

  const resources = useQuery({
    queryKey: ['resources'],
    queryFn: () => api<Resource[]>('/admin/api/resources'),
    enabled: open,
  })

  const acl = useQuery({
    queryKey: ['user-acl', userId],
    queryFn: () => api<UserAclPayload>(`/admin/api/users/${userId}/acl`),
    enabled: open && userId !== null,
  })

  useEffect(() => {
    if (!acl.data) return
    setAclEnabled(acl.data.aclEnabled)
    setSections(acl.data.sections.filter((s): s is AdminSection => ADMIN_SECTIONS.includes(s as AdminSection)))
    setGrants(
      acl.data.resources.map((g) => ({
        resourceId: g.resourceId,
        canRead: g.canRead,
        canCreate: g.canCreate,
        canUpdate: g.canUpdate,
        canDelete: g.canDelete,
        tabs: g.tabs.filter((tab): tab is ResourceTab =>
          RESOURCE_TABS.includes(tab as ResourceTab),
        ),
      })),
    )
    setError(null)
  }, [acl.data])

  const save = useMutation({
    mutationFn: () => {
      if (userId === null) throw new Error('No user')
      const body: UserAclPayload = {
        aclEnabled,
        sections,
        resources: grants
          .filter((g) => typeof g.resourceId === 'number' && g.resourceId > 0)
          .map((g) => ({
            resourceId: g.resourceId as number,
            canRead: g.canRead,
            canCreate: g.canCreate,
            canUpdate: g.canUpdate,
            canDelete: g.canDelete,
            tabs: g.tabs,
          })),
      }
      return api<UserAclPayload>(`/admin/api/users/${userId}/acl`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
    },
    onSuccess: () => {
      onSaved()
      onOpenChange(false)
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  function toggleSection(section: AdminSection) {
    setSections((prev) =>
      prev.includes(section) ? prev.filter((s) => s !== section) : [...prev, section],
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{t('users.acl.title')}</DialogTitle>
          <DialogDescription>{t('users.acl.hint', { name: userName })}</DialogDescription>
        </DialogHeader>

        {acl.isLoading ? (
          <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
        ) : (
          <div className="space-y-5">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={aclEnabled}
                onChange={(e) => setAclEnabled(e.target.checked)}
              />
              {t('users.acl.enabled')}
            </label>

            {aclEnabled ? (
              <>
                <div className="space-y-2">
                  <Label>{t('users.acl.sections')}</Label>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {ADMIN_SECTIONS.filter((s) => s !== 'account').map((section) => (
                      <label key={section} className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={sections.includes(section)}
                          onChange={() => toggleSection(section)}
                        />
                        {t(`users.acl.section.${section}`)}
                      </label>
                    ))}
                  </div>
                </div>

                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <Label>{t('users.acl.resources')}</Label>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setGrants((g) => [...g, emptyGrant()])}
                    >
                      {t('users.acl.addResource')}
                    </Button>
                  </div>
                  {grants.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('users.acl.noResources')}</p>
                  ) : null}
                  {grants.map((grant, index) => (
                    <div key={index} className="space-y-2 rounded-md border p-3">
                      <div className="flex flex-wrap items-center gap-2">
                        <Select
                          containerClassName="min-w-[12rem] flex-1"
                          value={grant.resourceId === '' ? '' : String(grant.resourceId)}
                          onChange={(e) => {
                            const value = e.target.value
                            setGrants((rows) =>
                              rows.map((row, i) =>
                                i === index
                                  ? { ...row, resourceId: value === '' ? '' : Number(value) }
                                  : row,
                              ),
                            )
                          }}
                        >
                          <option value="">{t('users.acl.pickResource')}</option>
                          {(resources.data ?? []).map((r) => (
                            <option key={r.id} value={r.id}>
                              {r.label} ({r.slug})
                            </option>
                          ))}
                        </Select>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() =>
                            setGrants((rows) =>
                              rows.map((row, i) => (i === index ? createOnlyGrant(row) : row)),
                            )
                          }
                        >
                          {t('users.acl.presetCreate')}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() =>
                            setGrants((rows) =>
                              rows.map((row, i) => (i === index ? fullGrant(row) : row)),
                            )
                          }
                        >
                          {t('users.acl.presetFull')}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => setGrants((rows) => rows.filter((_, i) => i !== index))}
                        >
                          {t('common.delete')}
                        </Button>
                      </div>
                      <div className="flex flex-wrap gap-3 text-sm">
                        {(
                          [
                            ['canRead', 'tokens.canRead'],
                            ['canCreate', 'tokens.canCreate'],
                            ['canUpdate', 'tokens.canUpdate'],
                            ['canDelete', 'tokens.canDelete'],
                          ] as const
                        ).map(([key, labelKey]) => (
                          <label key={key} className="flex items-center gap-1.5">
                            <input
                              type="checkbox"
                              checked={grant[key]}
                              onChange={(e) => {
                                setGrants((rows) =>
                                  rows.map((row, i) =>
                                    i === index ? { ...row, [key]: e.target.checked } : row,
                                  ),
                                )
                              }}
                            />
                            {t(labelKey)}
                          </label>
                        ))}
                      </div>
                      <div className="space-y-1">
                        <p className="text-xs text-muted-foreground">{t('users.acl.tabs')}</p>
                        <div className="flex flex-wrap gap-3 text-sm">
                          {RESOURCE_TABS.map((tab) => (
                            <label key={tab} className="flex items-center gap-1.5">
                              <input
                                type="checkbox"
                                checked={grant.tabs.includes(tab)}
                                onChange={(e) => {
                                  setGrants((rows) =>
                                    rows.map((row, i) => {
                                      if (i !== index) return row
                                      const tabs = e.target.checked
                                        ? [...row.tabs, tab]
                                        : row.tabs.filter((x) => x !== tab)
                                      return { ...row, tabs }
                                    }),
                                  )
                                }}
                              />
                              {t(`resources.tab.${tab}`)}
                            </label>
                          ))}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </>
            ) : null}

            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => onOpenChange(false)}>
                {t('common.cancel')}
              </Button>
              <Button disabled={save.isPending || acl.isLoading} onClick={() => save.mutate()}>
                {save.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function UserResetPasswordDialog({
  userId,
  userName,
  open,
  onOpenChange,
}: {
  userId: number | null
  userName: string
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useI18n()
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setPassword('')
      setError(null)
    }
  }, [open])

  const save = useMutation({
    mutationFn: () => {
      if (userId === null) throw new Error('No user')
      return api(`/admin/api/users/${userId}`, {
        method: 'PATCH',
        body: JSON.stringify({ password }),
      })
    },
    onSuccess: () => onOpenChange(false),
    onError: (err) => setError(err instanceof Error ? err.message : t('common.saveFailed')),
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.resetPassword')}</DialogTitle>
          <DialogDescription>{t('users.resetPasswordHint', { name: userName })}</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="reset-password">{t('users.password')}</Label>
            <Input
              id="reset-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder={t('users.placeholderPassword')}
            />
          </div>
          {error ? <p className="text-sm text-destructive">{error}</p> : null}
          <Button
            className="w-full"
            disabled={save.isPending || password.length < 8}
            onClick={() => save.mutate()}
          >
            {save.isPending ? t('common.saving') : t('users.resetPassword')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
