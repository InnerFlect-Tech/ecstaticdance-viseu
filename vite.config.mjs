import { resolve, dirname } from 'node:path'
import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'

const __dirname = dirname(fileURLToPath(import.meta.url))

/** Dev: GET /faq → ./faq.html (same idea as nginx try_files … $uri.html). */
function extensionlessHtmlPages() {
  return {
    name: 'extensionless-html-pages',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.method !== 'GET' && req.method !== 'HEAD') return next()
        const raw = req.url || '/'
        const pathname = raw.split('?')[0] || ''
        if (pathname.includes('.')) return next()
        if (pathname === '/' || pathname === '') return next()
        if (
          pathname.startsWith('/node_modules/')
          || pathname.startsWith('/@vite/')
          || pathname.startsWith('/@fs/')
          || pathname.startsWith('/api')
        )
          return next()
        const rel = pathname.startsWith('/') ? pathname.slice(1) : pathname
        const candidate = resolve(__dirname, `${rel}.html`)
        if (!existsSync(candidate)) return next()

        const q = raw.includes('?') ? raw.slice(raw.indexOf('?')) : ''
        req.url = `${pathname}.html${q}`
        next()
      })
    },
  }
}

export default defineConfig({
  root: __dirname,
  base: '/',

  plugins: [extensionlessHtmlPages()],

  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index:        resolve(__dirname, 'index.html'),
        sobre:        resolve(__dirname, 'sobre.html'),
        eventos:      resolve(__dirname, 'eventos.html'),
        galeria:      resolve(__dirname, 'galeria.html'),
        faq:          resolve(__dirname, 'faq.html'),
        contacto:     resolve(__dirname, 'contacto.html'),
        bilhetes:     resolve(__dirname, 'bilhetes.html'),
        ticket:       resolve(__dirname, 'ticket.html'),
        links:        resolve(__dirname, 'links.html'),
        confirmacao:  resolve(__dirname, 'confirmacao.html'),
        cancelamento: resolve(__dirname, 'cancelamento.html'),
      },
    },
  },

  server: {
    port: 5173,
    open: true,
    // PHP dev: `npm run dev:local` inicia Vite + PHP:8080; ou manualmente: `php -S 127.0.0.1:8080 -t server` com `npm run dev`
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },

  preview: {
    allowedHosts: [
      'ecstaticdanceviseu.pt',
      '.innerflect.tech',
    ],
  },
})
