<?php
/**
 * اسکریپت backup خودکار
 */

$backup_name = 'backup_' . date('Ymd_His') . '.tar.gz';
$exclude_files = [
    'config.php',
    'error.log',
    'db.php'
];

// لیست فایل‌های قابل backup
$files_to_backup = [];
$all_files = glob('*.{php,json}', GLOB_BRACE);

foreach ($all_files as $file) {
    if (!in_array($file, $exclude_files)) {
        $files_to_backup[] = $file;
    }
}

// ایجاد آرشیو
if (!empty($files_to_backup)) {
    $command = "tar -czf $backup_name " . implode(' ', $files_to_backup);
    $result = shell_exec($command);
    
    if (file_exists($backup_name)) {
        $size = round(filesize($backup_name) / 1024, 2);
        echo "✅ Backup created successfully!\n";
        echo "📁 File: $backup_name\n";
        echo "📊 Size: {$size} KB\n";
        echo "📂 Files included: " . count($files_to_backup) . "\n";
    } else {
        echo "❌ Backup failed!\n";
    }
} else {
    echo "❌ No files to backup!\n";
}
?>