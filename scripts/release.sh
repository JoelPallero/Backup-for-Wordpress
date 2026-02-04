#!/bin/bash

# Script para crear release de un plugin
# Uso: ./scripts/release.sh [VERSION]
# Ejemplo: ./scripts/release.sh 1.0.0

set -e

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Verificar que estamos en un repositorio git
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}❌ Error: No estás en un repositorio Git${NC}"
    exit 1
fi

# Obtener versión
if [ -z "$1" ]; then
    echo -e "${YELLOW}⚠️  No se especificó versión${NC}"
    echo "Uso: ./scripts/release.sh [VERSION]"
    echo "Ejemplo: ./scripts/release.sh 1.0.0"
    exit 1
fi

VERSION=$1
TAG="v${VERSION}"

# Verificar formato de versión (X.Y.Z)
if ! [[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo -e "${RED}❌ Error: Formato de versión inválido. Debe ser X.Y.Z (ej: 1.0.0)${NC}"
    exit 1
fi

echo -e "${BLUE}🚀 Creando release v${VERSION}...${NC}\n"

# Verificar que no haya cambios sin commitear
if ! git diff-index --quiet HEAD --; then
    echo -e "${YELLOW}⚠️  Hay cambios sin commitear${NC}"
    read -p "¿Deseas continuar de todas formas? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Verificar si el tag ya existe
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo -e "${RED}❌ El tag ${TAG} ya existe${NC}"
    exit 1
fi

# Obtener nombre del plugin del archivo principal
MAIN_FILE=$(find . -maxdepth 1 -name "*.php" -type f | head -n 1)
if [ -z "$MAIN_FILE" ]; then
    echo -e "${RED}❌ No se encontró archivo principal del plugin${NC}"
    exit 1
fi

PLUGIN_NAME=$(grep -m 1 "Plugin Name:" "$MAIN_FILE" | sed 's/.*Plugin Name: *//' | sed 's/ *$//' || echo "Plugin")

echo -e "${BLUE}Plugin: ${PLUGIN_NAME}${NC}"
echo -e "${BLUE}Versión: ${VERSION}${NC}"
echo -e "${BLUE}Tag: ${TAG}${NC}\n"

# Confirmar
read -p "¿Continuar con el release? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    exit 1
fi

# Crear tag
echo -e "${BLUE}📌 Creando tag...${NC}"
git tag -a "$TAG" -m "Release ${VERSION}: ${PLUGIN_NAME}"
echo -e "${GREEN}✅ Tag creado: ${TAG}${NC}\n"

# Push del tag
echo -e "${BLUE}📤 Subiendo tag a GitHub...${NC}"
git push origin "$TAG"
echo -e "${GREEN}✅ Tag subido${NC}\n"

echo -e "${GREEN}✅ Release iniciado!${NC}\n"
echo -e "${BLUE}GitHub Actions está creando el release y el ZIP automáticamente...${NC}"

# Obtener URL del repositorio
REPO_URL=$(git remote get-url origin 2>/dev/null || echo "")
if [ -n "$REPO_URL" ]; then
    REPO_URL=${REPO_URL#git@github.com:}
    REPO_URL=${REPO_URL#https://github.com/}
    REPO_URL=${REPO_URL%.git}
    echo -e "${BLUE}Ve a: https://github.com/${REPO_URL}/releases${NC}\n"
else
    echo -e "${BLUE}Ve a GitHub Releases para ver el release${NC}\n"
fi
