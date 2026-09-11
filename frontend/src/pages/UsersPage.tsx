import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { Ban, CircleCheck, KeyRound, Shield, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { EmptyState } from '@/components/EmptyState'
import { FieldError } from '@/components/FieldError'
import { PasswordField } from '@/components/PasswordField'
import { TableSkeleton } from '@/components/skeletons'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Form } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { UserPermissionsDialog, UserResetPasswordDialog } from '@/features/account/UserAclDialogs'
import { useAcl } from '@/hooks/useAcl'
import { useI18n } from '@/i18n'
import { api } from '@/lib/api'
import { apiFieldErrors, clearFieldError, hasFieldError, type FieldErrors } from '@/lib/formErrors'
import { showSuccess } from '@/lib/toast'
import styles from './UsersPage.module.css'

interface AdminUser {
  id: number
  name: string
  email: string
  status: 'active' | 'disabled'
  role: 'owner' | 'admin' | 'editor' | 'viewer'
  aclEnabled?: boolean
  lastLoginAt: string | null
  createdAt: string | null
  updatedAt: string | null
}

const ROLES = ['owner', 'admin', 'editor', 'viewer'] as const

export function UsersPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { isOwner, user: meUser } = useAcl()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<(typeof ROLES)[number]>('admin')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [aclUser, setAclUser] = useState<AdminUser | null>(null)
  const [passwordUser, setPasswordUser] = useState<AdminUser | null>(null)

  const users = useQuery({
    queryKey: ['admin-users'],
    queryFn: () => api<AdminUser[]>('/admin/api/users'),
  })

  const create = useMutation({
    mutationFn: () =>
      api<AdminUser>('/admin/api/users', {
        method: 'POST',
        body: JSON.stringify({ name, email, password, status: 'active', role }),
      }),
    onSuccess: () => {
      showSuccess(t('common.saved'))
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: (err) => setFieldErrors(apiFieldErrors(err)),
  })

  const changeRole = useMutation({
    mutationFn: ({ id, role: next }: { id: number; role: (typeof ROLES)[number] }) =>
      api<AdminUser>(`/admin/api/users/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ role: next }),
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
  })

  const toggleStatus = useMutation({
    mutationFn: (user: AdminUser) =>
      api<AdminUser>(`/admin/api/users/${user.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          status: user.status === 'active' ? 'disabled' : 'active',
        }),
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api<void>(`/admin/api/users/${id}`, { method: 'DELETE' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
  })

  function resetForm() {
    setName('')
    setEmail('')
    setPassword('')
    setRole('admin')
    setFieldErrors({})
  }

  function roleLabel(roleValue: (typeof ROLES)[number]): string {
    switch (roleValue) {
      case 'owner':
        return t('users.role.owner')
      case 'admin':
        return t('users.role.admin')
      case 'editor':
        return t('users.role.editor')
      case 'viewer':
        return t('users.role.viewer')
    }
  }

  const currentId = meUser?.id

  return (
    <div className={clsx(styles.root)}>
      <div className={clsx(styles.pageHeader)}>
        <div>
          <h1 className={clsx(styles.title)}>{t('users.title')}</h1>
          <p className={clsx(styles.subtitle)}>{t('users.subtitle')}</p>
        </div>
        <Button
          onClick={() => {
            resetForm()
            setOpen(true)
          }}
        >
          {t('users.create')}
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('users.listTitle')}</CardTitle>
          <CardDescription>{t('users.listHint')}</CardDescription>
        </CardHeader>
        <CardContent>
          {users.isLoading ? (
            <TableSkeleton columns={4} rows={5} />
          ) : (users.data ?? []).length === 0 ? (
            <EmptyState title={t('users.empty')} />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('users.email')}</TableHead>
                  <TableHead>{t('users.role')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className={clsx(styles.alignRight)}>{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(users.data ?? []).map((user) => {
                  const isSelf = currentId === user.id
                  const canManageAcl = isOwner && user.role !== 'owner'
                  return (
                    <TableRow key={user.id}>
                      <TableCell className={clsx(styles.nameCell)}>
                        {user.name}
                        {user.aclEnabled ? (
                          <Badge variant="secondary" className={clsx(styles.aclBadge)}>
                            {t('users.acl.badge')}
                          </Badge>
                        ) : null}
                      </TableCell>
                      <TableCell>{user.email}</TableCell>
                      <TableCell>
                        <Select
                          containerClassName={clsx(styles.roleSelect)}
                          className={clsx(styles.roleSelectControl)}
                          value={user.role ?? 'admin'}
                          disabled={changeRole.isPending}
                          aria-label={t('users.role')}
                          onChange={(e) =>
                            changeRole.mutate({
                              id: user.id,
                              role: e.target.value as (typeof ROLES)[number],
                            })
                          }
                        >
                          {ROLES.map((r) => (
                            <option key={r} value={r}>
                              {roleLabel(r)}
                            </option>
                          ))}
                        </Select>
                      </TableCell>
                      <TableCell>
                        <Badge variant={user.status === 'active' ? 'default' : 'secondary'}>
                          {user.status === 'active' ? t('users.active') : t('users.disabled')}
                        </Badge>
                      </TableCell>
                      <TableCell className={clsx(styles.alignRight)}>
                        <div className={clsx(styles.rowActions)}>
                          {canManageAcl ? (
                            <>
                              <Button
                                size="icon"
                                variant="ghost"
                                aria-label={t('users.resetPassword')}
                                title={t('users.resetPassword')}
                                onClick={() => setPasswordUser(user)}
                              >
                                <KeyRound className={clsx(styles.icon)} />
                              </Button>
                              <Button
                                size="icon"
                                variant="ghost"
                                aria-label={t('users.acl.title')}
                                title={t('users.acl.title')}
                                onClick={() => setAclUser(user)}
                              >
                                <Shield className={clsx(styles.icon)} />
                              </Button>
                            </>
                          ) : null}
                          <Button
                            size="icon"
                            variant="ghost"
                            disabled={toggleStatus.isPending}
                            aria-label={
                              user.status === 'active' ? t('users.disable') : t('users.enable')
                            }
                            title={
                              user.status === 'active' ? t('users.disable') : t('users.enable')
                            }
                            onClick={() => toggleStatus.mutate(user)}
                          >
                            {user.status === 'active' ? (
                              <Ban className={clsx(styles.icon)} />
                            ) : (
                              <CircleCheck className={clsx(styles.icon)} />
                            )}
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            disabled={isSelf || remove.isPending}
                            aria-label={t('common.delete')}
                            title={t('common.delete')}
                            onClick={() => {
                              if (window.confirm(t('users.deleteConfirm', { name: user.name }))) {
                                remove.mutate(user.id)
                              }
                            }}
                          >
                            <Trash2 className={clsx(styles.iconDanger)} />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('users.createTitle')}</DialogTitle>
            <DialogDescription>{t('users.createHint')}</DialogDescription>
          </DialogHeader>
          <Form className={clsx(styles.form)} onSubmit={() => create.mutate()}>
            <div className={clsx(styles.field)}>
              <Label htmlFor="user-name">{t('common.name')}</Label>
              <Input
                id="user-name"
                value={name}
                aria-invalid={hasFieldError(fieldErrors, 'name') || undefined}
                onChange={(e) => {
                  setName(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'name'))
                }}
                placeholder={t('users.placeholderName')}
              />
              <FieldError messages={fieldErrors.name} />
            </div>
            <div className={clsx(styles.field)}>
              <Label htmlFor="user-email">{t('users.email')}</Label>
              <Input
                id="user-email"
                type="email"
                value={email}
                aria-invalid={hasFieldError(fieldErrors, 'email') || undefined}
                onChange={(e) => {
                  setEmail(e.target.value)
                  setFieldErrors((prev) => clearFieldError(prev, 'email'))
                }}
                placeholder="admin@example.com"
              />
              <FieldError messages={fieldErrors.email} />
            </div>
            <PasswordField
              id="user-password"
              label={t('users.password')}
              value={password}
              aria-invalid={hasFieldError(fieldErrors, 'password') || undefined}
              onChange={(value) => {
                setPassword(value)
                setFieldErrors((prev) => clearFieldError(prev, 'password'))
              }}
              placeholder={t('users.placeholderPassword')}
              allowGenerate
              showCopy
            />
            <FieldError messages={fieldErrors.password} />
            <div className={clsx(styles.field)}>
              <Label htmlFor="user-role">{t('users.role')}</Label>
              <Select
                id="user-role"
                value={role}
                aria-invalid={hasFieldError(fieldErrors, 'role') || undefined}
                onChange={(e) => {
                  setRole(e.target.value as (typeof ROLES)[number])
                  setFieldErrors((prev) => clearFieldError(prev, 'role'))
                }}
              >
                {ROLES.map((r) => (
                  <option key={r} value={r}>
                    {roleLabel(r)}
                  </option>
                ))}
              </Select>
              <FieldError messages={fieldErrors.role} />
            </div>
            <Button
              type="submit"
              className={clsx(styles.fullWidth)}
              disabled={create.isPending || !name || !email || password.length < 8}
            >
              {create.isPending ? t('common.saving') : t('users.create')}
            </Button>
          </Form>
        </DialogContent>
      </Dialog>

      <UserPermissionsDialog
        userId={aclUser?.id ?? null}
        userName={aclUser?.name ?? ''}
        open={aclUser !== null}
        onOpenChange={(next) => {
          if (!next) setAclUser(null)
        }}
        onSaved={() => void queryClient.invalidateQueries({ queryKey: ['admin-users'] })}
      />
      <UserResetPasswordDialog
        userId={passwordUser?.id ?? null}
        userName={passwordUser?.name ?? ''}
        open={passwordUser !== null}
        onOpenChange={(next) => {
          if (!next) setPasswordUser(null)
        }}
      />
    </div>
  )
}
