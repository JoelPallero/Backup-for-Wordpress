# Script PowerShell para crear release de un plugin
# Uso: .\scripts\release.ps1 [VERSION]
# Ejemplo: .\scripts\release.ps1 1.0.0

param(
    [Parameter(Mandatory=$true)]
    [string]$Version
)

# Verificar que estamos en un repositorio git
if (-not (Test-Path .git)) {
    Write-Host "❌ Error: No estás en un repositorio Git" -ForegroundColor Red
    exit 1
}

# Verificar formato de versión
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    Write-Host "❌ Error: Formato de versión inválido. Debe ser X.Y.Z (ej: 1.0.0)" -ForegroundColor Red
    exit 1
}

$Tag = "v$Version"

Write-Host "🚀 Creando release v$Version..." -ForegroundColor Cyan
Write-Host ""

# Verificar si el tag ya existe
$tagExists = git rev-parse "$Tag" 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "❌ El tag $Tag ya existe" -ForegroundColor Red
    exit 1
}

# Obtener nombre del plugin
$mainFile = Get-ChildItem -Filter "*.php" | Select-Object -First 1
if (-not $mainFile) {
    Write-Host "❌ No se encontró archivo principal del plugin" -ForegroundColor Red
    exit 1
}

$pluginName = (Select-String -Path $mainFile.FullName -Pattern "Plugin Name:" | Select-Object -First 1).Line
$pluginName = $pluginName -replace '.*Plugin Name:\s*', '' -replace '\s*$'
if (-not $pluginName) {
    $pluginName = "Plugin"
}

Write-Host "Plugin: $pluginName" -ForegroundColor Cyan
Write-Host "Versión: $Version" -ForegroundColor Cyan
Write-Host "Tag: $Tag" -ForegroundColor Cyan
Write-Host ""

# Confirmar
$confirm = Read-Host "¿Continuar con el release? (y/N)"
if ($confirm -ne 'y' -and $confirm -ne 'Y') {
    exit 1
}

# Crear tag
Write-Host "📌 Creando tag..." -ForegroundColor Cyan
git tag -a "$Tag" -m "Release $Version : $pluginName"
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Tag creado: $Tag" -ForegroundColor Green
} else {
    Write-Host "❌ Error al crear tag" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Push del tag
Write-Host "📤 Subiendo tag a GitHub..." -ForegroundColor Cyan
git push origin "$Tag"
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Tag subido" -ForegroundColor Green
} else {
    Write-Host "❌ Error al subir tag" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "✅ Release iniciado!" -ForegroundColor Green
Write-Host ""
Write-Host "GitHub Actions está creando el release y el ZIP automáticamente..." -ForegroundColor Cyan
Write-Host ""
