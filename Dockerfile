# ── Stage 1: Build ──────────────────────────────────────────
FROM node:22-alpine AS builder

WORKDIR /app

COPY package*.json ./
RUN npm ci --prefer-offline

COPY . .
RUN npm run build \
  && test -f dist/links.html \
  && test -f dist/buy.html

# ── Stage 2: Nginx + PHP (admin /api no mesmo contentor — Coolify) ──
FROM nginx:1.27-alpine AS production

RUN apk add --no-cache \
    supervisor \
    php83 \
    php83-ctype \
    php83-curl \
    php83-mbstring \
    php83-openssl \
    php83-pdo \
    php83-pdo_mysql \
    php83-pdo_sqlite \
    php83-session \
    php83-xml \
    php83-sqlite3

RUN rm -rf /usr/share/nginx/html/*

COPY --from=builder /app/dist /usr/share/nginx/html
COPY server /var/www/edv-server

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY scripts/docker-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/api/health.php || exit 1
