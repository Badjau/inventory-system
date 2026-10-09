$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Open PowerShell as Administrator, then run this script.'
}
$address = Get-NetIPAddress -AddressFamily IPv4 -IPAddress '165.168.10.183'
if ($address.PrefixLength -ne 24) { throw 'Expected subnet mask 255.255.255.0; check the current network.' }
Set-NetConnectionProfile -InterfaceIndex $address.InterfaceIndex -NetworkCategory Private
$ruleName = 'Inventory-LAN-165-8081'
if (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue) {
    Remove-NetFirewallRule -Name $ruleName
}
New-NetFirewallRule -Name $ruleName -DisplayName 'Inventory LAN 165.168.10.0/24 (8081)' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8081 -Profile Private -RemoteAddress '165.168.10.0/24' | Out-Null
Write-Host 'Firewall configured. Start MySQL and reload Apache in Laragon.'
Write-Host 'Open http://165.168.10.183:8081/ and sign in.'
