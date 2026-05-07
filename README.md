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

Em produção (cPanel) os mesmos ficheiros usam **MySQL**; checklist em **`docs/DEPLOYMENT.md`** (secção *Link hub (links.html)*). No servidor continua a ser necessário o interpretador PHP com as extensões do hosting para o `/api`.
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
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Guia completo de deploy no cPanel |
| [docs/DATABASE.md](docs/DATABASE.md) | Esquema MySQL e queries de gestão |
| [docs/STRIPE.md](docs/STRIPE.md) | Configuração Stripe + MB Way + Multibanco |
| [docs/ADMIN.md](docs/ADMIN.md) | Como usar o painel de admin e scanner QR |

### Novas páginas

| Página | URL |
|---|---|
| Reserva de bilhetes | `/bilhetes.html` |
| Confirmação / QR code | `/confirmacao.html` |
| Pagamento cancelado | `/cancelamento.html` |
| Painel de admin | **`https://ecstaticdanceviseu.pt/admin/`** — faz login em **`/admin/login.php`** se não tiveres sessão ([docs/ADMIN.md](docs/ADMIN.md)). No **Coolify com Dockerfile**, PHP corre no mesmo contentor (proxy Nginx → `php -S`). No **cPanel**, PHP é o da hospedagem. |

---

## Deploy no Coolify (Hetzner)

Referência única (**volumes SQLite, `/var/www/edv-server`, env `EDV_*`, healthcheck**): **[docs/COOLIFY.md](docs/COOLIFY.md)**

### Opção A (recomendada): Dockerfile (Nginx + PHP)

O contentor faz **build Vite** → serve `dist/` com **Nginx** e sobe **PHP built-in** (`php -S … -t server`) em `127.0.0.1:8080`, com **tini** como init e um script a arrancar os dois processos (sem Supervisor/Python, mais estável em Alpine). O Nginx envia `/api`, `/admin` e `/uploads` para esse PHP (ver `nginx.conf`).

Na **primeira arranque**, se não existir `config.php` no contentor, o `entrypoint` copia `server/api/config.example.php` → `config.php`. O exemplo inclui **`ADMIN_PASSWORD_HASH` para a palavra-passe `admin123`** e **`USE_SQLITE_MAIN_DB = true`** (eventos/bilhetes em ficheiro SQLite em `server/data/`, sem MySQL no contentor). O painel em **`/admin/`** deixa de rebentar com *No such file or directory* no MySQL.

Se já tinhas um `config.php` antigo (só MySQL placeholder) no volume, edita-o ou apaga para voltar a gerar a partir do exemplo, ou monta um **`config.php`** completo. Para **MySQL** em produção, monta credenciais reais e define **`USE_SQLITE_MAIN_DB = false`**.

### 1. Configurar o repositório

No Coolify, cria um novo recurso do tipo **Docker** e liga ao repositório Git.

- **Build Pack**: **Dockerfile** (Nixpacks é alternativa — ver Opção B.)
- **Dockerfile location**: `./Dockerfile` (raiz do repo)
- **Base directory**: `/` (vazio ou raiz)
- **Porta exposta / Publish port**: `80` (o `EXPOSE 80` do Dockerfile; no painel, o tráfego público deve mapear para a porta **80** do contentor.)
- **Health check path**: `/`

### 1b. Ativar deploy ao fazer push no GitHub

1. Abre o **serviço** em Coolify → separador **Configuration** (ou **General**).
2. Em **Source**, confirma:
   - **Repository**: `InnerFlect-Tech/ecstaticdance-viseu` (ou o nome correcto da org/repo).
   - **Branch**: `main`.
3. Activa **Automatic deployment** / **Deploy on commit** (o nome exacto varia entre v3/v4 do Coolify) para esta branch.
4. Garante que a **GitHub App** do Coolify tem acesso ao repositório: em GitHub → *Organization / Repository settings* → *GitHub Apps* → *Coolify* → *Repository access* → inclui este repo (ou “All repositories”). Sem isto, o Coolify não recebe eventos de push.
5. Grava as alterações e faz **Redeploy** uma vez (ou **Deploy**) para validar o build.
6. Se o push não disparar deploy, no serviço Coolify procura **Webhook** / **Deploy hook** → copia o URL; em GitHub → *Settings* → *Webhooks* → *Add webhook* → cola o URL, content type `application/json`, eventos mínimos **Just the push event**. (Só necessário se a integração Git App não estiver a notificar.)

Depois de um deploy bem-sucedido, `https://ecstaticdanceviseu.pt/links` e `/links.html` devem servir o HTML do hub (e os ficheiros em `/assets/` mudam de hash em cada build). O `nginx.conf` do Docker redirecciona o “brochure + checkout” para a splash (inclui **`/buy`** e `/buy.html`); repõe o deploy se esses URL ainda mostrarem a página completa.

### Opção B: Nixpacks (Node + PHP)

O `vite preview` faz **proxy** de `/api`, `/admin` e `/uploads` para `http://127.0.0.1:${EDV_PHP_API_PORT}` (por omissão **8080**). Só com Vite, nada ouve na 8080 → logs tipo **`ECONNREFUSED 127.0.0.1:8080`**.

Este repo inclui:

- `nixpacks.toml` — Node **e** `php83` (Nix), build `npm run build`, arranque `npm run start`.
- `scripts/start-preview-with-php.sh` — sobe **PHP built-in** (`php -S … -t server`) na primeira porta livre **8080–8099**, exporta `EDV_PHP_API_PORT`, e corre `vite preview` em `0.0.0.0:$PORT`.
- `BROWSER=none` / `CI=true` por omissão no script para evitar `xdg-open ENOENT` em contentores.

**Base de dados em produção:** `server/api/config.php` não vai no Git. No Coolify (Nixpacks), monta o ficheiro (secret file) ou adiciona-o ao artefacto antes do start; define **MySQL** e `LINK_USE_SQLITE` / `LINK_USE_JSON` a **`false`**. Sem isto, o proxy deixa de dar `ECONNREFUSED`, mas a API pode responder 500 se as credenciais forem placeholders. Com **Dockerfile**, o mesmo: monta `config.php` em `/var/www/edv-server/api/config.php` quando precisares de credenciais reais.

### 2. Variáveis de ambiente

| Variável | Descrição | Exemplo |
|----------|-----------|---------|
| `PORT` | Porta do Vite preview (Coolify define automaticamente) | `3000` |
| `EDV_PHP_API_PORT` | Porta do PHP built-in (opcional; omissão = primeira livre 8080–8099) | `8080` |

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
