import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { PasswordField } from '@/components/PasswordField'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { showSuccess } from '@/lib/toast'
import { ADMIN_SECTIONS, RESOURCE_TABS, type AdminSection, type ResourceTab } from '@/lib/rbac'
import type { Resource } from '@/types/resource'
import styles from './UserAclDialogs.module.css'

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
    tabs: ['overview', 'data'],
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

function parseAcl(data: UserAclPayload): {
  aclEnabled: boolean
  sections: AdminSection[]
  grants: GrantDraft[]
} {
  return {
    aclEnabled: data.aclEnabled,
    sections: data.sections.filter((s): s is AdminSection =>
      ADMIN_SECTIONS.includes(s as AdminSection),
    ),
    grants: data.resources.map((g) => ({
      resourceId: g.resourceId,
      canRead: g.canRead,
      canCreate: g.canCreate,
      canUpdate: g.canUpdate,
      canDelete: g.canDelete,
      tabs: g.tabs.filter((tab): tab is ResourceTab => RESOURCE_TABS.includes(tab as ResourceTab)),
    })),
  }
}

function UserPermissionsForm({
  userId,
  initial,
  resources,
  onClose,
  onSaved,
}: {
  userId: number
  initial: UserAclPayload
  resources: Resource[]
  onClose: () => void
  onSaved: () => void
}) {
  const { t } = useI18n()
  const parsed = parseAcl(initial)
  const [aclEnabled, setAclEnabled] = useState(parsed.aclEnabled)
  const [sections, setSections] = useState<AdminSection[]>(parsed.sections)
  const [grants, setGrants] = useState<GrantDraft[]>(parsed.grants)

  const save = useMutation({
    mutationFn: () => {
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
      showSuccess(t('common.saved'))
      onSaved()
      onClose()
    },
  })

  const selectableSections = ADMIN_SECTIONS.filter((s) => s !== 'account')
  const allSectionsSelected =
    selectableSections.length > 0 && selectableSections.every((s) => sections.includes(s))
  const someSectionsSelected = selectableSections.some((s) => sections.includes(s))
  const resourcesSectionEnabled = sections.includes('resources')

  function toggleSection(section: AdminSection) {
    setSections((prev) =>
      prev.includes(section) ? prev.filter((s) => s !== section) : [...prev, section],
    )
  }

  function toggleAllSections(checked: boolean) {
    setSections(checked ? [...selectableSections] : [])
  }

  return (
    <div className={clsx(styles.root)}>
      <label className={clsx(styles.checkLabel)}>
        <input
          type="checkbox"
          checked={aclEnabled}
          onChange={(e) => setAclEnabled(e.target.checked)}
        />
        {t('users.acl.enabled')}
      </label>

      {aclEnabled ? (
        <>
          <div className={clsx(styles.stack)}>
            <Label>{t('users.acl.sections')}</Label>
            <label className={clsx(styles.checkLabelMedium)}>
              <input
                type="checkbox"
                checked={allSectionsSelected}
                ref={(el) => {
                  if (el) el.indeterminate = someSectionsSelected && !allSectionsSelected
                }}
                onChange={(e) => toggleAllSections(e.target.checked)}
              />
              {t('users.acl.allSections')}
            </label>
            <div className={clsx(styles.sectionGrid)}>
              {selectableSections.map((section) => (
                <label key={section} className={clsx(styles.checkLabel)}>
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

          <div className={clsx(styles.stack)}>
            <div className={clsx(styles.resourcesHeader)}>
              <Label>{t('users.acl.resources')}</Label>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={!resourcesSectionEnabled}
                title={
                  resourcesSectionEnabled ? undefined : t('users.acl.addResourceRequiresSection')
                }
                onClick={() => setGrants((g) => [...g, emptyGrant()])}
              >
                {t('users.acl.addResource')}
              </Button>
            </div>
            {grants.length === 0 ? (
              <p className={clsx(styles.muted)}>{t('users.acl.noResources')}</p>
            ) : null}
            {grants.map((grant, index) => (
              <div key={index} className={clsx(styles.grantCard)}>
                <div className={clsx(styles.grantToolbar)}>
                  <Select
                    containerClassName={clsx(styles.selectGrow)}
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
                    {resources.map((r) => (
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
                <div className={clsx(styles.flagRow)}>
                  {(
                    [
                      ['canRead', 'tokens.canRead'],
                      ['canCreate', 'tokens.canCreate'],
                      ['canUpdate', 'tokens.canUpdate'],
                      ['canDelete', 'tokens.canDelete'],
                    ] as const
                  ).map(([key, labelKey]) => (
                    <label key={key} className={clsx(styles.flagLabel)}>
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
                <div className={clsx(styles.stackXs)}>
                  <p className={clsx(styles.mutedXs)}>{t('users.acl.tabs')}</p>
                  <div className={clsx(styles.flagRow)}>
                    {RESOURCE_TABS.map((tab) => (
                      <label key={tab} className={clsx(styles.flagLabel)}>
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
      <div className={clsx(styles.actions)}>
        <Button variant="outline" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </div>
  )
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className={clsx(styles.dialogWide)}>
        <DialogHeader>
          <DialogTitle>{t('users.acl.title')}</DialogTitle>
          <DialogDescription>{t('users.acl.hint', { name: userName })}</DialogDescription>
        </DialogHeader>

        {acl.isLoading || userId === null ? (
          <p className={clsx(styles.muted)}>{t('common.loading')}</p>
        ) : acl.data ? (
          <UserPermissionsForm
            key={`${userId}-${acl.dataUpdatedAt}`}
            userId={userId}
            initial={acl.data}
            resources={resources.data ?? []}
            onClose={() => onOpenChange(false)}
            onSaved={onSaved}
          />
        ) : (
          <p className={clsx(styles.error)}>{t('common.loadError')}</p>
        )}
      </DialogContent>
    </Dialog>
  )
}

function ResetPasswordForm({ userId, onClose }: { userId: number; onClose: () => void }) {
  const { t } = useI18n()
  const [password, setPassword] = useState('')

  const save = useMutation({
    mutationFn: () =>
      api(`/admin/api/users/${userId}`, {
        method: 'PATCH',
        body: JSON.stringify({ password }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      onClose()
    },
  })

  return (
    <div className={clsx(styles.resetRoot)}>
      <PasswordField
        id="reset-password"
        label={t('users.password')}
        value={password}
        onChange={setPassword}
        placeholder={t('users.placeholderPassword')}
        allowGenerate
        showCopy
      />
      <Button
        className={clsx(styles.fullWidth)}
        disabled={save.isPending || password.length < 8}
        onClick={() => save.mutate()}
      >
        {save.isPending ? t('common.saving') : t('users.resetPassword')}
      </Button>
    </div>
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.resetPassword')}</DialogTitle>
          <DialogDescription>{t('users.resetPasswordHint', { name: userName })}</DialogDescription>
        </DialogHeader>
        {open && userId !== null ? (
          <ResetPasswordForm key={userId} userId={userId} onClose={() => onOpenChange(false)} />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
