from pathlib import Path

repls = {
    'landing.php': [
        ('<a href="busaptListing.php"   class="nav-link">Business</a>', '<a href="busaptListing.php?type=business"   class="nav-link">Business</a>'),
        ('<a href="busaptListing.php#apartment"  class="nav-link">Apartment</a>', '<a href="busaptListing.php?type=apartment"  class="nav-link">Apartment</a>'),
    ],
    'nonResident/nonresidentLanding.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'infoSecurity/terms.php': [
        ('<a href="../busaptListing.php"              class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business"              class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment"             class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment"             class="nav-link">Apartment</a>'),
    ],
    'infoSecurity/dataProtection.php': [
        ('<a href="../busaptListing.php"              class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business"              class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment"             class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment"             class="nav-link">Apartment</a>'),
    ],
    'nonResident/nonresidentProfile.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'nonResident/nonresidentEditProfile.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'resident/myProfile.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'resident/residentEditProfile.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'resident/residentEditPassword.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'resident/residentLanding.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
    'resident/residentPanel.php': [
        ('<a href="../busaptListing.php" class="nav-link">Business</a>', '<a href="../busaptListing.php?type=business" class="nav-link">Business</a>'),
        ('<a href="../busaptListing.php#apartment" class="nav-link">Apartment</a>', '<a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>'),
    ],
}
root = Path('.')
changed = []
for rel, patterns in repls.items():
    path = root / rel
    text = path.read_text(encoding='utf-8')
    original = text
    for old, new in patterns:
        text = text.replace(old, new)
    if text != original:
        path.write_text(text, encoding='utf-8')
        changed.append(rel)
print('UPDATED', changed)
