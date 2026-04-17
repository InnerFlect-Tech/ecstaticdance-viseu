import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'

const __dirname = dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  root: __dirname,
  base: '/',

  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index:    resolve(__dirname, 'index.html'),
        sobre:    resolve(__dirname, 'sobre.html'),
        eventos:  resolve(__dirname, 'eventos.html'),
        galeria:  resolve(__dirname, 'galeria.html'),
        faq:      resolve(__dirname, 'faq.html'),
        contacto: resolve(__dirname, 'contacto.html'),
      },
    },
  },

  server: {
    port: 5173,
    open: true,
  },
})
