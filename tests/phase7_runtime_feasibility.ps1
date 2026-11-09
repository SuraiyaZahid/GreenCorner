# GreenCorner — Launch Verification: container-runtime feasibility probe
#
# READ-ONLY. Inspects the host to determine whether a container runtime can be
# provisioned here, and reports the exact blocker. It installs nothing, starts
# nothing, and changes no configuration.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests/phase7_runtime_feasibility.ps1

$ErrorActionPreference = 'Continue'

function Section([string]$title) {
    Write-Output ''
    Write-Output "=== $title ==="
}

# Decode the UTF-16LE output that modern Windows CLIs emit.
function Decode([string]$text) {
    if ($null -eq $text) { return '' }
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($text)
    return ([System.Text.Encoding]::ASCII.GetString($bytes) -replace "`0", '')
}

Section 'The WSL subsystem'
foreach ($arg in @('--status', '--version', '--list --verbose')) {
    $parts = $arg -split ' '
    $out = & wsl.exe @parts 2>&1 | Out-String
    Write-Output ("wsl " + $arg + ":")
    Write-Output (Decode $out)
}

Section 'The Windows edition and build'
$os = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
Write-Output ("EditionID      = " + $os.EditionID)
Write-Output ("DisplayVersion = " + $os.DisplayVersion)
Write-Output ("CurrentBuild   = " + $os.CurrentBuild)

Section 'The processor and its virtualization support'
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
Write-Output ("Name                        = " + $cpu.Name)
Write-Output ("LogicalProcessors          = " + $cpu.NumberOfLogicalProcessors)
Write-Output ("VirtualizationFirmwareEnabled = " + $cpu.VirtualizationFirmwareEnabled)
Write-Output ("SecondLevelAddressTranslationExtensions = " + $cpu.SecondLevelAddressTranslationExtensions)
Write-Output ("VMMonitorModeExtensions     = " + $cpu.VMMonitorModeExtensions)

$cs = Get-CimInstance Win32_ComputerSystem
Write-Output ("HypervisorPresent          = " + $cs.HypervisorPresent)
Write-Output ("TotalPhysicalMemoryGB       = " + [math]::Round($cs.TotalPhysicalMemory / 1GB, 1))

Section 'The Hyper-V requirement check from systeminfo'
$sysinfo = & systeminfo.exe 2>&1 | Out-String
$match = [regex]::Match($sysinfo, '(?s)Hyper-V Requirements(.*?)(?:\r?\n\r?\n|\z)')
if ($match.Success) {
    Write-Output (Decode $match.Groups[1].Value)
} else {
    Write-Output 'The Hyper-V requirements block was not found in the systeminfo output.'
}

Section 'The elevation state of this session'
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
Write-Output ("IsAdministrator = " + $isAdmin)

Section 'The optional features that a container runtime needs'
try {
    Get-WindowsOptionalFeature -Online |
        Where-Object { $_.FeatureName -match 'Linux|Hyper-V|Containers|VirtualMachinePlatform' } |
        Select-Object FeatureName, State |
        Format-Table -AutoSize |
        Out-String |
        Write-Output
} catch {
    Write-Output ("Reading the optional-feature list failed: " + $_.Exception.Message)
    Write-Output 'This operation requires an elevated session.'
}

Section 'The conclusion'
Write-Output 'See the report. No runtime was installed and no configuration was changed by this probe.'
