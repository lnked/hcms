import type { MessageKey } from './en'

/**
 * Arabic UI catalog (v1): sparse — missing keys fall back to English.
 * Used primarily to drive `html[dir=rtl]` for layout QA.
 */
export const ar: Partial<Record<MessageKey, string>> = {
  'locale.en': 'English',
  'locale.ru': 'Русский',
  'locale.ar': 'العربية',
  'common.language': 'اللغة',
  'nav.dashboard': 'لوحة التحكم',
  'nav.resources': 'الموارد',
  'nav.media': 'الوسائط',
  'nav.system': 'النظام',
  'nav.account': 'الحساب',
  'nav.logout': 'تسجيل الخروج',
  'nav.docs': 'Swagger',
  'nav.graphql': 'GraphQL',
  'nav.documentation': 'التوثيق',
  'nav.expand': 'توسيع الشريط',
  'nav.collapse': 'طي الشريط',
  'nav.openMenu': 'فتح القائمة',
  'nav.closeMenu': 'إغلاق القائمة',
  'entries.noLocales': 'لا توجد لغات مهيأة',
  'entries.configureLocales': 'تهيئة اللغات',
  'entries.createLocale': 'اللغة',
  'resources.settings.localizationHint':
    'هيّئ اللغات في الترجمة أولاً. بعد التفعيل نفّذ Migrate لإضافة أعمدة locale.',
}
