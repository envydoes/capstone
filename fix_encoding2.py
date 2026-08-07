import os
import re

EXTENSIONS = ('.php', '.html', '.js', '.css')
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'PHPMailer', 'PHPMailer-7.0.2', 'uploads'}

# Exact known substitutions, longest/most specific first
REPLACEMENTS = [
    ("Made with \ufffdYO\ufffd for", "Made with ❤️ for"),
    ("Made with ?? for", "Made with ❤️ for"),
    ("\ufffd 2026", "© 2026"),
    ("Bakery / Caf\ufffd", "Bakery / Café"),
    ("km\ufffd", "km²"),
    ("1920\ufffd1080", "1920×1080"),
    ("tile's \ufffd to remove", "tile's × to remove"),
    ("9 sections \ufffd Read time", "9 sections • Read time"),
    ("11 sections \ufffd Read time", "11 sections • Read time"),
    ("9 seksyon \ufffd Oras", "9 seksyon • Oras"),
    ("11 seksyon \ufffd Oras", "11 seksyon • Oras"),
    ("2026 \ufffd Barangay", "2026 • Barangay"),
    ("March 2026 \ufffd Barangay", "March 2026 • Barangay"),
    ("Min 8 \ufffd max 72", "Min 8 – max 72"),
    ("--\ufffd</p>", "--°</p>"),
    ("${temp}\ufffd`", "${temp}°`"),
    ("\ufffd?\u201d", " — "),
]

# Regex substitutions for patterns that repeat with variable length
REGEX_REPLACEMENTS = [
    (r"\ufffd\?\"", " – "),                 # '?"' style en-dash marker -> " – "
    (r"\ufffd,\ufffd", "₱"),                 # peso sign placeholder
    (r"(\ufffd[\"'\.\?fo\u00e2\u20ac\^,\-]{1,6}){2,}", "===="),  # repeated decorative comment junk -> clean divider
    (r"\ufffd", ""),                         # anything leftover: strip (last resort, comments only)
]

changed_files = []

for root, dirs, files in os.walk('.'):
    dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
    for name in files:
        if not name.endswith(EXTENSIONS):
            continue
        path = os.path.join(root, name)
        try:
            with open(path, 'r', encoding='utf-8') as f:
                original = f.read()
        except UnicodeDecodeError:
            print(f"SKIP (not valid UTF-8): {path}")
            continue

        text = original
        for old, new in REPLACEMENTS:
            text = text.replace(old, new)
        for pattern, new in REGEX_REPLACEMENTS:
            text = re.sub(pattern, new, text)

        if text != original:
            changed_files.append(path)
            with open(path, 'w', encoding='utf-8') as f:
                f.write(text)
            print(f"FIXED: {path}")

print(f"\nDone. {len(changed_files)} file(s) changed.")