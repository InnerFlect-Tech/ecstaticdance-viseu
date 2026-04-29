# Ecstatic Dance Viseu

Site estático multi-página construído com **Vite**, com sistema de bilhetes via **PHP + MySQL** para cPanel.

## Desenvolvimento local

```bash
npm install
npm run dev       # dev server em http://localhost:5173
npm run build     # produção em dist/
npm run preview   # preview do build em http://localhost:4173
npm run start     # (para Coolify/Nixpacks) preview em 0.0.0.0:$PORT (default 4173)
```

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
| Painel de admin | `/admin/` (PHP, não no build Vite) |

---

## Deploy no Coolify (Hetzner)

### Opção A (recomendada): Dockerfile (Nginx)

### 1. Configurar o repositório

No Coolify, cria um novo recurso do tipo **Docker** e liga ao repositório Git.

- **Build Pack**: **Dockerfile** (não uses Nixpacks para este site — o contentor final é Nginx a servir `dist/`.)
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

Depois de um deploy bem-sucedido, `https://ecstaticdanceviseu.pt/links` e `/links.html` devem servir o HTML do hub (e os ficheiros em `/assets/` mudam de hash em cada build).

### Opção B: Nixpacks (Node)

Se estiveres a usar **Nixpacks**, garante que existe um comando de runtime (senão o Coolify pode lançar um `bash -c` vazio e falhar). Este repo inclui:

- `nixpacks.toml` com `start.cmd = "npm run start"`
- `npm run start` a fazer `vite preview --host 0.0.0.0 --port $PORT`

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
