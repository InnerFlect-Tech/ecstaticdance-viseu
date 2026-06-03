# Deploy no Coolify — referência (realidade v4.1)

Produção em **Hetzner (CX53)**: **Nixpacks** (`package.json` + `nixpacks.toml`) + **volumes persistentes** já montados em `/var/www/edv-server/...`.

| O quê | Onde no contentor |
|-------|-------------------|
| Código + `dist/` (build) | `/app` |
| SQLite + JSON dev | volume → `/var/www/edv-server/data` |
| Comprovativos `/links` | volume → `/var/www/edv-server/uploads` |
| HTTP público | Vite preview em `0.0.0.0:$PORT` (**3000**) |

---

## 1. Arquitectura

```
Internet → Traefik → :3000 (Vite preview, dist/)
                         │ proxy /api, /admin, /uploads
                         ▼
              PHP built-in 127.0.0.1:8080+  (-t /app/server)
                         │
              SQLite lê/escreve em /var/www/edv-server/data/*.sqlite
              Uploads em /var/www/edv-server/uploads (symlink desde /app/server/uploads)
```

- **Build:** `npm ci` → `npm run build` → `dist/`
- **Start:** `npm run start` → `scripts/start-preview-with-php.sh`
- **Config:** `server/api/config.php` no contentor (não está no Git). Com `EDV_REPLACE_CONFIG_FROM_EXAMPLE=0`, mantém-se o `config.php` existente; variáveis `EDV_*` aplicam-se quando o ficheiro foi gerado a partir de `config.example.php` (usa `getenv`).

### Log de deploy esperado

- `nixpacks build`, `npm run build`, assets novos (`manual-booking-….js`)
- Arranque: `EDV start | stack=nixpacks-vite-preview | commit=…`

---

## 2. Persistent Storage (configuração actual)

Coolify → **Storages** → **Volumes**:

| Volume (exemplo) | Destination |
|------------------|-------------|
| `…-edv-data` | `/var/www/edv-server/data` |
| `…-edv-uploads` | `/var/www/edv-server/uploads` |

**Não alterar** estes destinos se os dados de produção já estão aí.

**Não montar:** `/app`, `/app/dist` (sobrescreve o código da imagem → site não actualiza).

O script de arranque cria `data/` e `uploads/` em `EDV_SERVER_ROOT` e liga `/app/server/uploads` → `/var/www/edv-server/uploads` para o PHP e o proxy Vite usarem o mesmo volume.

---

## 3. Environment (manter como no painel)

Template em **`environment.coolify.env`** — espelha a configuração que já funcionava:

```env
NIXPACKS_NODE_VERSION=22
EDV_SERVER_ROOT=/var/www/edv-server
EDV_REPLACE_CONFIG_FROM_EXAMPLE=0
EDV_USE_SQLITE_MAIN_DB=true
EDV_LINK_USE_SQLITE=true
EDV_LINK_USE_JSON=false
EDV_MAIN_DB_SQLITE_PATH=/var/www/edv-server/data/events-tickets.sqlite
EDV_LINK_SQLITE_PATH=/var/www/edv-server/data/link-bookings.sqlite
EDV_LINK_JSON_PATH=/var/www/edv-server/data/link-registrations-dev.json
# … Stripe, mail, tokens, EDV_ADMIN_PASSWORD_HASH
```

`config.example.php` lê estes paths via `getenv` — os ficheiros SQLite ficam no volume, não em `/app/server/data`.

---

## 4. Rede e build

| Campo | Valor |
|-------|--------|
| Build Pack | **Nixpacks** (ou Automatic) |
| **Ports Exposes** | **3000** |
| Port mappings | vazio |
| Is it a static site? | **OFF** |
| Branch | `main` |

`coolify.json` e `nixpacks.toml` definem `PORT=3000` para coincidir com o painel.

---

## 5. Verificação após deploy

```bash
bash scripts/check-production-deploy.sh
```

Ou manualmente:

| URL | Esperado |
|-----|----------|
| `/api/health.php?diag=1` | `"stack":"nixpacks-vite-preview"`, `"commit":"…"` |
| `/links.html` (código-fonte) | `manual-booking-….js` **≠** `DebSsfR_` |
| `/api/get-ticket-pricing.php?email=x@y.z` | JSON 200 |
| `/deploy-stamp.json` | JSON com `commit` |

Se `health` só devolver `{"ok":true,"service":"edv-php"}` sem `commit`, o tráfego ainda não chega ao contentor Nixpacks novo (redeploy + confirmar porta 3000).

---

## 6. Terminal e Docker

| Onde abres o terminal | O que consegues fazer |
|-----------------------|------------------------|
| **Serviço** → Terminal | Shell **dentro do contentor** (`node`, `php`, `ls /app/dist`) — **não** há comando `docker` |
| **Servers** → CX53 Hetzner → Terminal | Shell no **host** — aqui sim: `docker ps`, limpar imagens antigas |

No host, o nome do serviço usa o prefixo do UUID, por exemplo `l5ive34…` (letra **L**, não `i`):

```bash
docker ps -a --filter name=l5ive34
```

Dentro do contentor (útil após redeploy):

```bash
ls -la /app/dist/links.html
cat /app/server/api/build-info.json
ls -la /var/www/edv-server/data/
```

---

## 7. «Nada mudou» após deploy

| Sintoma | Acção |
|---------|--------|
| Log `Build step skipped` | Force rebuild no Coolify |
| `DebSsfR_.js` em `/links` | Ports Exposes = **3000**, redeploy, confirmar logs de arranque Nixpacks |
| `health` sem `commit` | Idem; tráfego ainda no contentor antigo |
| Dados “vazios” | Não mudar paths do volume; confirmar `EDV_*_SQLITE_PATH` em `/var/www/edv-server/data/...` |
| Volume em `/app` | Remover — apaga o `dist/` novo |

---

## 8. Dockerfile (opcional, não é o modo actual)

Build Pack = Dockerfile, Ports Exposes = **80**, mesmos volumes `/var/www/edv-server/data` e `…/uploads`, Nginx + PHP. Ver `Dockerfile` e `scripts/docker-entrypoint.sh`.

---

## 9. Checklist

- [ ] Build Pack = Nixpacks
- [ ] Ports Exposes = **3000**
- [ ] Volumes: `/var/www/edv-server/data` + `…/uploads` (como no painel)
- [ ] Env com paths `/var/www/edv-server/...` (não mudar para `/app/...`)
- [ ] Sem volume em `/app` nem `/app/dist`
- [ ] Redeploy após push com `health.php?diag=1` e script de verificação OK

---

## 10. Emails e cron

- Emails: só em runtime (reserva, admin, webhook) — não no deploy.
- Cron: `GET /api/reconcile.php?token=EDV_RECONCILE_TOKEN`
