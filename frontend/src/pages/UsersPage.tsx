import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useI18n } from '@/i18n'
import { api, getToken } from '@/lib/api'
import type { AuthUser } from '@/types/system'

interface AdminUser {
  id: number
  name: string
  email: string
  status: 'active' | 'disabled'
  lastLoginAt: string | null
  createdAt: string | null
  updatedAt: string | null
}

export function UsersPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const me = useQuery({
    queryKey: ['auth-me', getToken()],
    queryFn: () => api<AuthUser>('/admin/api/auth/me'),
  })

  const users = useQuery({
    queryKey: ['admin-users'],
    queryFn: () => api<AdminUser[]>('/admin/api/users'),
  })

  const create = useMutation({
    mutationFn: () =>
      api<AdminUser>('/admin/api/users', {
        method: 'POST',
        body: JSON.stringify({ name, email, password, status: 'active' }),
      }),
    onSuccess: () => {
      setOpen(false)
      resetForm()
      void queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: (err) => setError(err instanceof Error ? err.message : t('common.createFailed')),
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
    setError(null)
  }

  const currentId = me.data?.id

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t('users.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('users.subtitle')}</p>
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
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.name')}</TableHead>
                  <TableHead>{t('users.email')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(users.data ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4} className="text-muted-foreground">
                      {t('users.empty')}
                    </TableCell>
                  </TableRow>
                ) : (
                  (users.data ?? []).map((user) => {
                    const isSelf = currentId === user.id
                    return (
                      <TableRow key={user.id}>
                        <TableCell className="font-medium">{user.name}</TableCell>
                        <TableCell>{user.email}</TableCell>
                        <TableCell>
                          <Badge variant={user.status === 'active' ? 'default' : 'secondary'}>
                            {user.status === 'active' ? t('users.active') : t('users.disabled')}
                          </Badge>
                        </TableCell>
                        <TableCell className="space-x-2 text-right">
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={toggleStatus.isPending}
                            onClick={() => toggleStatus.mutate(user)}
                          >
                            {user.status === 'active' ? t('users.disable') : t('users.enable')}
                          </Button>
                          <Button
                            variant="destructive"
                            size="sm"
                            disabled={isSelf || remove.isPending}
                            onClick={() => {
                              if (window.confirm(t('users.deleteConfirm', { name: user.name }))) {
                                remove.mutate(user.id)
                              }
                            }}
                          >
                            {t('common.delete')}
                          </Button>
                        </TableCell>
                      </TableRow>
                    )
                  })
                )}
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
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="user-name">{t('common.name')}</Label>
              <Input
                id="user-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('users.placeholderName')}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="user-email">{t('users.email')}</Label>
              <Input
                id="user-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="admin@example.com"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="user-password">{t('users.password')}</Label>
              <Input
                id="user-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder={t('users.placeholderPassword')}
              />
            </div>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <Button
              className="w-full"
              disabled={create.isPending || !name || !email || password.length < 8}
              onClick={() => create.mutate()}
            >
              {create.isPending ? t('common.saving') : t('users.create')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
