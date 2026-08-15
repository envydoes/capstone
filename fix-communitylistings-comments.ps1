$path = ".\admin\communityListings.php"
$content = Get-Content $path -Raw -Encoding UTF8

$replacements = @{
    '/*  "? "? Sidebar  "? "? */' = '/* == Sidebar == */'
    '/*  "? "? Layout  "? "? */' = '/* == Layout == */'
    '/*  "? "? Topbar  "? "? */' = '/* == Topbar == */'
    '/*  "? "? Stat cards  "? "? */' = '/* == Stat cards == */'
    '/*  "? "? Card Grid  "? "? */' = '/* == Card Grid == */'
    '/*  "? "? Listing Card  "? "? */' = '/* == Listing Card == */'
    '/*  "? "? Toolbar  "? "? */' = '/* == Toolbar == */'
    '/*  "? "? Buttons  "? "? */' = '/* == Buttons == */'
    '/*  "? "? Pagination  "? "? */' = '/* == Pagination == */'
    '/*  "? "? Detail View  "? "? */' = '/* == Detail View == */'
    '/*  "? "? Confirm Dialog  "? "? */' = '/* == Confirm Dialog == */'
    '/*  "? "? Lightbox  "? "? */' = '/* == Lightbox == */'
    '/*  "? "? Filter Panel  "? "? */' = '/* == Filter Panel == */'
    '/*  "? "? Toast  "? "? */' = '/* == Toast == */'
    '/*  "? "? Loading Overlay  "? "? */' = '/* == Loading Overlay == */'
    '<!--  .  .  SIDEBAR  .  .  -->' = '<!-- == SIDEBAR == -->'
    '<!--  .  .  MAIN  .  .  -->' = '<!-- == MAIN == -->'
    '<!--  "? "? DETAIL VIEW  "? "? -->' = '<!-- == DETAIL VIEW == -->'
    '<!--  .  .  CONFIRM DIALOG  .  .  -->' = '<!-- == CONFIRM DIALOG == -->'
    '<!--  .  .  LIGHTBOX  .  .  -->' = '<!-- == LIGHTBOX == -->'
    '/*  .  .  All listings data from PHP  .  .  */' = '/* == All listings data from PHP == */'
    '/*  .  .  SIDEBAR  .  .  */' = '/* == SIDEBAR == */'
    '/*  .  .  TOAST  .  .  */' = '/* == TOAST == */'
    '/*  .  .  SELECTION  .  .  */' = '/* == SELECTION == */'
    '/*  .  .  FILTER / SEARCH  .  .  */' = '/* == FILTER / SEARCH == */'
    '/*  .  .  PAGINATION  .  .  */' = '/* == PAGINATION == */'
    '/*  .  .  CONFIRM DIALOG  .  .  */' = '/* == CONFIRM DIALOG == */'
    '/*  .  .  SINGLE DELETE  .  .  */' = '/* == SINGLE DELETE == */'
    '/*  .  .  BULK DELETE  .  .  */' = '/* == BULK DELETE == */'
    '/*  .  .  DETAIL VIEW  .  .  */' = '/* == DETAIL VIEW == */'
    '/*  .  .  LIGHTBOX  .  .  */' = '/* == LIGHTBOX == */'
    "'? '" = "'₱ '"
    "'? '  + l.bussPrice" = "'₱ ' + l.bussPrice"
    "l.owner_phone || l.contact || ' ?`"')" = "l.owner_phone || l.contact || '—')"
    "'Bakery/Caf?'" = "'Bakery/Café'"
    "'2-5':'2 ?`"5 years'" = "'2-5':'2–5 years'"
    "'<span style=`"color:#d1d5db;`"> ?`"</span>'" = "'<span style=`"color:#d1d5db;`">—</span>'"
}

foreach ($old in $replacements.Keys) {
    $new = $replacements[$old]
    if ($content.Contains($old)) {
        $content = $content.Replace($old, $new)
        Write-Host "Replaced: $old"
    } else {
        Write-Host "NOT FOUND (skip): $old" -ForegroundColor Yellow
    }
}

[System.IO.File]::WriteAllText((Resolve-Path $path), $content, (New-Object System.Text.UTF8Encoding $false))