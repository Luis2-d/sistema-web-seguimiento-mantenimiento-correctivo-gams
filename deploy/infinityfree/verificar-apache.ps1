$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$state = Get-Content -LiteralPath (Join-Path $projectRoot 'output/infinityfree/estado.json') -Raw | ConvertFrom-Json
$htdocs = [IO.Path]::GetFullPath($state.htdocs)
$expected = [IO.Path]::GetFullPath((Join-Path $projectRoot 'output/infinityfree')) + [IO.Path]::DirectorySeparatorChar
if (-not $htdocs.StartsWith($expected, [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta de paquete invalida.' }
$envPath = Join-Path $htdocs '.env'
$originalEnv = if (Test-Path -LiteralPath $envPath) { [IO.File]::ReadAllBytes($envPath) } else { $null }
$privateProbe = Join-Path $htdocs 'vendor/__deployment_private_probe.css'
if (Test-Path -LiteralPath $privateProbe) { throw 'Ya existe la prueba de archivo privado.' }
$webRoot = $htdocs.Replace('\','/')
$qaDirectory = Join-Path $projectRoot 'output/infinityfree/qa'
New-Item -ItemType Directory -Path $qaDirectory -Force | Out-Null
$qaRoot = $qaDirectory.Replace('\','/')
$conf = @"
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:18087
ServerName localhost
PidFile "$qaRoot/apache.pid"
ErrorLog "$qaRoot/apache-error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule env_module modules/mod_env.so
LoadModule headers_module modules/mod_headers.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPIniDir "C:/xampp/php"
TypesConfig "conf/mime.types"
DirectoryIndex index.php
DocumentRoot "$webRoot"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory "$webRoot">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
"@
$configPath = Join-Path $qaDirectory 'apache.conf'
[IO.File]::WriteAllText($configPath, $conf)
& 'C:/xampp/apache/bin/httpd.exe' -t -f $configPath
if ($LASTEXITCODE -ne 0) { throw 'Configuracion Apache invalida.' }
$process = $null
try {
    [IO.File]::WriteAllText($privateProbe,'Este archivo privado no debe servirse por HTTP.')
    & php (Join-Path $projectRoot 'deploy/infinityfree/configurar.php') env-local-qa $envPath
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo generar el entorno de prueba.' }
    $process = Start-Process -FilePath 'C:/xampp/apache/bin/httpd.exe' -ArgumentList @('-X','-f',('"' + $configPath + '"')) -WindowStyle Hidden -PassThru
    $login = $null
    for ($attempt=0; $attempt -lt 15; $attempt++) {
        try { $login = Invoke-WebRequest -Uri 'http://127.0.0.1:18087/login' -UseBasicParsing -TimeoutSec 3; break } catch { Start-Sleep -Milliseconds 300 }
    }
    if ($null -eq $login -or $login.StatusCode -ne 200 -or $login.Content -notmatch 'name="email"') { throw 'El login no responde correctamente.' }
    $checks = @([ordered]@{path='/login';status=200;ok=$true})
    foreach ($path in @('/.env','/composer.json','/storage/logs/laravel.log','/vendor/autoload.php','/vendor/__deployment_private_probe.css','/public/.env')) {
        $status = 0
        try { $response = Invoke-WebRequest -Uri ('http://127.0.0.1:18087' + $path) -UseBasicParsing -TimeoutSec 5; $status = [int]$response.StatusCode }
        catch { if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode } }
        $ok = $status -in @(403,404)
        $checks += [ordered]@{path=$path;status=$status;ok=$ok}
        if (-not $ok) { throw ('Archivo privado accesible o comprobacion fallida: ' + $path) }
    }
    foreach ($path in @(
        '/vendor/adminlte/dist/css/adminlte.min.css',
        '/vendor/fontawesome-free/css/all.min.css',
        '/vendor/icheck-bootstrap/icheck-bootstrap.min.css',
        '/vendor/overlayScrollbars/css/OverlayScrollbars.min.css',
        '/vendor/jquery/jquery.min.js',
        '/vendor/bootstrap/js/bootstrap.bundle.min.js',
        '/vendor/adminlte/dist/js/adminlte.min.js',
        '/vendor/fontawesome-free/webfonts/fa-solid-900.woff2'
    )) {
        $response = Invoke-WebRequest -Uri ('http://127.0.0.1:18087' + $path) -UseBasicParsing -TimeoutSec 8
        $valid = $response.StatusCode -eq 200 -and $response.Headers['Content-Type'] -notmatch 'html|json'
        $checks += [ordered]@{path=$path;status=[int]$response.StatusCode;content_type=$response.Headers['Content-Type'];ok=$valid}
        if (-not $valid) { throw ('Recurso estatico no servido correctamente: ' + $path) }
    }
    $manifest = Get-Content -LiteralPath (Join-Path $htdocs 'public/build/manifest.json') -Raw | ConvertFrom-Json
    $asset = $manifest.'resources/js/tomtom-maps.js'.file
    $response = Invoke-WebRequest -Uri ('http://127.0.0.1:18087/build/' + $asset) -UseBasicParsing -TimeoutSec 5
    if ($response.StatusCode -ne 200) { throw 'El recurso compilado no responde.' }
    $checks += [ordered]@{path=('/build/' + $asset);status=200;ok=$true}
    [IO.File]::WriteAllText((Join-Path $qaDirectory 'apache-resultados.json'), ($checks | ConvertTo-Json -Depth 4))
    Write-Output ($checks | ConvertTo-Json -Depth 4)
} finally {
    if ($null -ne $process -and -not $process.HasExited) { Stop-Process -Id $process.Id -Force }
    if ($null -ne $originalEnv) { [IO.File]::WriteAllBytes($envPath,$originalEnv) }
    elseif (Test-Path -LiteralPath $envPath) { Remove-Item -LiteralPath $envPath }
    if (Test-Path -LiteralPath $privateProbe) { Remove-Item -LiteralPath $privateProbe }
}
