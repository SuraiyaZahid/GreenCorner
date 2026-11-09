# GreenCorner — PHP syntax check across every source file.
#
# Checks every .php file outside .tools/ (the bundled PHP binary) and
# outside .git/. The result is what the CI static job's syntax step does,
# run locally.

$root = 'E:\GreenCorner'
$php = Join-Path $root '.tools\php\php.exe'

$files = Get-ChildItem -Path $root -Recurse -Filter '*.php' -File |
    Where-Object {
        $_.FullName -notlike '*\.tools\*' -and
        $_.FullName -notlike '*\.git\*'
    } |
    Sort-Object FullName

$failed = 0

foreach ($file in $files) {
    $output = & $php -l $file.FullName 2>&1

    if ($LASTEXITCODE -ne 0) {
        Write-Output ("SYNTAX ERROR: " + $file.FullName)
        Write-Output $output
        $failed = $failed + 1
    }
}

Write-Output ("Files checked: " + $files.Count)
Write-Output ("Syntax errors: " + $failed)

if ($failed -gt 0) {
    exit 1
}

exit 0
