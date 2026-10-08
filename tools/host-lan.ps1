$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script in an administrator PowerShell window to configure Apache and Windows Firewall.'
}
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpBinary = if ($phpCommand) { $phpCommand.Source } else { 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' }
if (-not (Test-Path -LiteralPath $phpBinary)) { throw 'Laragon PHP was not found. Add PHP to PATH.' }
$apacheSites = 'C:\laragon\etc\apache2\sites-enabled'
if (-not (Test-Path -LiteralPath $apacheSites)) { throw 'Laragon Apache sites-enabled directory was not found.' }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'config.local.php'))) { throw 'Configure the database first; see README.md.' }
& $phpBinary (Join-Path $PSScriptRoot 'enable-lan.php')
if ($LASTEXITCODE -ne 0) { throw 'LAN configuration failed.' }
$destination = Join-Path $apacheSites 'inventory.conf'
$source = Join-Path $projectRoot 'deploy\laragon.inventory-lan.conf'
if (Test-Path -LiteralPath $destination) {
    if ((Get-FileHash -LiteralPath $destination).Hash -ne (Get-FileHash -LiteralPath $source).Hash) {
        $backup = "$destination.before-lan.$([DateTime]::Now.ToString('yyyyMMddHHmmssfff')).bak"
        Copy-Item -LiteralPath $destination -Destination $backup
    }
}
Copy-Item -LiteralPath $source -Destination $destination
$ruleName = 'Inventory-LAN-8081'
if (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue) {
    Remove-NetFirewallRule -Name $ruleName
}
New-NetFirewallRule -Name $ruleName -DisplayName 'Inventory LAN (8081)' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8081 -Profile Private -RemoteAddress LocalSubnet | Out-Null
Write-Host 'Setup complete. Stop any PHP development server on port 8081, then reload Apache in Laragon.'
Write-Host 'On other devices, open http://<this-PC-LAN-IP>:8081/ and sign in.'
Write-Host 'Use your existing attendance dashboard username or email and password.'
Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -ne '127.0.0.1' -and $_.IPAddress -notlike '169.254.*' } | Select-Object InterfaceAlias, IPAddress
