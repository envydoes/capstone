$files = Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\|\\node_modules\\' }

$newCode = "require_once __DIR__ . '/../config/db_connection.php';`r`n"
$newCodeRoot = "require_once __DIR__ . '/config/db_connection.php';`r`n"

# Pattern A: multi-var one-liner + connect + optional if block
$patternA = '(?s)\$host\s*=\s*"o7jpqmin0zgconui4xtnfju6";.*?if\s*\(\s*!\$conn\s*\)\s*\{.*?\}\r?\n?'

# Pattern B/C: direct mysqli_connect one-liner (single or double quotes, empty or "''" password)
$patternBC = "\`$conn\s*=\s*mysqli_connect\(['`"]o7jpqmin0zgconui4xtnfju6['`"]\s*,\s*['`"]root['`"]\s*,\s*['`"]{1,2}[''`"]{0,2}['`"]{1,2}\s*,\s*['`"]sumeste_db['`"]\);\r?\n?"

# Pattern D: new mysqli(...)
$patternD = "\`$conn\s*=\s*new mysqli\(['`"]o7jpqmin0zgconui4xtnfju6['`"]\s*,\s*['`"]root['`"]\s*,\s*['`"]{0,2}\s*,\s*['`"]sumeste_db['`"]\);\r?\n?"

foreach ($f in $files) {
    $content = Get-Content $f.FullName -Raw
    $depth = ($f.FullName -split '\\capstone\\')[1] -split '\\' | Measure-Object
    $prefix = if ($depth.Count -gt 1) { '../config/db_connection.php' } else { 'config/db_connection.php' }
    $replacement = "require_once __DIR__ . '/$prefix';`r`n"

    $new = $content -replace $patternA, $replacement
    $new = $new -replace $patternBC, $replacement
    $new = $new -replace $patternD, $replacement

    if ($new -cne $content) {
        Set-Content -Path $f.FullName -Value $new -NoNewline
        Write-Host "Fixed: $($f.FullName)"
    }
}