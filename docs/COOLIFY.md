# Deploy no Coolify — referência rápida

Este repositório suporta a imagem **Dockerfile oficial** (`/var/www/edv-server`). Se estiveres a usar **Nixpacks**, os caminhos dentro do contentor são outros (muitas vezes sob `/app`); usa o mesmo prefixo que o painel admin mostra na página **Inscrições** (faixa técnica com o caminho do SQLite).

Persistência recomendada pela documentação **[Coolify Persistent Storage](https://coolify.io/docs/knowledge-base/persistent-storage)** e padrões **[Docker volumes](https://docs.docker.com/get-started/docker-concepts/running-containers/persisting-container-data/)**: montar **volumes nomeados ou bind mounts** nos directórios onde a app guarda dados, para sobreviver a redeploy.

---

## 1. Imagem Dockerfile (este repo)

Camada PHP + Nginx: dados e uploads ficam relativos a **`/var/www/edv-server`** (ou `EDV_SERVER_ROOT` se definires).

Na UI Coolify → serviço → **Volumes / Persistent storage**, adiciona **dois** destinos:

| Volume (nome livre) | Destination path dentro do contentor |
|---------------------|--------------------------------------|
| `edv-data` | `/var/www/edv-server/data` |
| `edv-uploads` | `/var/www/edv-server/uploads` |

- **`data`**: SQLite `events-tickets.sqlite` + `link-bookings.sqlite` (modo default do exemplo), ou ficheiros auxiliares.
- **`uploads`**: comprovativos em `uploads/link-proofs/` (formulário /links).

> **Importante:** a documentação Coolify menciona `/app` como base em alguns stacks genéricos. **Esta imagem não usa `/app`** para dados da app — usa **`/var/www/edv-server`**. O destination path deve bater exactamente com a árvore do contentor ou os volumes não persistem onde o código escreve.

---

## 2. Configuração (escolher um modo)

### A) SQLite tudo na app (simples — default do `config.example.php`)

Sem variáveis de ambiente opcionais, o exemplo usa SQLite em `server/data/` (dentro do volume acima).

- Garante os volumes **`data`** + **`uploads`**.
- Não cries config montado incompatible: no primeiro boot o **entrypoint** copia `config.example.php` → `config.php` se `config.php` não existir.

### B) Só environment variables (`EDV_*`)

Lista completa **copiável** (formato `.env`): `environment.example.env` na raiz do repo. Referência técnica: cabeçalho de `server/api/config.example.php`.

Define no Coolify as mesmas variáveis (painel ou ficheiro de env).

- Para **forçar** recópia do template em cada arranque (útil só com secrets em env):

  `EDV_REPLACE_CONFIG_FROM_EXAMPLE=1` (ou valor truthy compatível)

- Produção só MySQL dentro do próprio Coolify: `EDV_USE_SQLITE_MAIN_DB=false`, `EDV_LINK_USE_SQLITE=false`, `EDV_DB_*`.

---

## 3. Health check

Imagem Dockerfile: probes **PHP built-in** em `http://127.0.0.1:8080/api/health.php` (endpoint leve sem base de dados).

No Coolify podes repetir esse path atrás do proxy público (**Health check**: `/api/health.php`), coerente com `nginx.conf` (`location ^~ /api`).

---

## 4. Deploy

1. Repositório + branch (**main** típico).  
2. Volumes configurados (**data** + **uploads**) com caminhos acima.  
3. Secrets / env conforme modo A ou B.  
4. **Deploy / Redeploy**.  
5. Verificar: página pública `/links`, admin `/admin/link-bookings.php` (total e faixa técnica), e existe ficheiros no volume após uma inscrição de teste.

### «Nada mudou» após deploy (build ignorado)

No log, se aparecer **`Build step skipped`** com imagem já etiquetada com o mesmo commit, o Coolify pode **não reconstruir** e voltar a arrancar uma imagem **antiga** (código de Maio, `links.html` com hashes antigos).

**Verificação rápida (browser ou curl):**

- `https://ecstaticdanceviseu.pt/api/health.php` → deve incluir `"commit":"<sha do main>"` (não `unknown`).
- `https://ecstaticdanceviseu.pt/build-info.json` → mesmo commit.
- `https://ecstaticdanceviseu.pt/links.html` → no código-fonte, o JS deve ser `manual-booking-BcDjgn_k.js` (o hash muda quando o front muda).

**Correcção:**

1. Coolify → serviço → **Redeploy** → activar **Force rebuild** / **Build without cache** (v4: opção no deploy ou em *Advanced*).
2. Confirma que o log **não** diz `Build step skipped` — deve correr `npm run build` no Dockerfile.
3. **Volumes:** só `/var/www/edv-server/data` e `.../uploads`. **Não** montes `/var/www/edv-server` inteiro nem `/usr/share/nginx/html` (sobrescreve código novo da imagem).
4. Se persistir: apaga a imagem antiga no servidor (`docker images | grep i5ive34`) e redeploy.

---

## Checklist rápido

- [ ] Volumes montados nos caminhos **correctos para esta imagem** (`/var/www/edv-server/...`).  
- [ ] Se usas apenas SQLite: dois volumes (`data` + `uploads`).  
- [ ] Se alteraste `server/api/config.php` num volume antigo sem suporte `EDV_*`, actualiza-o ou usa `EDV_REPLACE_CONFIG_FROM_EXAMPLE=1` com só env vars.  
- [ ] Um único sítio a servir o domínio (evita dois backends a concorrer pela mesma inscrição).  
- [ ] **Ports Exposes = `80`**, **Port Mappings vazio** (ver secção **5**).  
- [ ] **Build Pack = Dockerfile** (não Nixpacks — ver secção **7**).  
- [ ] Domínios / URLs válidos (`https://...`, sem `https//`).  

---

## 5. **Ports Exposes** vs **Port Mappings** (crítico — evita falha de deploy e 502)

No Coolify, o **Traefik** (ou outro proxy da instalação) já ocupa as portas **80** e **443** no **host** Hetzner. O tráfego público chega lá e é **encaminhado para a rede Docker** até ao teu contentor — **sem** precisares de publicar a app em `0.0.0.0:80`.

| Campo | Valor correcto para esta imagem |
|--------|--------------------------------|
| **Ports Exposes** | **`80`** — é a porta onde o **Nginx** escuta *dentro* do contentor (o proxy usa isto para saber para onde fazer forward). |
| **Port Mappings** | **Vazio / apagar** — **não** uses `80:80` nem `3000:3000` no host. Se mapeares **`80:80`**, o Docker tenta ligar a `0.0.0.0:80` na máquina e falha com **`port is already allocated`** (a porta já está a ser usada pelo proxy do Coolify). |

Mensagem típica quando está mal configurado:

`Bind for 0.0.0.0:80 failed: port is already allocated`

**O que fazer:** remove **Port Mappings** por completo; mantém só **Ports Exposes = 80**. Grava e faz **Redeploy**.

O log *"Application has ports mapped to the host system, rolling update is not supported"* indica que há mapeamento para o host — para apps atrás do proxy Coolify, normalmente **não** queres isso.

---

## 6. **502 Bad Gateway** no domínio público

1. **Porta do contentor** — A imagem **EXPOSE 80** (`nginx`). O proxy (Traefik/Coolify) deve enviar tráfego HTTP(S) para **`80` interno**, **não** para `8080` (isso é só o PHP built‑in para `/api`/`/admin`).
2. **Sem host publish** — Segue a secção **5** acima: não forces `80:80` no host.
3. **Nginx a escutar** — O healthcheck da imagem faz `wget` em `http://127.0.0.1/` **e** em `http://127.0.0.1:8080/api/health.php`. Se o deploy ficar *unhealthy*, o proxy devolve 502.
4. **DNS** — `ecstaticdanceviseu.pt` tem de resolver para o mesmo destino onde corre **este** serviço (registo A/AAAA ou CNAME conforme Coolify/domínios custom).

---

## 7. Deploy com **Nixpacks** por engano · **no available server** · URLs partidas nos logs

### Sintomas no log (build errado para produção nginx+PHP)

Se vires **`deploy_nixpacks_buildpack`**, **`COPY .nixpacks/`**, **`nix-env`**, ou **`vite build`** numa pilha tipo **stage-0**/Nix, o Coolify está a usar **Nixpacks**, **não** o **Dockerfile** deste repo.

- Stack de produção: **Docker** com `Dockerfile` na raiz (Nginx + PHP em `/var/www/edv-server`).
- O ficheiro `nixpacks.toml` só serve **preview/stack alternativo** (Node `npm run start`). Não deve ser o modo do serviço público Coolify para este site.

**O que fazer:** Serviço → **Build** → **Build Pack = Dockerfile** (não *Nixpacks* nem *automatic* se isso voltar ao Nixpacks). Dockerfile path típico: `Dockerfile`, base **`/`**. Guardar e **Redeploy**.

### **Port is already allocated** (continua igual à secção 5)

Mantém **`Ports Exposes = 80`** e **Port Mappings vazio**. Enquanto o log disser *"Application has ports mapped to the host system"*, ainda há **`ports:` para o host** em algures — remove na UI ou no compose gerado até desaparecer essa linha.

### **`no available server`** / router sem backend

Um deploy falhado pode **apanhar e remover** a nova versão; se nenhum contentor ficou a correr atrás das labels Traefik/Coolify, o browser pode mostrar erro de servidor indisponível. Corrigir **Build Pack + rede** como acima e **Redeploy** até o compose subir sem erros.

### URLs nos logs Coolify (`https//`, `COOLIFY_FQDN` estranho)

Exemplo típico: `https//host...` (**falta `:`** após `https`) ou vírgulas a juntar domínios sem URL completa. Em **domains / URLs** usa sempre **`https://` + nome de host** por entrada (ex.: `https://ecstaticdanceviseu.pt`), vírgulas só entre URLs válidas — senão variáveis `COOLIFY_URL` / `COOLIFY_FQDN` ficam mal formadas e o router pode comportar‑se mal.
