#!/usr/bin/env sh
set -eu

JWT_SECRET_KEY_PATH="${JWT_SECRET_KEY:-/var/www/html/backend/var/jwt/private.pem}"
JWT_PUBLIC_KEY_PATH="${JWT_PUBLIC_KEY:-/var/www/html/backend/var/jwt/public.pem}"
JWT_PASSPHRASE_VALUE="${JWT_PASSPHRASE:-}"

mkdir -p /var/www/html/backend/var/cache /var/www/html/backend/var/log "$(dirname "$JWT_SECRET_KEY_PATH")"
chown -R www-data:www-data /var/www/html/backend/var || true

if [ ! -f "$JWT_SECRET_KEY_PATH" ] || [ ! -f "$JWT_PUBLIC_KEY_PATH" ]; then
  tmp_private_key="$(mktemp)"

  if [ -n "$JWT_PASSPHRASE_VALUE" ]; then
    openssl genpkey \
      -algorithm RSA \
      -aes-256-cbc \
      -pass pass:"$JWT_PASSPHRASE_VALUE" \
      -pkeyopt rsa_keygen_bits:2048 \
      -out "$tmp_private_key" >/dev/null 2>&1
    openssl pkey \
      -in "$tmp_private_key" \
      -passin pass:"$JWT_PASSPHRASE_VALUE" \
      -pubout \
      -out "$JWT_PUBLIC_KEY_PATH" >/dev/null 2>&1
  else
    openssl genpkey \
      -algorithm RSA \
      -pkeyopt rsa_keygen_bits:2048 \
      -out "$tmp_private_key" >/dev/null 2>&1
    openssl pkey \
      -in "$tmp_private_key" \
      -pubout \
      -out "$JWT_PUBLIC_KEY_PATH" >/dev/null 2>&1
  fi

  mv "$tmp_private_key" "$JWT_SECRET_KEY_PATH"
  chmod 600 "$JWT_SECRET_KEY_PATH"
  chmod 644 "$JWT_PUBLIC_KEY_PATH"
  chown www-data:www-data "$JWT_SECRET_KEY_PATH" "$JWT_PUBLIC_KEY_PATH" || true
fi

exec "$@"
