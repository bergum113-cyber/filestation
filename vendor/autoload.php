<?php
/**
 * phpseclib3 autoloader (FileStation 내장)
 */
spl_autoload_register(function ($class) {
    // phpseclib3 네임스페이스
    $prefix = 'phpseclib3\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/phpseclib/phpseclib/phpseclib/' . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});
