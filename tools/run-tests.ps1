# FileShare テスト実行スクリプト
# 使い方: pwsh tools\run-tests.ps1
$ErrorActionPreference = 'Stop'

# PATH 上の php を優先し、無ければ winget 導入先にフォールバック。
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
}
if (-not (Test-Path $php)) {
    Write-Error "php.exe が見つかりません。PHP 8.1+ を導入してください。"
}

$plugin  = Join-Path $PSScriptRoot '..\fileshare'
$phpunit = Join-Path $PSScriptRoot 'phpunit.phar'

Push-Location $plugin
try {
    & $php $phpunit --configuration phpunit.xml.dist @args
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
