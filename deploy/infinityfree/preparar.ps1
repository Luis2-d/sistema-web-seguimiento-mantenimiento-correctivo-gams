param([string]$ExistingPackage)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$releaseRoot = Join-Path $projectRoot ('output/infinityfree/paquete-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
if ($ExistingPackage) {
    $releaseRoot = (Resolve-Path -LiteralPath $ExistingPackage).Path
    $expectedParent = [IO.Path]::GetFullPath((Join-Path $projectRoot 'output/infinityfree')) + [IO.Path]::DirectorySeparatorChar
    if (-not $releaseRoot.StartsWith($expectedParent, [StringComparison]::OrdinalIgnoreCase)) { throw 'La salida debe pertenecer al proyecto.' }
} elseif (Test-Path -LiteralPath $releaseRoot) { throw 'El directorio de salida ya existe.' }
$htdocs = Join-Path $releaseRoot 'htdocs'
$private = Join-Path $releaseRoot 'privado-NO-SUBIR'
if (-not $ExistingPackage) {
New-Item -ItemType Directory -Path $htdocs, $private -Force | Out-Null

foreach ($dir in @('app','config','database','lang','resources','routes','vendor')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $dir) -Destination (Join-Path $htdocs $dir) -Recurse
}
New-Item -ItemType Directory -Path (Join-Path $htdocs 'bootstrap/cache') -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $projectRoot 'bootstrap') -File | Copy-Item -Destination (Join-Path $htdocs 'bootstrap')
Copy-Item -LiteralPath (Join-Path $projectRoot 'bootstrap/cache/.gitignore') -Destination (Join-Path $htdocs 'bootstrap/cache/.gitignore')
New-Item -ItemType Directory -Path (Join-Path $htdocs 'public') -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $projectRoot 'public') -Force |
    Where-Object { $_.Name -notin @('build','hot','storage') } |
    Copy-Item -Destination (Join-Path $htdocs 'public') -Recurse
foreach ($dir in @('app/private','app/public','framework/cache/data','framework/sessions','framework/views','logs')) {
    $folder = Join-Path $htdocs ('storage/' + $dir)
    New-Item -ItemType Directory -Path $folder -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $folder '.gitignore'), "*`n!.gitignore`n")
}
foreach ($file in @('artisan','composer.json','composer.lock','.env.example')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination (Join-Path $htdocs $file)
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'htdocs.htaccess') -Destination (Join-Path $htdocs '.htaccess')
& php (Join-Path $PSScriptRoot 'configurar.php') env (Join-Path $private '.env.production')
if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar .env.' }

Push-Location -LiteralPath $htdocs
try {
    & composer config optimize-autoloader false
    if ($LASTEXITCODE -ne 0) { throw 'Fallo composer config.' }
    & composer install --no-dev --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { throw 'Fallo composer install.' }
    & composer check-platform-reqs --no-dev
    if ($LASTEXITCODE -ne 0) { throw 'Fallo la comprobacion de PHP.' }
} finally { Pop-Location }

Push-Location -LiteralPath $projectRoot
try {
    & npm.cmd run build -- --outDir (Join-Path $htdocs 'public/build')
    if ($LASTEXITCODE -ne 0) { throw 'Fallo Vite.' }
} finally { Pop-Location }

}

# Solo assets de plugins opcionales que no utiliza este sistema. La copia local
# original no se modifica. Estas rutas superan 1 MB por archivo en el proveedor.
$optionalAssets = @(
    'vendor/almasaeed2010/adminlte/plugins/jqvmap/maps/jquery.vmap.usa.counties.js',
    'vendor/almasaeed2010/adminlte/plugins/pdfmake/pdfmake.js',
    'vendor/almasaeed2010/adminlte/plugins/pdfmake/pdfmake.min.js'
)
foreach ($relative in $optionalAssets) {
    $assetPath = Join-Path $htdocs $relative
    if (Test-Path -LiteralPath $assetPath) { Remove-Item -LiteralPath $assetPath }
}

# No distribuir registros, sesiones ni vistas generadas durante comprobaciones.
foreach ($relative in @('storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs')) {
    $runtimeFolder = [IO.Path]::GetFullPath((Join-Path $htdocs $relative))
    $checkedRoot = [IO.Path]::GetFullPath($htdocs) + [IO.Path]::DirectorySeparatorChar
    if (-not $runtimeFolder.StartsWith($checkedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta temporal fuera del paquete.' }
    Get-ChildItem -LiteralPath $runtimeFolder -File -Recurse -Force |
        Where-Object { $_.Name -ne '.gitignore' } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName }
}

$violations = @()
$files = @(Get-ChildItem -LiteralPath $htdocs -File -Recurse -Force)
foreach ($file in $files) {
    $limit = 10000000
    if ($file.Extension.ToLowerInvariant() -in @('.php','.html','.htm','.js')) { $limit = 1000000 }
    if ($file.Name -eq '.htaccess') { $limit = 10000 }
    if ($file.Length -gt $limit) {
        $violations += [PSCustomObject]@{ file=$file.FullName.Substring($htdocs.Length+1); bytes=$file.Length; limit=$limit }
    }
}
if ($violations.Count -gt 0) {
    $violations | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $releaseRoot 'archivos-excedidos.json') -Encoding UTF8
    throw 'Hay archivos que exceden el limite de InfinityFree. Revisar archivos-excedidos.json.'
}
$oldViolationReport = Join-Path $releaseRoot 'archivos-excedidos.json'
if (Test-Path -LiteralPath $oldViolationReport) { Remove-Item -LiteralPath $oldViolationReport }
$dumpResult = & php (Join-Path $PSScriptRoot 'configurar.php') export (Join-Path $private 'base-datos.sql')
$databaseExported = $LASTEXITCODE -eq 0
if ($databaseExported) {
    [IO.File]::WriteAllText((Join-Path $private 'resumen-base.json'), ($dumpResult -join "`n"))
}
$state = [ordered]@{
    release=$releaseRoot; htdocs=$htdocs; private=$private
    files=$files.Count; bytes=($files | Measure-Object Length -Sum).Sum
    database_exported=$databaseExported; file_limits_ok=$true
    deployment='PENDIENTE: cuenta y subdominio de InfinityFree'
}
[IO.File]::WriteAllText((Join-Path $projectRoot 'output/infinityfree/estado.json'), ($state | ConvertTo-Json -Depth 4))
Write-Output ($state | ConvertTo-Json -Depth 4)
