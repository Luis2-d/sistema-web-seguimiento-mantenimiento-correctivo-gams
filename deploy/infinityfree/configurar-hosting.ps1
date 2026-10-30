param(
    [Parameter(Mandatory=$true)][string]$Url,
    [Parameter(Mandatory=$true)][string]$DbHost,
    [Parameter(Mandatory=$true)][string]$DbName,
    [Parameter(Mandatory=$true)][string]$DbUser
)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$state = Get-Content -LiteralPath (Join-Path $projectRoot 'output/infinityfree/estado.json') -Raw | ConvertFrom-Json
$release = [IO.Path]::GetFullPath($state.release)
$expectedParent = [IO.Path]::GetFullPath((Join-Path $projectRoot 'output/infinityfree')) + [IO.Path]::DirectorySeparatorChar
if (-not $release.StartsWith($expectedParent, [StringComparison]::OrdinalIgnoreCase)) { throw 'La salida debe pertenecer al proyecto.' }
$uri = $null
if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$uri) -or $uri.Scheme -ne 'https' -or $uri.AbsolutePath -ne '/' -or $uri.Query -or $uri.Fragment -or $uri.UserInfo) {
    throw 'Usa la URL base HTTPS, por ejemplo https://seguimiento.rf.gd'
}
foreach ($value in @($DbHost,$DbName,$DbUser)) {
    if ($value -notmatch '^[A-Za-z0-9_.-]+$') { throw 'Los datos MySQL contienen caracteres inesperados.' }
}
function ConvertTo-DotenvValue([string]$value) {
    return '"' + $value.Replace('\','\\').Replace('"','\"').Replace('$','\$').Replace("`r",'').Replace("`n",'\n') + '"'
}
$password = Read-Host 'Contrasena MySQL de InfinityFree (no se mostrara)' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
try {
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    if ([string]::IsNullOrWhiteSpace($plain)) { throw 'La contrasena no puede estar vacia.' }
    $settings = [ordered]@{
        APP_URL=$Url.TrimEnd('/'); DB_HOST=$DbHost; DB_DATABASE=$DbName; DB_USERNAME=$DbUser; DB_PASSWORD=$plain
        GOOGLE_CALENDAR_REDIRECT_URI=($Url.TrimEnd('/') + '/calendario/google/callback')
    }
    $path = Join-Path $state.private '.env.production'
    $content = [IO.File]::ReadAllText($path)
    foreach ($key in $settings.Keys) {
        $replacement = $key + '=' + (ConvertTo-DotenvValue $settings[$key])
        $callback = { param($match) $replacement }.GetNewClosure()
        $evaluator = [Text.RegularExpressions.MatchEvaluator]$callback
        $content = [regex]::Replace($content, ('(?m)^' + $key + '=.*$'), $evaluator)
    }
    $utf8 = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($path, $content, $utf8)
    [IO.File]::WriteAllText((Join-Path $state.htdocs '.env'), $content, $utf8)
    Write-Output 'Configuracion guardada en el paquete. Sube .htaccess primero y luego los archivos de htdocs.'
    Write-Output 'El archivo .env contiene secretos: no lo publiques en GitHub ni en el informe.'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    $plain = $null
}
