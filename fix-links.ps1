$files = Get-ChildItem -Recurse -Filter *.php

foreach ($f in $files) {
    $content = Get-Content $f.FullName -Raw
    $new = $content -creplace 'nonresident/nonresidentLanding\.php', 'nonResident/nonresidentLanding.php'
    $new = $new -creplace 'nonResidentLanding\.php', 'nonresidentLanding.php'
    if ($new -ne $content) {
        Set-Content -Path $f.FullName -Value $new -NoNewline
        Write-Host "Fixed: $($f.FullName)"
    }
}