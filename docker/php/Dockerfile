# syntax=docker/dockerfile:1

# Image de base (développement local : le code est monté par docker-compose).
FROM php:8.4-apache AS base

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update && apt-get install -y --no-install-recommends \
    bash \
    git \
    curl \
    unzip \
    gnupg \
    ca-certificates \
    libzip-dev \
    libpq-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

# Configuration Apache pour servir Symfony depuis /public
RUN a2enmod rewrite \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf \
    && sed -ri "s!/var/www/!${APACHE_DOCUMENT_ROOT}/!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Apache écoute sur 8080 (le trafic inter-node du cluster droppe les ports < 1025)
RUN sed -ri "s!^Listen 80\$!Listen 8080!" /etc/apache2/ports.conf \
    && sed -ri "s!<VirtualHost \*:80>!<VirtualHost *:8080>!" /etc/apache2/sites-available/000-default.conf

# Installation des extensions PHP
RUN docker-php-ext-configure zip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql zip gd

# Installation de Composer depuis l'image officielle
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Installation du client Symfony pour les makers
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash \
    && apt-get update \
    && apt-get install -y --no-install-recommends symfony-cli \
    && rm -rf /var/lib/apt/lists/*

# ============================================
# STAGE PRODUCTION
# ============================================
# Image optimisée pour la production
# Hérite du stage "base" (PHP 8.4, Apache, extensions)
# Installe uniquement les dépendances nécessaires (pas de devDependencies)
# Build: docker buildx build -f docker/php/Dockerfile --target production .
FROM base AS production

WORKDIR /var/www/html

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# ============================================
# Étape 1: Installation des dépendances PHP
# ============================================
# Copie les fichiers de configuration Composer
# Important pour le cache Docker: on copie d'abord les manifests/lockfiles
# pour éviter de relancer `composer install` à chaque changement de code métier.
COPY composer.json composer.lock symfony.lock ./

# Installe les dépendances PHP sans les packages de développement
# --no-dev: exclut les devDependencies
# --optimize-autoloader: optimise le chargement des classes
# --prefer-dist: télécharge les archives plutôt que les repos Git
# --no-scripts: n'exécute pas les scripts post-install
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

# ============================================
# Étape 2: Build des assets frontend
# ============================================
# Copie les fichiers de configuration Node.js
# Même logique de cache: `npm ci` n'est relancé que si les fichiers package changent.
COPY package.json package-lock.json webpack.config.js ./
COPY assets ./assets

# Installe les dépendances npm et lance le build Webpack
# npm ci: installation reproductible (utilise package-lock.json)
# npm run build: compile SCSS/JS avec Webpack
RUN npm ci \
    && npm run build

# ============================================
# Étape 3: Copie du code applicatif
# ============================================
# Copie le reste du code source dans le conteneur
COPY . .

# ============================================
# Étape 4: Optimisation et configuration finale
# ============================================
# Crée les répertoires cache et logs
# Génère l'autoloader optimisé (classmap)
# Définit les permissions pour le serveur web
RUN mkdir -p var/cache var/log \
    && composer dump-autoload --optimize --classmap-authoritative \
    && chown -R www-data:www-data var public

# ============================================
# Configuration environnement
# ============================================
# Active le mode production pour Symfony
# Désactive le Profiler et les outils de debug
ENV APP_ENV=prod
ENTRYPOINT ["docker-entrypoint.sh"]

# ============================================
# Health Check
# ============================================
# Vérifie que l'application est accessible
# Intervalle: 30s | Timeout: 5s | Démarrage: 60s | Retries: 3
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost:8080/ || exit 1