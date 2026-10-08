param(
    [Parameter(Mandatory=$true)]
    [ValidatePattern('^you2biz_staging_[a-f0-9]{16}$')]
    [string]$Database,
    [ValidateRange(1024,65535)]
    [int]$Port=8097
)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$previousStagingDatabase = $env:YOU2BIZ_STAGING_DB
$stagingSessionDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ('you2biz-staging-sessions-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stagingSessionDirectory | Out-Null
Push-Location $projectDirectory
try {
    $env:YOU2BIZ_STAGING_DB = $Database
    $sessionSetting = 'session.save_path="' + $stagingSessionDirectory.Replace('\','/') + '"'
    & C:\xampp\php\php.exe -d $sessionSetting -d session.name=YOU2BIZ_STAGING -d allow_url_fopen=0 -d disable_functions=mail,fsockopen,pfsockopen,stream_socket_client,curl_exec -S "127.0.0.1:$Port" -t .
} finally {
    $env:YOU2BIZ_STAGING_DB = $previousStagingDatabase
    Pop-Location
}
