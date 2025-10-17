<?php
/**
 * مدیریت و تمیزکاری Error Log
 * اجرا: روزانه توسط cron job
 */

$log_file = __DIR__ . '/error.log';
$max_size = 10 * 1024 * 1024; // 10MB
$backup_count = 3;

function cleanLogFile($log_file, $max_size, $backup_count) {
    if (!file_exists($log_file)) {
        return false;
    }
    
    $file_size = filesize($log_file);
    
    if ($file_size < $max_size) {
        return false; // فایل هنوز کوچک است
    }
    
    // پشتیبان‌گیری
    for ($i = $backup_count - 1; $i > 0; $i--) {
        $old_backup = $log_file . '.' . $i;
        $new_backup = $log_file . '.' . ($i + 1);
        
        if (file_exists($old_backup)) {
            if ($i == $backup_count - 1) {
                unlink($old_backup); // حذف قدیمی‌ترین
            } else {
                rename($old_backup, $new_backup);
            }
        }
    }
    
    // جابجایی فایل فعلی
    rename($log_file, $log_file . '.1');
    
    // ساخت فایل جدید
    touch($log_file);
    chmod($log_file, 0644);
    
    echo "Log file rotated. Size was: " . number_format($file_size / 1024 / 1024, 2) . " MB\n";
    return true;
}

// اجرای تمیزکاری
if (cleanLogFile($log_file, $max_size, $backup_count)) {
    echo "Log rotation completed.\n";
} else {
    echo "No log rotation needed.\n";
}
?>