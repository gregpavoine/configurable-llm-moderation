#!/bin/sh

set -eu

umask 077
mkdir -p /app/var /app/config/jwt

php /app/bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
chmod 0600 /app/config/jwt/private.pem
chmod 0644 /app/config/jwt/public.pem

php /app/bin/console doctrine:migrations:migrate --no-interaction
php /app/bin/console doctrine:schema:validate
php /app/bin/console cache:clear --no-warmup

