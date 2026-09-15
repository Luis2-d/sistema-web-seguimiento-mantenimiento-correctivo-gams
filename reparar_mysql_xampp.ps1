$ErrorActionPreference = 'Stop'

$mysqlRoot = 'C:\xampp\mysql'
$dataDir = Join-Path $mysqlRoot 'data'
$templateDir = Join-Path $mysqlRoot 'backup'
$myIni = Join-Path $mysqlRoot 'bin\my.ini'
$mysqlExe = Join-Path $mysqlRoot 'bin\mysql.exe'
$mysqlAdminExe = Join-Path $mysqlRoot 'bin\mysqladmin.exe'
$mysqldExe = Join-Path $mysqlRoot 'bin\mysqld.exe'
$projectDir = 'C:\xampp\htdocs\Gestion de Seguimiento'
$safetyBackup = Join-Path $projectDir 'backups\mysql_data_corrupta_20260825_1925'
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$damagedDir = Join-Path $mysqlRoot "data_danada_$stamp"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell.exe -Verb RunAs -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`""
    )
    exit
}

Write-Host '=== Reparacion segura de MySQL/XAMPP ===' -ForegroundColor Cyan

if (([IO.Path]::GetFullPath($dataDir)) -ne 'C:\xampp\mysql\data') {
    throw "Ruta de datos inesperada: $dataDir"
}
if (-not (Test-Path -LiteralPath $safetyBackup)) {
    throw "No se encontro el respaldo de seguridad: $safetyBackup"
}
if (-not (Test-Path -LiteralPath $templateDir)) {
    throw "No se encontro la plantilla limpia de XAMPP: $templateDir"
}

Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2

if (Test-Path -LiteralPath $dataDir) {
    Write-Host "Conservando los datos danados en: $damagedDir"
    Move-Item -LiteralPath $dataDir -Destination $damagedDir
}

Write-Host 'Instalando el directorio de datos limpio...'
New-Item -ItemType Directory -Path $dataDir | Out-Null
Copy-Item -Path (Join-Path $templateDir '*') -Destination $dataDir -Recurse -Force

$config = Get-Content -LiteralPath $myIni
$config = $config | Where-Object { $_ -notmatch '^\s*#?\s*innodb[_-]force[_-]recovery\s*=' }
Set-Content -LiteralPath $myIni -Value $config -Encoding ASCII

Write-Host 'Iniciando MariaDB...'
$server = Start-Process -FilePath $mysqldExe `
    -ArgumentList "--defaults-file=`"$myIni`" --standalone" `
    -WorkingDirectory (Join-Path $mysqlRoot 'bin') `
    -WindowStyle Hidden -PassThru

$ready = $false
for ($i = 0; $i -lt 20; $i++) {
    Start-Sleep -Seconds 1
    & $mysqlAdminExe -h 127.0.0.1 -P 3306 -u root ping *> $null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    if ($server.HasExited) { break }
}
if (-not $ready) {
    throw 'MariaDB limpio no pudo iniciar. Revise C:\xampp\mysql\data\mysql_error.log.'
}

Write-Host 'Creando la base gestion_seguimiento...'
& $mysqlExe -h 127.0.0.1 -P 3306 -u root -e 'CREATE DATABASE IF NOT EXISTS gestion_seguimiento CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la base de datos.' }

Write-Host 'Ejecutando migraciones y datos iniciales...'
Push-Location $projectDir
try {
    & 'C:\xampp\php\php.exe' artisan migrate:fresh --seed --force
    if ($LASTEXITCODE -ne 0) { throw 'Laravel no pudo crear las tablas.' }
    & 'C:\xampp\php\php.exe' artisan optimize:clear
} finally {
    Pop-Location
}

Write-Host ''
Write-Host 'REPARACION COMPLETADA' -ForegroundColor Green
Write-Host 'MySQL esta activo en el puerto 3306.'
Write-Host "Los datos originales permanecen en: $damagedDir"
Write-Host 'Usuarios: admin@educacion.test, tecnico@educacion.test y director@educacion.test'
Write-Host 'Contrasena inicial: password'
Read-Host 'Presione Enter para cerrar'
