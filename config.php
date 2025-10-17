<?php
/**
 * تنظیمات امن ربات تلگرام
 * نسخه تصحیح شده - 12 اکتبر 2025
 * رفع مشکل Security False Positive
 */

// ✅ محافظت از دسترسی مستقیم
if (!defined('BOT_ACCESS')) {
    http_response_code(403);
    die('🚫 Access Denied');
}

// ✅ جلوگیری از اجرای مستقیم
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    http_response_code(403);
    die('🚫 Direct access not allowed');
}

// ✅ تنظیمات امنیتی اولیه
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ✅ تنظیم timezone
date_default_timezone_set('Asia/Tehran');

// ✅ اطلاعات اصلی ربات
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8414852318:AAFhFEhXhtiprRFJfwBEscV619xUTP1_eCw');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('BOT_USERNAME', 'dore_capitantradebot');

// ✅ مدیریت ربات
define('ADMIN_ID', 490288836);

// ✅ کانال‌های الزامی
define('REQUIRED_CHANNELS', [
    '@capitantraderfx',
    '@capitan_nazarat'
]);

define('CHANNEL1', '@capitantraderfx');
define('CHANNEL2', '@capitan_nazarat');

// ✅ مسیرهای فایل‌های امن
define('ADMIN_STATE_FILE', __DIR__ . '/admin_state.json');
define('SUPPORT_STATE_FILE', __DIR__ . '/support_state.json');
define('BTN_CAPTAIN_FILE', __DIR__ . '/btn_captain.json');
define('BTN_ADVANCED_FILE', __DIR__ . '/btn_advanced.json');
define('CAMPAIGN_MESSAGES_FILE', __DIR__ . '/campaign_messages.json');

// ✅ تنظیمات دعوتی و کمپین
define('MIN_REFERRALS_FOR_FREE_COURSE', 0);
define('MIN_REFERRALS_FOR_ADVANCED_DISCOUNT', 20);

// ✅ تنظیمات امنیتی - تصحیح شده
define('SECURITY_ENABLED', true);
define('MAX_REQUESTS_PER_MINUTE', 30);
define('MAX_CALLBACK_PER_MINUTE', 50);
define('LOG_SECURITY_EVENTS', true);
define('AUTO_CLEANUP_ENABLED', true);

// ✅ پیام‌های سیستم
define('WELCOME_MESSAGE', '🎓 به دوره رایگان فارکس کاپیتان ترید خوش آمدید!');
define('COURSE_COMPLETED_MESSAGE', '🎉 تبریک! شما دوره را با موفقیت تکمیل کردید.');

// ✅ URLs مفید
define('WEBSITE_URL', 'https://capitanbours.ir');
define('SUPPORT_URL', 'https://t.me/capitantraderfx');

// ✅ تنظیمات پیشرفته کمپین جشنواره
define('INACTIVE_DAYS_THRESHOLD', 30);
define('INACTIVE_CAMPAIGN_ENABLED', true);
define('DAILY_INACTIVE_CAMPAIGN_LIMIT', 50);

// ✅ لاگ امنیتی پیشرفته
function securityLog($event, $details = []) {
    if (!LOG_SECURITY_EVENTS) return;
    
    $log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
        'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'event' => $event,
        'details' => $details
    ];
    
    // لاگ با فرمت JSON
    error_log("SECURITY: " . json_encode($log, JSON_UNESCAPED_UNICODE));
    
    // اطلاع فوری به ادمین در موارد حساس
    $critical_events = [
        'UNAUTHORIZED_ACCESS', 
        'RATE_LIMIT_EXCEEDED', 
        'SUSPICIOUS_ACTIVITY',
        'CRITICAL_ERROR',
        'FILE_MANIPULATION',
        'SQL_INJECTION_ATTEMPT',
        'XSS_ATTEMPT'
    ];
    
    if (in_array($event, $critical_events)) {
        if (function_exists('sendMessage')) {
            $message = "🚨 <b>Security Alert</b>\n\n";
            $message .= "🔍 Event: <code>$event</code>\n";
            $message .= "🌐 IP: <code>{$log['ip']}</code>\n";
            $message .= "🕒 Time: <code>{$log['timestamp']}</code>\n";
            $message .= "🔗 URI: <code>{$log['request_uri']}</code>\n";
            
            if (!empty($details)) {
                $message .= "📋 Details: <code>" . json_encode($details, JSON_UNESCAPED_UNICODE) . "</code>";
            }
            
            @sendMessage(ADMIN_ID, $message);
        }
    }
}

// ✅ بررسی سلامت سیستم پیشرفته
function systemHealthCheck() {
    $checks = [];
    $start_time = microtime(true);
    
    // بررسی فایل‌های مهم
    $critical_files = [
        'main.php' => 'فایل اصلی ربات',
        'functions.php' => 'توابع اصلی', 
        'admin.php' => 'پنل مدیریت',
        'user.php' => 'مدیریت کاربران',
        'exercises.php' => 'سیستم تمرین‌ها',
        'campaign.php' => 'کمپین اصلی',
        'inactive_campaign.php' => 'کمپین جشنواره',
        'referral.php' => 'سیستم دعوتی',
        'db.php' => 'اتصال دیتابیس'
    ];
    
    foreach ($critical_files as $file => $desc) {
        $path = __DIR__ . '/' . $file;
        $checks['files'][$file] = [
            'exists' => file_exists($path),
            'readable' => file_exists($path) && is_readable($path),
            'writable' => file_exists($path) && is_writable($path),
            'size' => file_exists($path) ? filesize($path) : 0,
            'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
            'description' => $desc
        ];
    }
    
    // بررسی دیتابیس
    try {
        if (isset($GLOBALS['pdo'])) {
            $stmt = $GLOBALS['pdo']->query("SELECT 1");
            $result = $stmt->fetch();
            $checks['database'] = [
                'status' => 'connected', 
                'description' => 'اتصال دیتابیس',
                'response_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
            ];
        } else {
            $checks['database'] = [
                'status' => 'not_initialized', 
                'description' => 'دیتابیس مقداردهی نشده'
            ];
        }
    } catch (Exception $e) {
        $checks['database'] = [
            'status' => 'error', 
            'error' => $e->getMessage(), 
            'description' => 'خطا در دیتابیس'
        ];
        securityLog('DATABASE_ERROR', ['error' => $e->getMessage()]);
    }
    
    // بررسی فضای دیسک
    $free_space = disk_free_space('.');
    $total_space = disk_total_space('.');
    if ($free_space && $total_space) {
        $used_percentage = (($total_space - $free_space) / $total_space) * 100;
        $checks['disk_space'] = [
            'free_mb' => round($free_space / 1024 / 1024, 2),
            'total_mb' => round($total_space / 1024 / 1024, 2),
            'used_percentage' => round($used_percentage, 2),
            'status' => $used_percentage > 90 ? 'critical' : ($used_percentage > 75 ? 'warning' : 'ok')
        ];
    }
    
    // بررسی اندازه error log
    $log_file = __DIR__ . '/error.log';
    if (file_exists($log_file)) {
        $log_size = filesize($log_file);
        $checks['error_log'] = [
            'size_mb' => round($log_size / 1024 / 1024, 2),
            'status' => $log_size > 50*1024*1024 ? 'warning' : 'ok',
            'lines' => $log_size > 0 ? count(file($log_file)) : 0,
            'last_modified' => date('Y-m-d H:i:s', filemtime($log_file))
        ];
    }
    
    // بررسی فایل‌های JSON
    $json_files = ['admin_state.json', 'support_state.json', 'btn_captain.json', 'btn_advanced.json'];
    foreach ($json_files as $json_file) {
        $path = __DIR__ . '/' . $json_file;
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $is_valid = json_decode($content) !== null;
            $checks['json_files'][$json_file] = [
                'exists' => true,
                'valid' => $is_valid,
                'size' => strlen($content),
                'status' => $is_valid ? 'ok' : 'error'
            ];
        } else {
            $checks['json_files'][$json_file] = [
                'exists' => false,
                'status' => 'missing'
            ];
        }
    }
    
    // بررسی permissions
    $checks['permissions'] = [
        'config_secure' => (substr(sprintf('%o', fileperms(__FILE__)), -3) === '600'),
        'directory_readable' => is_readable('.'),
        'directory_writable' => is_writable('.'),
        'log_writable' => is_writable(dirname($log_file))
    ];
    
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $checks['system_load'] = [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2],
            'status' => $load[0] > 2 ? 'high' : 'normal'
        ];
    }
    
    $checks['check_duration'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
    return $checks;
}

// ✅ تابع تمیزکاری فایل‌های موقت پیشرفته
function cleanupTempFiles() {
    $cleaned = 0;
    $total_size = 0;
    
    try {
        // پاک کردن فایل‌های rate limiting قدیمی
        $rate_files = glob(__DIR__ . '/rate_*.tmp');
        foreach ($rate_files as $file) {
            if ((time() - filemtime($file)) > 3600) {
                $size = filesize($file);
                if (unlink($file)) {
                    $cleaned++;
                    $total_size += $size;
                }
            }
        }
        
        // پاک کردن فایل‌های backup قدیمی
        $backup_files = glob(__DIR__ . '/*.bak');
        foreach ($backup_files as $file) {
            if ((time() - filemtime($file)) > 7*24*3600) {
                $size = filesize($file);
                if (unlink($file)) {
                    $cleaned++;
                    $total_size += $size;
                }
            }
        }
        
        // پاک کردن فایل‌های log قدیمی
        $old_logs = glob(__DIR__ . '/error.log.*');
        foreach ($old_logs as $file) {
            if ((time() - filemtime($file)) > 30*24*3600) {
                $size = filesize($file);
                if (unlink($file)) {
                    $cleaned++;
                    $total_size += $size;
                }
            }
        }
        
        if ($cleaned > 0) {
            securityLog('CLEANUP_COMPLETED', [
                'files_cleaned' => $cleaned,
                'space_freed_kb' => round($total_size / 1024, 2)
            ]);
        }
        
    } catch (Exception $e) {
        securityLog('CLEANUP_ERROR', ['error' => $e->getMessage()]);
    }
    
    return ['files' => $cleaned, 'size' => $total_size];
}

// ✅ تشخیص فایل‌های مشکوک - تصحیح شده برای حذف False Positive
function detectSuspiciousFiles() {
    $suspicious = [];
    
    // فقط فایل‌های واقعاً مشکوک را چک کن
    $dangerous_patterns = [
        '*.php.*',         // فایل‌های PHP با پسوند اضافی مثل file.php.txt
        'shell.*',         // فایل‌های shell
        'c99.*',          // backdoor های معروف
        'r57.*',
        'webshell.*',
        '*.php.bak',
        'config.php.old',
        'backup*.sql',     // فایل‌های SQL backup
        'dump*.sql'
    ];
    
    foreach ($dangerous_patterns as $pattern) {
        $files = glob(__DIR__ . '/' . $pattern);
        foreach ($files as $file) {
            $suspicious[] = [
                'file' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'pattern' => $pattern,
                'reason' => 'Suspicious filename pattern'
            ];
        }
    }
    
    // بررسی محتوای مشکوک - فقط فایل‌های غیرمعمول
    $legitimate_files = [
        'main.php', 'config.php', 'functions.php', 'admin.php', 
        'user.php', 'exercises.php', 'campaign.php', 'inactive_campaign.php',
        'referral.php', 'db.php'
    ];
    
    $php_files = glob(__DIR__ . '/*.php');
    foreach ($php_files as $file) {
        $filename = basename($file);
        
        // Skip فایل‌های معتبر
        if (in_array($filename, $legitimate_files)) {
            continue;
        }
        
        if (is_readable($file)) {
            $content = file_get_contents($file);
            
            // فقط کدهای واقعاً مشکوک
            $dangerous_patterns = [
                'eval($_GET',
                'eval($_POST', 
                'eval($_REQUEST',
                'eval(base64_decode(',
                'shell_exec($_',
                'system($_',
                'exec($_',
                'passthru($_',
                'base64_decode($_GET',
                'base64_decode($_POST',
                'file_get_contents("http',
                'curl_exec(curl_init("http'
            ];
            
            foreach ($dangerous_patterns as $danger_pattern) {
                if (stripos($content, $danger_pattern) !== false) {
                    $suspicious[] = [
                        'file' => $filename,
                        'reason' => "Contains dangerous code: $danger_pattern",
                        'type' => 'malicious_content',
                        'size' => strlen($content)
                    ];
                    break;
                }
            }
        }
    }
    
    // فقط اگر واقعاً فایل مشکوک پیدا شد لاگ کن
    if (!empty($suspicious)) {
        securityLog('REAL_SUSPICIOUS_FILES_DETECTED', $suspicious);
    }
    
    return $suspicious;
}

// ✅ تابع کمکی برای بررسی وضعیت کلی سیستم
function getSystemStatus() {
    $health = systemHealthCheck();
    $issues = 0;
    
    if (isset($health['database']['status']) && $health['database']['status'] !== 'connected') {
        $issues++;
    }
    
    if (isset($health['disk_space']['status']) && in_array($health['disk_space']['status'], ['warning', 'critical'])) {
        $issues++;
    }
    
    if (isset($health['error_log']['status']) && $health['error_log']['status'] === 'warning') {
        $issues++;
    }
    
    foreach ($health['files'] ?? [] as $file_check) {
        if (!$file_check['exists'] || !$file_check['readable']) {
            $issues++;
        }
    }
    
    return [
        'status' => $issues === 0 ? 'healthy' : ($issues < 3 ? 'warning' : 'critical'),
        'issues_count' => $issues,
        'last_check' => date('Y-m-d H:i:s'),
        'details' => $health
    ];
}

// ✅ بررسی امنیت خودکار - کاهش فرکانس
if (SECURITY_ENABLED && AUTO_CLEANUP_ENABLED) {
    // اجرای بررسی امنیتی کمتر (2% احتمال)
    if (mt_rand(1, 50) == 1) {
        $suspicious = detectSuspiciousFiles();
        
        if (!empty($suspicious)) {
            securityLog('SECURITY_SCAN_ALERT', [
                'suspicious_count' => count($suspicious),
                'files' => array_column($suspicious, 'file')
            ]);
        }
    }
    
    // تمیزکاری خودکار (5% احتمال)
    if (mt_rand(1, 20) == 1) {
        cleanupTempFiles();
    }
}

// ✅ مانیتورینگ عملکرد
$config_load_time = microtime(true);
register_shutdown_function(function() use ($config_load_time) {
    $execution_time = (microtime(true) - $config_load_time) * 1000;
    
    // لاگ فقط اگر زمان اجرا بیش از 2 ثانیه باشد
    if ($execution_time > 2000) {
        securityLog('SLOW_EXECUTION', [
            'execution_time_ms' => round($execution_time, 2),
            'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2)
        ]);
    }
});

// ✅ اطلاع از بارگذاری موفق config - کاهش verbose logging
if (LOG_SECURITY_EVENTS && mt_rand(1, 10) == 1) {
    error_log("CONFIG: Configuration loaded successfully at " . date('Y-m-d H:i:s') . " - Memory: " . round(memory_get_usage() / 1024, 2) . "KB");
}
?>