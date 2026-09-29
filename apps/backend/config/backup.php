<?php

/*
|--------------------------------------------------------------------------
| Backup Configuration (SEC-008 / PILOT-003)
|--------------------------------------------------------------------------
|
| Object-storage disks (config/filesystems.php) whose contents are archived
| by `backup:run --type=STORAGE` in addition to the local public disk. Each
| disk's files are stored in the archive under "<disk>/". A disk that cannot
| be listed fails the backup job rather than being skipped silently.
| report_exports is excluded: exports are temporary and expire (RPT-017).
|
*/

$disks = env('BACKUP_OBJECT_STORAGE_DISKS', 'person_photos,face_samples,event_snapshots');

return [

    'object_storage_disks' => array_values(array_filter(array_map('trim', explode(',', (string) $disks)))),

];
