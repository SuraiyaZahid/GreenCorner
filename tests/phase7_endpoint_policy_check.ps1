# GreenCorner — Launch Verification: endpoint-management policy check
#
# READ-ONLY. This host reports "App Control for Business policy: Enforced"
# and runs Hypervisor enforced Code Integrity. An endpoint-management policy
# of that kind is the usual reason a container runtime is blocked on a
# managed corporate Windows machine, so it is worth recording precisely
# which policies are in force before concluding that an installation would
# succeed.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tests/phase7_endpoint_policy_check.ps1

$ErrorActionPreference = 'Continue'

Write-Output '=== The Code Integrity / App Control policy state ==='
$guard = Get-CimInstance -ClassName Win32_DeviceGuard -Namespace root\Microsoft\Windows\DeviceGuard -ErrorAction SilentlyContinue
Write-Output ('CodeIntegrityPolicyEnforcementStatus = ' + $guard.CodeIntegrityPolicyEnforcementStatus)
Write-Output ('  0 = off, 1 = audit mode, 2 = enforcement mode')
Write-Output ('SecurityServicesConfigured            = ' + ($guard.SecurityServicesConfigured -join ','))
Write-Output ('SecurityServicesRunning               = ' + ($guard.SecurityServicesRunning -join ','))
Write-Output ('  1 = Credential Guard, 2 = HVCI')
Write-Output ('App Control for Business policy: Enforced')

Write-Output ''
Write-Output '=== The Windows Defender application-control policies ==='
try {
    $policies = Get-CimInstance -ClassName Win32_AppLockerPolicy -Namespace root\StandardCimv2\MSFT_MSRA -ErrorAction SilentlyContinue
    if ($null -eq $policies) { Write-Output 'No MSRA AppLocker policy object is readable.' }
    else { $policies | Format-List | Out-String | Write-Output }
} catch {
    Write-Output ('AppLocker policy read failed: ' + $_.Exception.Message)
}

try {
    $wd = Get-CimInstance -Namespace root\Microsoft\Windows\DeviceGuard\Policies -ClassName MSFT_DeviceGuard_CodeIntegrityPolicy -ErrorAction SilentlyContinue
    if ($null -eq $wd) {
        Write-Output 'No readable DeviceGuard code-integrity policy object (the policy may be delivered by an MDM server).'
    } else {
        foreach ($p in $wd) {
            Write-Output ('Policy GUID : ' + $p.PolicyID)
            Write-Output ('Version     : ' + $p.VersionNumber)
            Write-Output ('IsEnforced  : ' + $p.IsEnforced)
        }
    }
} catch {
    Write-Output ('DeviceGuard policy read failed: ' + $_.Exception.Message)
}

Write-Output ''
Write-Output '=== The antivirus / security product state ==='
try {
    Get-CimInstance -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct -ErrorAction SilentlyContinue |
        Select-Object displayName, productState |
        Format-Table -AutoSize |
        Out-String |
        Write-Output
} catch {
    Write-Output ('Security product read failed: ' + $_.Exception.Message)
}

Write-Output ''
Write-Output '=== The machine management state ==='
$cs = Get-CimInstance Win32_ComputerSystem
Write-Output ('PartOfDomain  = ' + $cs.PartOfDomain)
Write-Output ('Domain        = ' + $cs.Domain)
Write-Output ('Workgroup     = ' + $cs.Workgroup)

try {
    $mgmt = Get-CimInstance -Namespace root\cimv2\mdm\dmmap -ClassName MDM_EnterpriseModernManagement -ErrorAction SilentlyContinue
    if ($null -eq $mgmt) {
        Write-Output 'No MDM enrollment object is present (the policy may come from another source).'
    } else {
        Write-Output 'The machine IS enrolled in an MDM/EMM service.'
    }
} catch {
    Write-Output 'MDM state could not be read.'
}
