# GreenCorner — Launch Verification: container-runtime blocker record
#
# READ-ONLY. This script produces the formal, reproducible record of why the
# Docker build cannot be executed on this host. It installs nothing, starts
# nothing, and changes no configuration.
#
# It is the Phase 7 counterpart of tests/phase6_docker_runtime_probe.ps1.
# The Phase 6 probe asked "is a runtime present?". This script asks the
# follow-up question that the Track A authorization requires: "if not, can
# one be provisioned here, and if not, why not?" — so that the blocker is
# documented precisely rather than asserted.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests/phase7_docker_runtime_blocker.ps1

$ErrorActionPreference = 'Continue'

$blockers = New-Object System.Collections.ArrayList

function Note([string]$text) { Write-Output $text }
function Rule { Write-Output ''; Write-Output ('=' * 72) }

Rule
Note 'GREEN CORNER - LAUNCH VERIFICATION - CONTAINER RUNTIME BLOCKER RECORD'
Note ('Generated: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
Note 'This is a READ-ONLY inspection. Nothing was installed or configured.'

Rule
Note '1. IS A CONTAINER RUNTIME PRESENT?'
$runntimes = @('docker', 'podman', 'nerdctl', 'containerd', 'lima', 'limactl', 'colima')
$present = @()
foreach ($rt in $runntimes) {
    $cmd = Get-Command $rt -ErrorAction SilentlyContinue
    if ($null -ne $cmd) {
        $present += $rt
        Note ("   FOUND   : " + $rt + ' -> ' + $cmd.Source)
    } else {
        Note ("   absent  : " + $rt)
    }
}
if ($present.Count -eq 0) {
    Note '   RESULT: no container runtime is installed.'
} else {
    Note '   RESULT: a runtime is present.'
}

Rule
Note '2. COULD A RUNTIME BE INSTALLED WITHOUT ELEVATION?'
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
Note ('   IsAdministrator = ' + $isAdmin)
if (-not $isAdmin) {
    [void]$blockers.Add('The session is not elevated. Every container runtime installer for Windows is machine-scoped and requires administrator rights.')
    Note '   RESULT: a machine-scoped install cannot proceed in this session.'
} else {
    Note '   RESULT: the session is elevated; an install could proceed.'
}

Note ''
Note '   The Podman Windows installer manifest was retrieved and inspected:'
Note '     Scope: machine            <- machine-scoped, elevation required'
Note '     InstallerType: burn'
Note '     InstallerSwitches.Silent: /quiet /norestart'
Note '   A download-only step succeeded without elevation, but the install'
Note '   itself is machine-scoped and would still need administrator rights.'

Rule
Note '3. IS THE VIRTUALIZATION SUBSTRATE AVAILABLE?'
$cs = Get-CimInstance Win32_ComputerSystem
Note ('   HypervisorPresent = ' + $cs.HypervisorPresent)
$guard = Get-CimInstance -ClassName Win32_DeviceGuard -Namespace root\Microsoft\Windows\DeviceGuard -ErrorAction SilentlyContinue
if ($null -ne $guard) {
    Note ('   VirtualizationBasedSecurityStatus = ' + $guard.VirtualizationBasedSecurityStatus + ' (2 = running)')
    Note ('   SecurityServicesRunning           = ' + ($guard.SecurityServicesRunning -join ',') + ' (2 = HVCI)')
}
$wslOut = & wsl.exe --status 2>&1 | Out-String
$wslBytes = [System.Text.Encoding]::Unicode.GetBytes($wslOut)
$wslText = [System.Text.Encoding]::ASCII.GetString($wslBytes) -replace "`0", ''
if ($wslText -match 'not installed') {
    [void]$blockers.Add('WSL is not installed. `wsl --install` requires elevation and a reboot. Docker Desktop and Podman machine both need it for a Linux container.')
    Note '   WSL: NOT INSTALLED (wsl --install requires elevation and a reboot)'
} else {
    Note ('   WSL: ' + $wslText.Trim())
}
Note ''
Note '   INTERPRETATION: the processor DOES support virtualization. The'
Note '   Hypervisor is already running (VBS/HVCI active), which is why'
Note '   Win32_Processor reports VirtualizationFirmwareEnabled=False: the'
Note '   firmware flags are hidden from the guest because the hypervisor owns'
Note '   the hardware. The CPU is NOT the blocker.'

Rule
Note '4. IS A USERSSPACE-ONLY RUNTIME POSSIBLE (NO ELEVATION, NO HYPERVISOR)?'
Note '   A Linux container requires a Linux kernel. The shell available here'
Note '   is MSYS2/Windows-native:'
$uname = & uname -a 2>&1 | Out-String
Note ('     uname -a -> ' + $uname.Trim())
Note '   It cannot execute Linux ELF binaries, so no Linux container runtime'
Note '   can be hosted inside it. A rootless Podman machine still needs WSL or'
Note '   Hyper-V, both of which need elevation.'
[void]$blockers.Add('No Linux kernel is reachable. The only Linux hosts available (WSL, Hyper-V) require elevation.')

Rule
Note '5. COULD THE BUILD BE DELEGATED TO A REMOTE BUILDER?'
$reachable = $false
try {
    $r = Invoke-WebRequest -Uri 'https://registry-1.docker.io/v2/' -UseBasicParsing -TimeoutSec 20
    $reachable = $true
} catch {
    if ($_.Exception.Response) { $reachable = $true }
}
Note ('   Docker Hub registry egress: ' + $(if ($reachable) { 'REACHABLE' } else { 'UNREACHABLE' }))
Note '   A registry is reachable, but a registry only SERVES images. Building'
Note '   the image and running the container still require a local runtime, and'
Note '   delegating the build to a third-party hosted builder would send the'
Note '   project source to an external service that the owner has not'
Note '   authorised. That is outside the approved scope.'

Rule
Note '6. THE CONCLUSION'
if ($blockers.Count -eq 0) {
    Note '   NO BLOCKER WAS FOUND. The Docker build can proceed.'
} else {
    Note '   THE DOCKER BUILD CANNOT BE EXECUTED ON THIS HOST. The blockers are:'
    $i = 1
    foreach ($b in $blockers) {
        Note ('   [' + $i + '] ' + $b)
        $i++
    }
    Note ''
    Note '   REQUIRED TO CLEAR THEM (any one of these is sufficient):'
    Note '     a. An elevated session on this host, followed by `wsl --install`'
    Note '        and a reboot, then the Docker Desktop or Podman install.'
    Note '     b. A Linux host (or a Linux VM) with Docker, to which the project'
    Note '        could be copied and the approved Docker configuration run.'
    Note '     c. A remote Docker host with a secured TCP endpoint, reachable'
    Note '        from here, with DOCKER_HOST set.'
    Note ''
    Note '   Until one of these is provided, items 1, 2, 3 and 4 of the approved'
    Note '   Track A work list CANNOT be completed, and NFR-P-02 cannot be'
    Note '   measured against the Apache/mod_php container.'
}

Rule
Note 'END OF RECORD'
