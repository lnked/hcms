import { Calendar as CalendarIcon, ChevronLeft, ChevronRight, X } from 'lucide-react'
import {
  Button,
  Calendar,
  CalendarCell,
  CalendarGrid,
  CalendarGridBody,
  CalendarGridHeader,
  CalendarHeaderCell,
  DateInput,
  DatePicker,
  DateSegment,
  Dialog,
  Group,
  Heading,
  I18nProvider,
  Popover,
} from 'react-aria-components'
import { controlFieldClass, controlHugClass } from '@/components/ui/control'
import { useI18n } from '@/i18n'
import { type DateGranularity, dateFieldLocale } from '@/lib/dateFormat'
import { fromCalendarValue, toCalendarValue } from '@/lib/dateValue'
import { cn } from '@/lib/utils'

export interface DatePickerFieldProps {
  id?: string
  /** Stored value: `YYYY-MM-DD` or `YYYY-MM-DD HH:mm:ss`. */
  value: unknown
  onChange: (value: string | null) => void
  /** Schema display pattern (moment-style tokens); drives segment order. */
  format?: string
  /** Smallest editable unit. */
  granularity?: DateGranularity
  disabled?: boolean
  /** Applied to the control shell. */
  className?: string
  'aria-label'?: string
}

const iconButtonClass =
  'grid size-6 shrink-0 place-items-center rounded-sm text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-accent-foreground data-[focus-visible]:ring-2 data-[focus-visible]:ring-ring'

const segmentClass =
  'rounded px-0.5 tabular-nums outline-none data-[placeholder]:text-muted-foreground data-[focused]:bg-primary data-[focused]:text-primary-foreground data-[type=literal]:px-0 data-[type=literal]:text-muted-foreground'

const cellClass =
  'grid size-8 cursor-pointer place-items-center rounded-md text-sm outline-none transition-colors data-[disabled]:cursor-default data-[disabled]:text-muted-foreground/40 data-[outside-month]:text-muted-foreground/40 data-[hovered]:bg-accent data-[hovered]:text-accent-foreground data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[focus-visible]:ring-2 data-[focus-visible]:ring-ring'

/**
 * Segmented date/datetime picker with a calendar popover.
 * Values stay wall-clock strings, so no timezone shift happens on round-trip.
 */
export function DatePickerField({
  id,
  value,
  onChange,
  format = '',
  granularity = 'day',
  disabled,
  className,
  'aria-label': ariaLabel,
}: DatePickerFieldProps) {
  const { locale, t } = useI18n()
  const calendarValue = toCalendarValue(value, granularity)

  return (
    <I18nProvider locale={dateFieldLocale(format, locale)}>
      <DatePicker
        value={calendarValue}
        granularity={granularity}
        hourCycle={24}
        shouldForceLeadingZeros
        isDisabled={disabled}
        aria-label={ariaLabel}
        onChange={(next) => onChange(fromCalendarValue(next, granularity))}
      >
        <Group
          id={id}
          className={cn(
            controlFieldClass,
            controlHugClass,
            'items-center gap-1 pr-1 data-[focus-within]:ring-2 data-[focus-within]:ring-ring',
            className,
          )}
        >
          <DateInput className="flex items-center whitespace-nowrap">
            {(segment) => <DateSegment segment={segment} className={segmentClass} />}
          </DateInput>
          {calendarValue && !disabled ? (
            <Button
              slot={null}
              className={iconButtonClass}
              aria-label={t('common.clear')}
              onPress={() => onChange(null)}
            >
              <X className="size-3.5" aria-hidden />
            </Button>
          ) : null}
          <Button className={iconButtonClass}>
            <CalendarIcon className="size-3.5" aria-hidden />
          </Button>
        </Group>
        <Popover
          placement="bottom start"
          offset={4}
          className="z-[60] rounded-md border bg-popover p-3 text-popover-foreground shadow-md"
        >
          <Dialog className="outline-none">
            <Calendar>
              <div className="mb-2 flex items-center justify-between gap-2">
                <Button slot="previous" className={iconButtonClass}>
                  <ChevronLeft className="size-4" aria-hidden />
                </Button>
                <Heading className="text-sm font-medium capitalize" />
                <Button slot="next" className={iconButtonClass}>
                  <ChevronRight className="size-4" aria-hidden />
                </Button>
              </div>
              <CalendarGrid className="border-collapse">
                <CalendarGridHeader>
                  {(day) => (
                    <CalendarHeaderCell className="size-8 text-xs font-normal capitalize text-muted-foreground">
                      {day}
                    </CalendarHeaderCell>
                  )}
                </CalendarGridHeader>
                <CalendarGridBody>
                  {(date) => <CalendarCell date={date} className={cellClass} />}
                </CalendarGridBody>
              </CalendarGrid>
            </Calendar>
          </Dialog>
        </Popover>
      </DatePicker>
    </I18nProvider>
  )
}
