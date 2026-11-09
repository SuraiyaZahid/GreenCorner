# GreenCorner — Launch Verification: virtualization capability check
#
# READ-ONLY. Determines whether the CPU virtualization support that a
# container runtime needs is actually available to this Windows host.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests/phase7_virtualization_check.ps1

$ErrorActionPreference = 'Continue'

function Decode([string]$text) {
    if ($null -eq $text) { return '' }
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($text)
    return ([System.Text.Encoding]::ASCII.GetString($bytes) -replace "`0", '')
}

Write-Output '=== The systeminfo virtualization and Hyper-V block ==='
$sysinfo = & systeminfo.exe 2>&1 | Out-String
$decoded = Decode $sysinfo
$lines = $decoded -split "`r?`n"
for ($i = 45; $i -lt 80; $i++) {
    if ($i -lt $lines.Count) {
        $n = $i.ToString()
        Write-Output ($n.PadLeft(3) + ': ' + $lines[$i])
    }
}

Write-Output ''
Write-Output '=== The virtualization-based security state ==='
$guard = Get-CimInstance -ClassName Win32_DeviceGuard -Namespace root\Microsoft\Windows\DeviceGuard -ErrorAction SilentlyContinue
if ($null -eq $guard) {
    Write-Output 'Win32_DeviceGuard did not report. The class may need elevation.'
} else {
    foreach ($prop in $guard.PSObject.Properties) {
        $value = $prop.Value
        if ($value -is [array]) { $value = $value -join ',' }
        Write-Output ($prop.Name + ' = ' + $value)
    }
}

Write-Output ''
Write-Output '=== The processor virtualization flags ==='
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
Write-Output ('Name                               = ' + $cpu.Name)
Write-Output ('VirtualizationFirmwareEnabled      = ' + $cpu.VirtualizationFirmwareEnabled)
Write-Output ('SecondLevelAddressTranslationExt.   = ' + $cpu.SecondLevelAddressTranslationExtensions)
Write-Output ('VMMonitorModeExtensions             = ' + $cpu.VMMonitorModeExtensions)

Write-Output ''
Write-Output '=== The interpretation ==='
Write-Output 'HypervisorPresent=True together with "Virtualization-based security: Running"'
Write-Output 'means the Windows hypervisor is already active on this machine, so the'
Write-Output 'processor virtualization support IS exposed to the guest. The'
Write-Output 'VirtualizationFirmwareEnabled=False reading from Win32_Processor is the'
Write-Output 'normal result when a hypervisor is already running: the firmware flags'
Write-Output 'are hidden from the guest because the hypervisor owns the hardware.'
