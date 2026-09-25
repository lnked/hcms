import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/queryKeys'
import { FIELD_TYPES, type FieldTypeDescriptor, type FieldTypeName } from '@/types/field'

export function useFieldTypes() {
  const fieldTypesQuery = useQuery({
    queryKey: queryKeys.fieldTypes,
    queryFn: () => api<FieldTypeDescriptor[] | string[]>('/admin/api/field-types'),
    staleTime: 60_000,
  })

  const fieldTypeDescriptors: FieldTypeDescriptor[] = (() => {
    const data = fieldTypesQuery.data
    if (!data || data.length === 0) {
      return FIELD_TYPES.map((name) => ({
        name,
        label: name,
        widget: 'text',
        defaultConfig: {},
        configSchema: {},
      }))
    }
    if (typeof data[0] === 'string') {
      return (data as string[]).map((name) => ({
        name,
        label: name,
        widget: 'text',
        defaultConfig: {},
        configSchema: {},
      }))
    }
    return data as FieldTypeDescriptor[]
  })()

  const fieldTypes: FieldTypeName[] = fieldTypeDescriptors.map((d) => d.name)
  const descriptorByName = new Map(fieldTypeDescriptors.map((d) => [d.name, d]))

  return {
    fieldTypesQuery,
    fieldTypeDescriptors,
    fieldTypes,
    descriptorByName,
  }
}
