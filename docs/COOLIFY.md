# Deploy no Coolify — referência (realidade v4.1)

Produção actual em **Hetzner (CX53)** usa **Nixpacks** (auto-detect a partir de `package.json` + `nixpacks.toml`), **não** o `Dockerfile` da raiz — a menos que mudes explicitamente o Build Pack no painel.

| Modo | Quando usar | Raiz no contentor | Porta exposta |
|------|-------------|-------------------|---------------|
| **Nixpacks** (actual) | Coolify “Automatic” / Node | `/app` | `PORT` (Coolify define, ex. 3000) |
| **Dockerfile** (opcional) | Build Pack = Dockerfile | `/var/www/edv-server` + Nginx em `:80` | `80` |

Persistência: [Coolify Persistent Storage](https://coolify.io/docs/knowledge-base/persistent-storage) — volumes nos directórios onde a app **escreve** dados (SQLite, comprovativos).

---

## 1. Arquitectura Nixpacks (produção)

```
Internet → Traefik (Coolify) → contentor :PORT
                                    │
                    ┌───────────────┴───────────────┐
                    │  vite preview (npm run start) │
                    │  serve dist/ (HTML, /assets)  │
                    │  proxy /api, /admin, /uploads │
                    └───────────────┬───────────────┘
                                    ▼
                    PHP built-in 127.0.0.1:8080  (-t server/)
                    server/api/*.php  server/admin/*.php
```

- **Front:** `npm run build` → `dist/` (hashes em `/assets/*`).
- **Back:** `scripts/start-preview-with-php.sh` — PHP na primeira porta livre 8080–8099, Vite em `0.0.0.0:$PORT`.
- **Config:** `server/api/config.php` não está no Git. Com `EDV_REPLACE_CONFIG_FROM_EXAMPLE=1`, cada arranque gera `config.php` a partir de `config.example.php` + variáveis `EDV_*` (ver `environment.coolify.env`).

### O que o log de deploy deve mostrar

- `nixpacks plan` / `nixpacks build`
- `npm run build` → lista `dist/links.html`, `manual-booking-….js`
- `CMD ["npm run start"]`

Se vires `FROM nginx:1.27-alpine` ou `COPY server /var/www/edv-server`, estás no **Dockerfile** (outro modo).

---

## 2. Volumes (Nixpacks — obrigatório para dados)

Coolify → serviço → **Persistent Storage**:

| Volume (nome livre) | Destination no contentor |
|---------------------|----------------------------|
| `edv-data` | `/app/server/data` |
| `edv-uploads` | `/app/server/uploads` |

Conteúdo típico em `data/`:

- `events-tickets.sqlite` — eventos, bilhetes Stripe, presenças, custos
- `link-bookings.sqlite` — inscrições `/links` (MB Way, comprovativos)

**Não montar:**

- `/app` inteiro (sobrescreve código da imagem → deploy “não muda nada”)
- `/app/dist` (idem)
- `/var/www/edv-server/...` (caminho do Dockerfile; irrelevante em Nixpacks)

### Dockerfile (se mudares Build Pack)

| Volume | Destination |
|--------|-------------|
| `edv-data` | `/var/www/edv-server/data` |
| `edv-uploads` | `/var/www/edv-server/uploads` |

---

## 3. Base de dados em Coolify

### Modo actual: SQLite nos volumes (recomendado)

Duas bases SQLite no mesmo volume `data/` (ficheiros separados):

| Ficheiro | Conteúdo |
|----------|----------|
| `events-tickets.sqlite` | `events`, `tickets`, `event_attendance`, `event_costs`, … |
| `link-bookings.sqlite` | `link_registrations` (/links) |

Variáveis (copiar de **`environment.coolify.env`**):

```env
EDV_USE_SQLITE_MAIN_DB=true
EDV_LINK_USE_SQLITE=true
EDV_LINK_USE_JSON=false
EDV_MAIN_DB_SQLITE_PATH=/app/server/data/events-tickets.sqlite
EDV_LINK_SQLITE_PATH=/app/server/data/link-bookings.sqlite
```

As tabelas são criadas/actualizadas pelo PHP ao abrir admin ou API (migrations em `server/setup/migration_*.sql` para MySQL; em SQLite muitas colunas são adicionadas em código).

**Importante:** se no Coolify tiveres paths antigos `/var/www/edv-server/data/...`, a app escreve **fora** do volume montado em `/app/server/data` — dados “desaparecem” ou parecem vazios. Alinha env com `environment.coolify.env`.

### Modo futuro: MySQL no Coolify ou externo

```env
EDV_USE_SQLITE_MAIN_DB=false
EDV_LINK_USE_SQLITE=false
EDV_LINK_USE_JSON=false
EDV_DB_DRIVER=mysql
EDV_DB_HOST=...
EDV_DB_NAME=...
EDV_DB_USER=...
EDV_DB_PASS=...
```

Corre as migrations em `server/setup/migration_*.sql` na base MySQL. Ver também `docs/DEPLOYMENT.md` (cPanel) e `docs/DATABASE.md`.

### cPanel (legado)

Hospedagem clássica com MySQL em `public_html/api/` — guia em **`docs/DEPLOYMENT.md`**. Não misturar o mesmo domínio com dois backends (Coolify + cPanel).

---

## 4. Coolify — configuração do serviço

### Build

| Campo | Valor (Nixpacks) |
|-------|------------------|
| Build Pack | **Nixpacks** ou Automatic (detecta `nixpacks.toml`) |
| Branch | `main` |
| Base directory | `/` |

### Rede

| Campo | Valor (Nixpacks) |
|-------|------------------|
| **Ports Exposes** | deixar o Coolify usar **`PORT`** (não forces `80` salvo Dockerfile) |
| **Port Mappings** | **vazio** |

### Domínios

Uma URL por linha, com `https://`:

- `https://ecstaticdanceviseu.pt`
- `https://ecstaticdance-viseu.innerflect.tech`

Evitar `https//...` (falta `:`) — estraga `COOLIFY_URL` nos logs.

### Environment

Copiar **`environment.coolify.env`** → painel Environment Variables. Substituir Stripe, tokens e hash admin.

Opcional: `EDV_REPLACE_CONFIG_FROM_EXAMPLE=1` para não depender de `config.php` manual no contentor.

### Health check

Path: `/api/health.php` ou `/deploy-stamp.json`

Resposta esperada após deploy recente:

```json
{"ok":true,"service":"edv-php","commit":"…","built_at":"…"}
```

---

## 5. Deploy e verificação

1. Push em `main` (ou **Redeploy** manual).
2. Log: build Nixpacks completo (não só “Build step skipped” com imagem antiga).
3. Confirmar no browser:
   - `https://ecstaticdanceviseu.pt/deploy-stamp.json` — `commit` = SHA do `main`, `built_at` recente
   - `https://ecstaticdanceviseu.pt/links.html` — ver código-fonte: hash JS actual (não `manual-booking-DebSsfR_.js` de Maio)
   - `https://ecstaticdanceviseu.pt/api/get-ticket-pricing.php?email=test@example.com` — JSON (não 404)
4. Admin → **Inscrições** — faixa técnica deve mostrar caminho `/app/server/data/...`.

### «Nada mudou» após deploy

| Sintoma | Causa provável | Acção |
|---------|----------------|-------|
| Log `Build step skipped` + HTML antigo | Imagem Docker antiga com mesmo SHA | **Force rebuild** / apagar imagem `i5ive34…` no servidor |
| `DebSsfR_.js` em `/links` | Tráfego para contentor antigo ou volume em `/app` | Só volumes `data` + `uploads`; redeploy |
| Dados vazios após redeploy | Env com paths `/var/www/...` em Nixpacks | Usar `environment.coolify.env` |
| `ECONNREFUSED 127.0.0.1:8080` | PHP não arrancou | Ver logs; `php83` no Nixpacks (`nixpacks.toml`) |

---

## 6. Dockerfile (opcional)

Build Pack = **Dockerfile**, Ports Exposes = **80**, volumes em `/var/www/edv-server/data` e `.../uploads`.

- Nginx serve `dist/`; PHP em `127.0.0.1:8080`
- `nginx.conf` redirecciona páginas “brochure” para `/` (splash); `/links` fica activo
- Env: usar paths `/var/www/edv-server/...` ou `environment.example.env` secção Dockerfile

Útil se quiseres stack Nginx fixa; **não** é o que o log Nixpacks actual mostra.

---

## 7. Checklist rápido

- [ ] Build Pack = **Nixpacks** (ou Automatic com `nixpacks.toml` na raiz)
- [ ] Volumes: `/app/server/data` + `/app/server/uploads`
- [ ] Env alinhado com **`environment.coolify.env`** (paths `/app/server/...`)
- [ ] Sem volume em `/app` nem `/app/dist`
- [ ] Port Mappings vazio
- [ ] Domínios com `https://` correcto
- [ ] `/deploy-stamp.json` com commit recente após deploy
- [ ] Stripe webhook → `https://ecstaticdanceviseu.pt/api/webhook.php`

---

## 8. Emails e cron

- Emails: `mail()` PHP com `EDV_FROM_EMAIL` — não são enviados pelo deploy; só em reserva/confirmação/webhook.
- Reconciliação Stripe: cron externo ou Coolify scheduled task a chamar `/api/reconcile.php?token=EDV_RECONCILE_TOKEN`.
