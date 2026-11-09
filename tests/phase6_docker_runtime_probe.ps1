# GreenCorner — Phase 6: container runtime probe
#
# Records whether a container runtime is available on this machine. The
# Docker environment cannot be built or started without one, so the
# finding has to be recorded either way.
#
# Usage:
#   powershell -NoProfile -File tests/phase6_docker_runtime_probe.ps1

Write-Output "=== The container runtimes on the PATH ==="

foreach ($command in 'docker', 'podman', 'nerdctl', 'containerd', 'lima') {
    $found = Get-Command $command -ErrorAction SilentlyContinue

    if ($found) {
        Write-Output ("  " + $command + " FOUND at " + $found.Source)
    } else {
        Write-Output ("  " + $command + " not found")
    }
}

Write-Output ""
Write-Output "=== The container directories ==="

foreach ($directory in 'C:\Program Files\Docker', 'C:\Program Files\Podman', 'C:\ProgramData\DockerDesktop', 'C:\Program Files\RedHat\Podman') {
    if (Test-Path $directory) {
        Write-Output ("  " + $directory + " exists")
    } else {
        Write-Output ("  " + $directory + " does not exist")
    }
}

Write-Output ""
Write-Output "=== The container services ==="

$services = Get-Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match 'docker|container|podman'
}

if ($services) {
    $services | ForEach-Object { Write-Output ("  " + $_.Name + " = " + $_.Status) }
} else {
    Write-Output "  none"
}

Write-Output ""
Write-Output "=== The Windows Subsystem for Linux ==="

$wsl = Get-Command wsl -ErrorAction SilentlyContinue

if ($wsl) {
    Write-Output "  wsl is on the PATH"

    $result = & wsl --status 2>&1 | Out-String

    Write-Output ("  " + $result.Trim())
} else {
    Write-Output "  wsl is not on the PATH"
}

Write-Output ""
Write-Output "=== The conclusion ==="

if (Get-Command docker -ErrorAction SilentlyContinue) {
    Write-Output "  A container runtime IS available. The image can be built."
} else {
    Write-Output "  NO container runtime is available on this machine."
    Write-Output "  The Docker environment can be validated statically but cannot be built or started here."
}
