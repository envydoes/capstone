<?php
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));

$regex = '/(<div\s+class="[^"]*)rounded-xl([^"]*)(".*?>)(\s*)(<img[^>]*src="[^"]*sumesteLogo\.jpg)/i';

foreach ($files as $file) {
    if ($file->isFile() && pathinfo($file->getFilename(), PATHINFO_EXTENSION) == 'php') {
        $path = $file->getRealPath();
        $content = file_get_contents($path);
        
        $replaced = preg_replace_callback($regex, function($matches) {
            $classLeft = $matches[1];
            $classRight = $matches[2];
            $tagEnd = $matches[3];
            $whitespace = $matches[4];
            $img = $matches[5];
            
            $newClasses = $classLeft . "rounded-full" . $classRight;
            if (strpos($newClasses, 'overflow-hidden') === false) {
                // we want to add overflow-hidden to the class attribute, before the closing quote
                $newClasses = rtrim($newClasses) . ' overflow-hidden';
            }
            
            return $newClasses . $tagEnd . $whitespace . $img;
            
        }, $content, -1, $count);
        
        if ($count > 0) {
            file_put_contents($path, $replaced);
            echo "Updated: $path\n";
        }
    }
}
