// One‑time migration for existing stages without permissions
$stages = readTable('stages');
$updated = false;
foreach ($stages as &$stage) {
    if (!isset($stage['permissions']) || empty($stage['permissions'])) {
        $stage['permissions'] = [
            'role_system_admin' => [
                'grab' => true, 'drop' => true, 'edit' => true,
                'comment' => true, 'revision' => true, 'upload' => true,
                'stageEdit' => true, 'reorder' => true
            ]
        ];
        $updated = true;
    }
}
if ($updated) {
    syncTable('stages', $stages);
}