# Ecstatic Dance Viseu

Site estático multi-página construído com **Vite**, servido por **Nginx** via **Docker**.

## Desenvolvimento local

```bash
npm install
npm run dev       # dev server em http://localhost:5173
npm run build     # produção em dist/
npm run preview   # preview do build em http://localhost:4173
```

## Deploy no Coolify (Hetzner)

### 1. Configurar o repositório

No Coolify, cria um novo recurso do tipo **Docker** e liga ao repositório Git.

- **Build Pack**: Dockerfile
- **Dockerfile path**: `./Dockerfile`
- **Porta exposta**: `80`
- **Health check path**: `/`

### 2. Variáveis de ambiente

Não existem variáveis obrigatórias em runtime. As variáveis de build são opcionais:

| Variável | Descrição | Exemplo |
|----------|-----------|---------|
| (nenhuma no estado actual) | — | — |

### 3. Formulário de contacto — Web3Forms

O formulário usa [Web3Forms](https://web3forms.com/) (gratuito, sem backend próprio).

**Passos:**

1. Registar em https://web3forms.com/ com o email `info@ecstaticdanceviseu.pt`.
2. Copiar a **Access Key** gerada.
3. Em `contacto.html`, substituir `YOUR_ACCESS_KEY` pela chave real:

```html
<input type="hidden" name="access_key" value="CHAVE_AQUI" />
```

4. Fazer o mesmo em todos os formulários de newsletter (`.newsletter-form`) nas páginas interiores.
5. Fazer commit e redeploy.

### 4. Domínio e HTTPS

O Coolify gere o certificado SSL via Let's Encrypt automaticamente. Configura o domínio `ecstaticdanceviseu.pt` no painel do Coolify (Domains → Add Domain).

### 5. Actualizar o sitemap e canónicos

Após confirmar o domínio final, verificar que os URLs em `public/sitemap.xml` e as meta tags `og:url` / `canonical` em cada HTML correspondem ao domínio real.

## Estrutura do projecto

```
├── index.html          ← Página principal
├── sobre.html
├── eventos.html
├── galeria.html
├── faq.html
├── contacto.html
├── css/
│   ├── styles.css      ← Estilos da home
│   └── pages.css       ← Estilos das páginas interiores
├── js/
│   ├── main.js         ← JS da home (módulo ES)
│   └── pages.js        ← JS das páginas interiores + formulários
├── public/
│   ├── robots.txt
│   ├── sitemap.xml
│   └── 404.html
├── vite.config.mjs     ← Config Vite MPA (6 entradas HTML)
├── Dockerfile          ← Build multi-stage Node → Nginx
├── nginx.conf          ← Nginx: gzip, cache, security headers
└── package.json
```

## Build Docker local (teste antes de deploy)

```bash
docker build -t ecstatic-dance-viseu .
docker run -p 8080:80 ecstatic-dance-viseu
# Abre http://localhost:8080
```
