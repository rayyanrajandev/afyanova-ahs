$ErrorActionPreference = 'Stop'

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectRoot

if (Test-Path public/hot) {
    Remove-Item public/hot -Force
}

npm run build
php artisan config:clear

Start-Process powershell.exe -ArgumentList @(
    '-NoExit',
    '-Command',
    "Set-Location '$ProjectRoot'; if (Test-Path public/hot) { Remove-Item public/hot -Force }; npm run build:watch"
)

Start-Process powershell.exe -ArgumentList @(
    '-NoExit',
    '-Command',
    "Set-Location '$ProjectRoot'; php -S 127.0.0.1:8080 -t public"
)

Start-Sleep -Seconds 3

$Cloudflared = Join-Path $ProjectRoot 'tools/cloudflared.exe'
if (Test-Path $Cloudflared) {
    try {
        & $Cloudflared --version | Out-Null
        & $Cloudflared tunnel --url http://127.0.0.1:8080
        exit
    } catch {
        Write-Warning 'cloudflared is present but did not run on this Windows installation. Falling back to SSH tunnel.'
    }
}

ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=NUL -o ServerAliveInterval=60 -R 80:127.0.0.1:8080 nokey@localhost.run
