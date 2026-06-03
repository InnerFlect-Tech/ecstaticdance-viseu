# Ecstatic Dance Viseu

Site estático multi-página construído com **Vite**, com sistema de bilhetes via **PHP + MySQL** para cPanel.

## Desenvolvimento local

```bash
npm install
npm run dev       # só Vite em http://localhost:5173 (HTML/CSS/JS)
npm run build     # produção em dist/
npm run preview   # preview do build em http://localhost:4173
npm run start     # (Coolify/Nixpacks) vite preview + PHP em 127.0.0.1:8080–8099 para /api (ver scripts/start-preview-with-php.sh)
```

### API PHP (`/api/*`, formulário em `/links`)

O proxy do Vite em `vite.config.mjs` envia `/api/*` para `http://127.0.0.1:${EDV_PHP_API_PORT}` (variável definida pelo `dev:local`; omissão = **8080**).

- **`npm run dev`** só sobe o front — sem PHP a responder no proxy, aparecem `EPIPE` / `ECONNRESET` e o formulário de links falha.

Para gravar pedidos **localmente**:

1. Instala **PHP 8+** com **mbstring**. Para modo **SQLite** (recomendado em dev), também `php-sqlite3`:  
   `sudo apt update && sudo apt install -y php-cli php-sqlite3 php-mbstring`
2. **`npm run dev:local`** — sobe PHP built‑in na **primeira porta livre entre 8080–8099** (resolve «Address already in use» na 8080), exporta `EDV_PHP_API_PORT`, e arranca o Vite; cria `server/api/config.php` a partir do exemplo se faltar.
3. Em **`server/api/config.php`:**  
   - **SQLite:** `LINK_USE_SQLITE=true`, `LINK_USE_JSON=false` (exemplo típico).  
   - **Sem extensão sqlite:** `LINK_USE_SQLITE=false`, `LINK_USE_JSON=true` — dados em `server/data/link-registrations-dev.json` (**só desenvolvimento; nunca em produção**).

**Produção:** [Coolify + Nixpacks](docs/COOLIFY.md) com **SQLite** em volumes (`environment.coolify.env`). **cPanel** usa **MySQL** — [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).
---

## Sistema de bilhetes

O sistema de bilhetes está implementado como camada PHP + MySQL no cPanel, separada do build Vite.

### Funcionalidades

- Bilhetes pagos com **sliding scale** (€25–€80) via **Stripe Checkout**
- Pagamento por **MB Way**, **Multibanco** e cartão
- Bilhetes gratuitos com reserva por email
- Email automático com **QR code** ao participante
- Painel de administração com **scanner QR** para check-in na porta
- Exportação CSV de inscrições
- Reconciliação automática via cron job

### Documentação

| Doc | Descrição |
|---|---|
| [docs/COOLIFY.md](docs/COOLIFY.md) | **Produção Hetzner** — Nixpacks, volumes, SQLite, env |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Deploy no cPanel (MySQL) |
| [docs/DATABASE.md](docs/DATABASE.md) | Esquema e queries (MySQL + notas Coolify SQLite) |
| [docs/STRIPE.md](docs/STRIPE.md) | Configuração Stripe + MB Way + Multibanco |
| [docs/ADMIN.md](docs/ADMIN.md) | Como usar o painel de admin e scanner QR |

### Novas páginas

| Página | URL |
|---|---|
| Reserva de bilhetes | `/bilhetes.html` |
| Confirmação / QR code | `/confirmacao.html` |
| Pagamento cancelado | `/cancelamento.html` |
| Painel de admin | **`https://ecstaticdanceviseu.pt/admin/`** — login em **`/admin/login.php`** ([docs/ADMIN.md](docs/ADMIN.md)). Coolify: PHP no contentor (Nixpacks: Vite faz proxy para `php -S`). cPanel: PHP da hospedagem. |

---

## Deploy no Coolify (Hetzner) — produção actual

Guia completo: **[docs/COOLIFY.md](docs/COOLIFY.md)** · Env copiável: **`environment.coolify.env`**

### Nixpacks (modo actual no painel)

Coolify v4 detecta **Node** + `nixpacks.toml` → `npm run build` + `npm run start`:

- **Vite preview** em `$PORT` (Coolify define) serve `dist/`
- **PHP** em `127.0.0.1:8080` (`-t server/`) para `/api`, `/admin`, `/uploads`
- **SQLite** em volumes: `/app/server/data` + `/app/server/uploads`
- **Não** uses paths `/var/www/edv-server/...` nas env vars (isso é só para o Dockerfile)

| Coolify | Valor |
|---------|--------|
| Build Pack | Nixpacks / Automatic |
| Volumes | `/app/server/data`, `/app/server/uploads` |
| Env | `environment.coolify.env` |
| Health | `/api/health.php` ou `/deploy-stamp.json` |

Push em `main` + deploy automático. Verificar `https://ecstaticdanceviseu.pt/deploy-stamp.json` após cada deploy.

### Dockerfile (opcional)

Build Pack = **Dockerfile**, porta **80**, volumes em `/var/www/edv-server/data` e `.../uploads`. Nginx + PHP — ver `Dockerfile` e secção 6 em `docs/COOLIFY.md`.

### Variáveis de ambiente (resumo)

| Variável | Descrição |
|----------|-----------|
| `PORT` | Porta Vite (Nixpacks; Coolify define) |
| `EDV_*` | Ver `environment.coolify.env` |
| `EDV_REPLACE_CONFIG_FROM_EXAMPLE` | `1` = gera `config.php` em cada arranque |

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
├── bilhetes.html       ← Reserva de bilhetes (novo)
├── confirmacao.html    ← Confirmação + QR code (novo)
├── cancelamento.html   ← Pagamento cancelado (novo)
├── css/
│   ├── styles.css      ← Estilos da home
│   ├── pages.css       ← Estilos das páginas interiores
│   └── bilhetes.css    ← Estilos do sistema de bilhetes (novo)
├── js/
│   ├── main.js         ← JS da home (módulo ES)
│   ├── pages.js        ← JS das páginas interiores + formulários
│   └── bilhetes.js     ← JS do sistema de bilhetes (novo)
├── public/
│   ├── robots.txt
│   ├── sitemap.xml
│   └── 404.html
├── server/             ← PHP backend (upload para cPanel, NÃO no build Vite)
│   ├── api/
│   │   ├── config.example.php   ← Template das credenciais
│   │   ├── config.php           ← Credenciais reais (gitignored)
│   │   ├── helpers.php          ← Utilitários partilhados
│   │   ├── get-events.php       ← Retorna evento activo
│   │   ├── create-checkout.php  ← Stripe Checkout session
│   │   ├── register-free.php    ← Reserva gratuita
│   │   ├── webhook.php          ← Webhook Stripe
│   │   ├── verify-ticket.php    ← Verificação QR
│   │   ├── reconcile.php        ← Reconciliação cron
│   │   └── .htaccess
│   ├── admin/
│   │   ├── index.php            ← Painel de admin
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── checkin.php          ← Toggle manual check-in
│   │   ├── export.php           ← Export CSV
│   │   ├── auth.php             ← Session helpers
│   │   └── .htaccess
│   └── setup/
│       ├── schema.sql           ← Esquema MySQL
│       ├── install.php          ← Script de instalação (apagar após usar)
│       └── .htaccess
├── docs/
│   ├── DEPLOYMENT.md
│   ├── DATABASE.md
│   ├── STRIPE.md
│   └── ADMIN.md
├── vite.config.mjs     ← Config Vite MPA (9 entradas HTML)
├── Dockerfile          ← Node build → Nginx + PHP + tini para Coolify
├── nginx.conf          ← Nginx: gzip, cache, proxy /api /admin → PHP
└── package.json
```

## Build Docker local (teste antes de deploy)

```bash
docker build -t ecstatic-dance-viseu .
docker run -p 8080:80 ecstatic-dance-viseu
# Abre http://localhost:8080
```
