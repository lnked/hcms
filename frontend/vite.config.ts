import path from 'node:path'
import optimizeLocales from '@react-aria/optimize-locales-plugin'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

const publicDir = process.env.CMS_PUBLIC_DIR || 'public'

/** Locales `dateFieldLocale()` can hand to React Aria; the rest are dropped from the bundle. */
const reactAriaLocales = ['en-GB', 'en-US', 'en-CA', 'ru-RU']

export default defineConfig({
  plugins: [react(), { ...optimizeLocales.vite({ locales: reactAriaLocales }), enforce: 'pre' }],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  base: '/admin/',
  build: {
    outDir: path.resolve(import.meta.dirname, `../${publicDir}/admin`),
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/admin/api': 'http://127.0.0.1:8080',
      '/install.php': 'http://127.0.0.1:8080',
      '/api': 'http://127.0.0.1:8080',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.ts',
    // Avoid undici/jsdom clone errors in forks workers on CI Node.
    pool: 'threads',
  },
})
