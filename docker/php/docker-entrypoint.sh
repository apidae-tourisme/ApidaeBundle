#!/bin/bash
set -e

if [ "$PHP_MODULES" != "" ]; then
  for module in $PHP_MODULES; do
    docker-php-ext-enable "$module"
  done
fi

# Active la migration auto au démarrage si demandé.
if [ "${RUN_DOCTRINE_MIGRATIONS:-0}" = "1" ]; then
  echo "Running Doctrine migrations..."
  php bin/console doctrine:migrations:migrate -n
fi

# S'assure que var/ (cache + log) est inscriptible par www-data (l'utilisateur Apache).
# Indispensable car un volume monté (K8s/compose) peut écraser l'ownership défini au build,
# et parce que les commandes ci-dessus tournent en root et créent du cache root-owned.
# Toléré si le conteneur tourne en non-root (chown échouera sans bloquer le démarrage).
if [ "$(id -u)" = "0" ]; then
  mkdir -p var/cache var/log
  chown -R www-data:www-data var
fi

if [[ -n "$1" ]]; then
  exec "$@"
else
  exec apache2-foreground
fi