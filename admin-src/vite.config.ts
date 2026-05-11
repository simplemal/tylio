import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

// Output goes into ../admin so the front controller (Apache DocumentRoot =
// project root) serves it directly: requests to `/admin/index.html` and
// `/admin/assets/*` resolve to real files on disk via the
// "skip rewrite if file exists" rule in `.htaccess`. The PHP `adminShell`
// route only fires for client-side SPA routes like `/admin/theme` where no
// matching file exists.
export default defineConfig({
  base: '/admin/',
  plugins: [vue()],
  build: {
    outDir: path.resolve(__dirname, '../admin'),
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'pinia'],
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8000',
      '/uploads': 'http://localhost:8000',
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
})
