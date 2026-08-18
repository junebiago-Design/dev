<?php
// Place this in your CRM root as migrate_paths.php, run once, then delete.
require_once 'dashboard/api.php'; // adjust path

$files = readTable('files');
$updated = false;
foreach ($files as &$f) {
    if (isset($f['path']) && strpos($f['path'], '/') !== 0) {
        $f['path'] = '/' . $f['path'];
        $updated = true;
    }
}
if ($updated) {
    syncTable('files', $files);
    echo "Migrated " . count($files) . " file paths.\n";
} else {
    echo "No paths needed migration.\n";
}