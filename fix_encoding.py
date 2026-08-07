import os
import ftfy

EXTENSIONS = ('.php', '.html', '.js', '.css')
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'PHPMailer', 'PHPMailer-7.0.2', 'uploads'}

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
            print(f"SKIP (not valid UTF-8, needs manual check): {path}")
            continue

        fixed = ftfy.fix_text(original)

        if fixed != original:
            changed_files.append(path)
            with open(path, 'w', encoding='utf-8') as f:
                f.write(fixed)
            print(f"FIXED: {path}")

print(f"\nDone. {len(changed_files)} file(s) changed.")