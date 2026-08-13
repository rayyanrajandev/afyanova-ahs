$ErrorActionPreference = 'Stop'

$phpBat = Join-Path $env:USERPROFILE '.config\herd\bin\php.bat'
$projectDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

if (!(Test-Path $phpBat)) {
    throw "php.bat not found at $phpBat -- adjust the path if your Herd install differs."
}

$restartSettings = New-ScheduledTaskSettingsSet -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
$trigger = New-ScheduledTaskTrigger -AtLogOn

$reverbAction = New-ScheduledTaskAction -Execute $phpBat -Argument 'artisan reverb:start' -WorkingDirectory $projectDir
Register-ScheduledTask -TaskName 'Afyanova Reverb Server' -Action $reverbAction -Trigger $trigger -Settings $restartSettings `
    -Description 'Auto-starts the Laravel Reverb WebSocket server for afyanova-ahs-v2 at login' -Force

$queueAction = New-ScheduledTaskAction -Execute $phpBat -Argument 'artisan queue:work' -WorkingDirectory $projectDir
Register-ScheduledTask -TaskName 'Afyanova Queue Worker' -Action $queueAction -Trigger $trigger -Settings $restartSettings `
    -Description 'Auto-starts the Laravel queue worker for afyanova-ahs-v2 at login (delivers Reverb broadcasts)' -Force

Write-Output "Registered 'Afyanova Reverb Server' and 'Afyanova Queue Worker' scheduled tasks."
Write-Output "They will start automatically the next time you log in."
Write-Output ""
Write-Output "To start them right now without logging out/in:"
Write-Output "  Start-ScheduledTask -TaskName 'Afyanova Reverb Server'"
Write-Output "  Start-ScheduledTask -TaskName 'Afyanova Queue Worker'"
Write-Output ""
Write-Output "To remove them later:"
Write-Output "  Unregister-ScheduledTask -TaskName 'Afyanova Reverb Server' -Confirm:`$false"
Write-Output "  Unregister-ScheduledTask -TaskName 'Afyanova Queue Worker' -Confirm:`$false"
