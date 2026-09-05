import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  base: '/admin/',
  build: {
    outDir: path.resolve(import.meta.dirname, '../public/admin'),
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
  },
})
