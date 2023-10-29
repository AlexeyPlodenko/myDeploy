<?php

$vars = []; // this would be replaced with an array of variables
$filePath = ''; // this would contain a path to the file

$varsCount = count($vars);
$phpFilePath = __FILE__;
echo "Running $phpFilePath with $varsCount \$vars and \$filePath \"$filePath\"...\n";

if (!$vars) {
    echo "There were no variables supplied. Aborting the script execution.\n";
    exit(0);
}

// checking the setup
if (!$filePath) {
    echo "Error. The file path variable \$filePath is empty.\n";
    exit(1);
}
if (!is_file($filePath)) {
    echo "Error. The file \"$filePath\" does not exist.";
    exit(2);
}
if (!is_readable($filePath)) {
    echo "Error. The file \"$filePath\" is not readable. Check the file permissions.";
    exit(3);
}
if (!is_writable($filePath)) {
    echo "Error. The file \"$filePath\" is not writable. Check the file permissions.";
    exit(4);
}

// applying the variables to the file
$fileData = file_get_contents($filePath);
$fileData = str_replace(array_keys($vars), array_values($vars), $fileData);
file_put_contents($filePath, $fileData);

echo "Done.";
exit(0);
