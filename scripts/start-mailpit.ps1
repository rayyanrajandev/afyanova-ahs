$ErrorActionPreference = 'Stop'

$mailpitPath = Join-Path $PSScriptRoot '..\tools\mailpit\mailpit.exe'
$mailpitPath = [System.IO.Path]::GetFullPath($mailpitPath)

if (!(Test-Path $mailpitPath)) {
    throw "Mailpit executable not found at $mailpitPath"
}

$existing = Get-Process -Name 'mailpit' -ErrorAction SilentlyContinue

if ($existing) {
    Write-Output "Mailpit is already running."
    Write-Output "Web UI: http://127.0.0.1:8025"
    Write-Output "SMTP: 127.0.0.1:1025"
    exit 0
}

$command = "& `"$mailpitPath`" --listen 127.0.0.1:8025 --smtp 127.0.0.1:1025"

$process = Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoExit', '-Command', $command -PassThru

Write-Output "Mailpit window opened."
Write-Output "PID: $($process.Id)"
Write-Output "Web UI: http://127.0.0.1:8025"
Write-Output "SMTP: 127.0.0.1:1025"
