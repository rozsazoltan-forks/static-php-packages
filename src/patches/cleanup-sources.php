<?php

/**
 * Cleanup patch for static-php-cli builds
 * Removes source directories after each library is built to save disk space during CI builds
 */

use SPC\store\FileSystem;

if (preg_match('/after-library\[(.*)\]-build/', patch_point(), $match)) {
    $lib_name = $match[1];
    $sourcePath = SOURCE_PATH . '/' . $lib_name;

    if (is_dir($sourcePath)) {
        echo "Cleaning up source directory for library: {$lib_name}\n";
        FileSystem::removeDir($sourcePath);
    }
}
if (preg_match('/after-shared-ext\[(.*)\]-build/', patch_point(), $match)) {
    $ext_name = $match[1];
    $sourcePath = SOURCE_PATH . '/php-src/ext/' . $ext_name;

    if (is_dir($sourcePath)) {
        echo "Cleaning up source directory for shared extension: {$ext_name}\n";
        FileSystem::removeDir($sourcePath);
    }
}
