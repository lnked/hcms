import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function TokensPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>API Tokens</CardTitle>
        <CardDescription>Create and revoke Bearer tokens in Phase 8.</CardDescription>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">No tokens yet.</CardContent>
    </Card>
  )
}
