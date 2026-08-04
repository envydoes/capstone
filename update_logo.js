const fs = require('fs');
const path = require('path');

function walk(dir) {
    let files;
    try {
        files = fs.readdirSync(dir);
    } catch(err) {
        return;
    }
    
    files.forEach(file => {
        const p = path.join(dir, file);
        if (fs.statSync(p).isDirectory()) {
            if (!['vendor', '.git', 'node_modules', 'uploads', 'assets'].includes(file)) {
                walk(p);
            }
        } else if (p.endsWith('.php')) {
            let text = fs.readFileSync(p, 'utf8');
            if (text.includes('logo.png')) {
                text = text.replace(/logo\.png/g, 'logo2.png');
                // keep type image/png as the file is still PNG
                // text = text.replace(/type="image\/png"/g, 'type="image\/jpeg"');
                fs.writeFileSync(p, text);
                console.log('Updated:', p);
            }
        }
    });
}

walk('.');
console.log('Done mapping PNG to JPG!');