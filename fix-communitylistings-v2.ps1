$path = ".\admin\communityListings.php"
$content = Get-Content $path -Raw -Encoding UTF8

# Decorative comment banners -> plain == Label == style (matches any non-ASCII junk between markers)
$content = $content -replace '/\*[^\x00-\x7F]+Sidebar[^\x00-\x7F]+\*/', '/* == Sidebar == */'
$content = $content -replace '/\*[^\x00-\x7F]+Layout[^\x00-\x7F]+\*/', '/* == Layout == */'
$content = $content -replace '/\*[^\x00-\x7F]+Topbar[^\x00-\x7F]+\*/', '/* == Topbar == */'
$content = $content -replace '/\*[^\x00-\x7F]+Stat cards[^\x00-\x7F]+\*/', '/* == Stat cards == */'
$content = $content -replace '/\*[^\x00-\x7F]+Card Grid[^\x00-\x7F]+\*/', '/* == Card Grid == */'
$content = $content -replace '/\*[^\x00-\x7F]+Listing Card[^\x00-\x7F]+\*/', '/* == Listing Card == */'
$content = $content -replace '/\*[^\x00-\x7F]+Toolbar[^\x00-\x7F]+\*/', '/* == Toolbar == */'
$content = $content -replace '/\*[^\x00-\x7F]+Buttons[^\x00-\x7F]+\*/', '/* == Buttons == */'
$content = $content -replace '/\*[^\x00-\x7F]+Pagination[^\x00-\x7F]+\*/', '/* == Pagination == */'
$content = $content -replace '/\*[^\x00-\x7F]+Detail View[^\x00-\x7F]+\*/', '/* == Detail View == */'
$content = $content -replace '/\*[^\x00-\x7F]+Confirm Dialog[^\x00-\x7F]+\*/', '/* == Confirm Dialog == */'
$content = $content -replace '/\*[^\x00-\x7F]+Lightbox[^\x00-\x7F]+\*/', '/* == Lightbox == */'
$content = $content -replace '/\*[^\x00-\x7F]+Filter Panel[^\x00-\x7F]+\*/', '/* == Filter Panel == */'
$content = $content -replace '/\*[^\x00-\x7F]+Toast[^\x00-\x7F]+\*/', '/* == Toast == */'
$content = $content -replace '/\*[^\x00-\x7F]+Loading Overlay[^\x00-\x7F]+\*/', '/* == Loading Overlay == */'
$content = $content -replace '<!--[^\x00-\x7F]+SIDEBAR[^\x00-\x7F]+-->', '<!-- == SIDEBAR == -->'
$content = $content -replace '<!--[^\x00-\x7F]+MAIN[^\x00-\x7F]+-->', '<!-- == MAIN == -->'
$content = $content -replace '<!--[^\x00-\x7F]+DETAIL VIEW[^\x00-\x7F]+-->', '<!-- == DETAIL VIEW == -->'
$content = $content -replace '<!--[^\x00-\x7F]+CONFIRM DIALOG[^\x00-\x7F]+-->', '<!-- == CONFIRM DIALOG == -->'
$content = $content -replace '<!--[^\x00-\x7F]+LIGHTBOX[^\x00-\x7F]+-->', '<!-- == LIGHTBOX == -->'
$content = $content -replace '/\*[^\x00-\x7F]+All listings data from PHP[^\x00-\x7F]+\*/', '/* == All listings data from PHP == */'
$content = $content -replace '/\*[^\x00-\x7F]+SELECTION[^\x00-\x7F]+\*/', '/* == SELECTION == */'
$content = $content -replace '/\*[^\x00-\x7F]+FILTER / SEARCH[^\x00-\x7F]+\*/', '/* == FILTER / SEARCH == */'
$content = $content -replace '/\*[^\x00-\x7F]+SINGLE DELETE[^\x00-\x7F]+\*/', '/* == SINGLE DELETE == */'
$content = $content -replace '/\*[^\x00-\x7F]+BULK DELETE[^\x00-\x7F]+\*/', '/* == BULK DELETE == */'

# Peso sign fixes (context-anchored on stable ASCII code around them)
$content = $content -replace "\?\?\S? \+ Number\(l\.aptPrice\)", '`u{20B1} ' + "' + Number(l.aptPrice)"
$content = $content -replace "'[^\x00-\x7F']? '(?=\s*\+\s*Number\(l\.aptPrice\))", "'$([char]0x20B1) '"
$content = $content -replace "'[^\x00-\x7F']? '(?=\s*\+\s*l\.bussPrice)", "'$([char]0x20B1) '"

# Em-dash fallbacks (context-anchored)
$content = $content -replace "\|\|\s*l\.contact\s*\|\|\s