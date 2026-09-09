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
import styles from './DatePickerField.module.css'

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
        <Group id={id} className={cn(controlFieldClass, controlHugClass, styles.group, className)}>
          <DateInput className={styles.dateInput}>
            {(segment) => <DateSegment segment={segment} className={styles.segment} />}
          </DateInput>
          {calendarValue && !disabled ? (
            <Button
              slot={null}
              className={styles.iconButton}
              aria-label={t('common.clear')}
              onPress={() => onChange(null)}
            >
              <X className={styles.icon} aria-hidden />
            </Button>
          ) : null}
          <Button className={styles.iconButton}>
            <CalendarIcon className={styles.icon} aria-hidden />
          </Button>
        </Group>
        <Popover placement="bottom start" offset={4} className={styles.popover}>
          <Dialog className={styles.dialog}>
            <Calendar>
              <div className={styles.calendarNav}>
                <Button slot="previous" className={styles.iconButton}>
                  <ChevronLeft className={styles.navIcon} aria-hidden />
                </Button>
                <Heading className={styles.heading} />
                <Button slot="next" className={styles.iconButton}>
                  <ChevronRight className={styles.navIcon} aria-hidden />
                </Button>
              </div>
              <CalendarGrid className={styles.grid}>
                <CalendarGridHeader>
                  {(day) => (
                    <CalendarHeaderCell className={styles.headerCell}>{day}</CalendarHeaderCell>
                  )}
                </CalendarGridHeader>
                <CalendarGridBody>
                  {(date) => <CalendarCell date={date} className={styles.cell} />}
                </CalendarGridBody>
              </CalendarGrid>
            </Calendar>
          </Dialog>
        </Popover>
      </DatePicker>
    </I18nProvider>
  )
}
