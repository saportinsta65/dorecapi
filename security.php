<?php
/**
 * سیستم امنیتی ربات
 */

define('BOT_ACCESS', true);
require_once 'config.php';

// ✅ بررسی امنیتی روزانه
function dailySecurityCheck() {
    $issues = [];
    
    // بررسی اندازه لاگ
    if (file_exists('error.log') && filesize('error.log') > 10*1024*1024) {
        $issues[] = 'Error log بیش از 10MB';
    }
    
    // بررسی فایل‌های مشکوک
    $suspicious = glob('*.bak') + glob('*.tmp') + glob('shell.*');
    if (!empty($suspicious)) {
        $issues[] = 'فایل‌های مشکوک: ' . implode(', ', $suspicious);
    }
    
    // بررسی permissions
    $files_to_check = ['config.php', 'db.php'];
    foreach ($files_to_check as $file) {
        if (file_exists($file)) {
            $perms = substr(sprintf('%o', fileperms($file)), -3);
            if ($perms !== '600') {
                $issues[] = "Permission غلط برای $file: $perms";
            }
        }
    }
    
    // اگر مشکلی وجود داشت، اطلاع بده
    if (!empty($issues)) {
        securityLog('SECURITY_ISSUES', $issues);
        if (function_exists('sendMessage')) {
            sendMessage(ADMIN_ID, "🚨 مشکلات امنیتی:\n• " . implode("\n• ", $issues));
        }
    }
    
    return empty($issues);
}

// ✅ پاکسازی فایل‌های موقت
function cleanupTempFiles() {
    $temp_files = glob('rate_*.tmp');
    $old_files = array_filter($temp_files, function($file) {
        return (time() - filemtime($file)) > 3600; // فایل‌های بیش از 1 ساعت
    });
    
    foreach ($old_files as $file) {
        unlink($file);
    }
    
    return count($old_files);
}

// اجرا در صورت فراخوانی مستقیم
if (isset($_GET['security_check'])) {
    echo "🔍 Security Check Started...\n";
    
    $is_secure = dailySecurityCheck();
    $cleaned = cleanupTempFiles();
    
    echo $is_secure ? "✅ No issues found\n" : "⚠️ Issues detected\n";
    echo "🧹 Cleaned $cleaned temp files\n";
    echo "✅ Security check completed\n";
}
?>