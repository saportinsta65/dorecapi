<?php
/**
 * فایل اصلی ربات تلگرام
 * نسخه کامل، امن و تصحیح شده - 12 اکتبر 2025
 * با رفع مشکلات callback تمرین‌ها و امنیت پیشرفته
 */

// ✅ تعریف دسترسی امن
define('BOT_ACCESS', true);

// ✅ تنظیمات امنیتی و اولیه
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Tehran');

// ✅ تابع debug برای main.php
function debugLog($message, $data = null) {
    $log_message = "[MAIN_DEBUG] $message";
    if ($data !== null) {
        $log_message .= " - Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    error_log($log_message);
}

// ✅ بررسی IP تلگرام (فقط برای پیام‌های واقعی)
$telegram_ips = [
    '149.154.160.0/20',
    '91.108.4.0/22', 
    '91.108.56.0/22',
    '149.154.164.0/22',
    '149.154.168.0/22',
    '149.154.172.0/22'
];

function isValidTelegramIP($ip) {
    global $telegram_ips;
    foreach ($telegram_ips as $range) {
        if (ipInRange($ip, $range)) {
            return true;
        }
    }
    return false;
}

function ipInRange($ip, $range) {
    if (strpos($range, '/') !== false) {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }
    return false;
}

// ✅ Rate Limiting ساده
function checkRateLimit($user_id, $max = 30) {
    $file = "rate_$user_id.tmp";
    $now = time();
    
    if (file_exists($file)) {
        $requests = array_filter(
            explode(',', file_get_contents($file)),
            function($time) use ($now) { return ($now - intval($time)) < 60; }
        );
        
        if (count($requests) >= $max) {
            error_log("🚨 Rate limit exceeded for user: $user_id");
            return false;
        }
        
        $requests[] = $now;
    } else {
        $requests = [$now];
    }
    
    file_put_contents($file, implode(',', $requests));
    return true;
}

// ✅ بررسی امنیتی اولیه
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_cron = isset($_GET['campaign_cron']) || isset($_GET['inactivity_cron']) || isset($_GET['inactive_campaign_cron']);

// اگر کرون نیست، بررسی IP (اختیاری - فقط لاگ می‌کنیم)
if (!$is_cron && $client_ip && !isValidTelegramIP($client_ip)) {
    // فقط لاگ می‌کنیم، بلاک نمی‌کنیم
    error_log("⚠️ Request from non-Telegram IP: $client_ip");
}

// ✅ محدودیت User-Agent برای درخواست‌های عادی
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!$is_cron && empty($user_agent)) {
    error_log("⚠️ Request without User-Agent from IP: $client_ip");
}

// بارگذاری فایل‌های اصلی
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'admin.php';
require_once 'user.php';
require_once 'referral.php';
require_once 'exercises.php';
require_once 'campaign.php';
require_once 'inactive_campaign.php'; // ✅ کمپین جشنواره

try {
    // **🔥 هندل کرون جاب کمپین اصلی**
    if (isset($_GET['campaign_cron']) || (php_sapi_name() === 'cli' && isset($argv) && in_array('campaign_cron', $argv))) {
        
        // تنظیم header برای مرورگر
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
        }
        
        error_log("🚀 Campaign cron triggered at " . date('Y-m-d H:i:s'));
        echo "🚀 Campaign Cron Started\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n";
        echo "=================================\n\n";
        
        try {
            $result = processCampaignNotifications();
            
            if ($result) {
                error_log("✅ Campaign cron completed successfully");
                echo "✅ Campaign processing completed successfully\n";
                http_response_code(200);
            } else {
                error_log("❌ Campaign cron failed");
                echo "❌ Campaign processing failed\n";
                http_response_code(500);
            }
        } catch (Exception $e) {
            error_log("❌ Campaign cron exception: " . $e->getMessage());
            echo "❌ Campaign processing error: " . $e->getMessage() . "\n";
            http_response_code(500);
        }
        
        echo "\n=================================\n";
        echo "🏁 Campaign Cron Finished\n";
        exit;
    }

    // **🔔 هندل کرون جاب یادآوری غیرفعال‌ها**
    if (isset($_GET['inactivity_cron']) || (php_sapi_name() === 'cli' && isset($argv) && in_array('inactivity_cron', $argv))) {
        
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
        }
        
        error_log("🔔 Inactivity reminder cron triggered at " . date('Y-m-d H:i:s'));
        echo "🔔 Inactivity Reminder Cron Started\n";
        
        try {
            $count = sendInactivityReminders();
            error_log("📤 Inactivity reminders sent to $count users");
            echo "📤 Inactivity reminders sent to $count users\n";
        } catch (Exception $e) {
            error_log("❌ Inactivity cron error: " . $e->getMessage());
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
        
        echo "🏁 Inactivity Reminder Cron Finished\n";
        exit;
    }

    // **🎉 هندل کرون جاب کمپین جشنواره (کاربران غیرفعال)** ✅ جدید
    if (isset($_GET['inactive_campaign_cron']) || (php_sapi_name() === 'cli' && isset($argv) && in_array('inactive_campaign_cron', $argv))) {
        
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
        }
        
        error_log("🎉 Inactive campaign cron triggered at " . date('Y-m-d H:i:s'));
        echo "🎉 Inactive Campaign Cron Started\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n";
        echo "=================================\n\n";
        
        try {
            $result = processInactiveCampaigns();
            
            if ($result !== false) {
                error_log("✅ Inactive campaign cron completed successfully - Started: $result campaigns");
                echo "✅ Inactive campaign processing completed successfully\n";
                echo "Started: $result campaigns\n";
                http_response_code(200);
            } else {
                error_log("❌ Inactive campaign cron failed");
                echo "❌ Inactive campaign processing failed\n";
                http_response_code(500);
            }
        } catch (Exception $e) {
            error_log("❌ Inactive campaign cron exception: " . $e->getMessage());
            echo "❌ Error: " . $e->getMessage() . "\n";
            http_response_code(500);
        }
        
        echo "\n=================================\n";
        echo "🏁 Inactive Campaign Cron Finished\n";
        exit;
    }

    // دریافت آپدیت از تلگرام
    $input = file_get_contents('php://input');
    
    // اگر درخواست از مرورگر است و input خالی است
    if (empty($input) && !empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo "🤖 Telegram Bot is running...\n";
        echo "🛡️ Security: Enabled\n";
        echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
        echo "🌐 IP: " . $client_ip . "\n";
        echo "✅ Status: OK";
        exit;
    }
    
    if (empty($input)) {
        error_log("No input received");
        exit;
    }
    
    $update = json_decode($input, true);
    
    if (!$update) {
        error_log("Invalid JSON received");
        exit;
    }

    // لاگ آپدیت برای دیباگ (فقط در حالت توسعه)
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Update received: " . $input);
    }

    // --- هندل کال‌بک دکمه‌های inline ---
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $data = $callback['data'];
        $admin_id = $callback['from']['id'];
        $callback_id = $callback['id'];

        debugLog("Callback received", ['data' => $data, 'user_id' => $admin_id]);

        // ✅ بررسی Rate Limiting برای callback ها
        if (!checkRateLimit($admin_id, 50)) {
            // پاسخ محدودیت
            $url = API_URL . "answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callback_id,
                'text' => "تعداد درخواست‌های شما زیاد است. لطفاً کمی صبر کنید.",
                'show_alert' => true
            ]);
            file_get_contents($url);
            exit;
        }

        // هندل پاسخ پشتیبانی
        if (strpos($data, 'support_reply_') === 0 && $admin_id == ADMIN_ID) {
            $reply_user_id = str_replace('support_reply_', '', $data);
            $support_state = loadSupportState();
            $support_state['admin_reply_to'] = $reply_user_id;
            saveSupportState($support_state);
            
            // پاسخ callback
            $url = API_URL . "answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callback_id,
                'text' => "اکنون پاسخ خود را برای کاربر ارسال کنید.",
                'show_alert' => false
            ]);
            file_get_contents($url);
            
            sendMessage(ADMIN_ID, "✏️ پیام متنی، عکس یا ویس خود را ارسال کنید تا برای کاربر فرستاده شود.\nبرای لغو، عبارت 'لغو' یا /cancel را بفرستید.");
            exit;
        }

        // ✅ هندل callback های تمرین‌ها - فقط از exercises.php
        if (preg_match('/^exercise_(accept|reject|view)_([0-9]+)_([0-9]+)$/', $data)) {
            debugLog("Exercise callback detected", ['data' => $data]);
            
            // فقط از exercises.php استفاده کن
            if (function_exists('handleExerciseCallback') && handleExerciseCallback($data)) {
                debugLog("Exercise callback handled successfully");
                // پاسخ callback
                $url = API_URL . "answerCallbackQuery?" . http_build_query([
                    'callback_query_id' => $callback_id,
                    'text' => "عملیات انجام شد.",
                    'show_alert' => false
                ]);
                file_get_contents($url);
                exit;
            } else {
                debugLog("Exercise callback failed");
                // اگر نتونست handle کنه
                $url = API_URL . "answerCallbackQuery?" . http_build_query([
                    'callback_query_id' => $callback_id,
                    'text' => "خطا در پردازش درخواست.",
                    'show_alert' => true
                ]);
                file_get_contents($url);
                exit;
            }
        }

        // پاسخ عمومی callback
        $url = API_URL . "answerCallbackQuery?" . http_build_query([
            'callback_query_id' => $callback_id,
            'text' => "دستور پردازش شد.",
            'show_alert' => false
        ]);
        file_get_contents($url);
        exit;
    }

    // بررسی وجود پیام
    if (!isset($update['message'])) {
        error_log("No message in update");
        exit;
    }

    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $user_id = $message['from']['id'];

    // اعتبارسنجی اولیه
    if (!isValidUserId($user_id)) {
        error_log("Invalid user ID: $user_id");
        exit;
    }

    // ✅ بررسی Rate Limiting برای کاربران
    if (!checkRateLimit($user_id, 30)) {
        sendMessage($chat_id, "⚠️ تعداد پیام‌های شما زیاد است. لطفاً کمی صبر کنید.");
        exit;
    }

    // ✅ بررسی طول پیام (محافظت از spam)
    if (strlen($text) > 4000) {
        sendMessage($chat_id, "⚠️ پیام شما خیلی طولانی است. لطفاً پیام کوتاه‌تری ارسال کنید.");
        exit;
    }

    // --- دستورات ادمین ویژه ---
    
    // دستور دستی برای ارسال یادآوری جمعی دوره (ادمین)
    if ($text === "/remind_all" && $user_id == ADMIN_ID) {
        $users = loadUsers();
        $reminded_count = 0;
        
        foreach ($users as $u) {
            if (($u['type'] ?? '') == 'free' || getReferralCount($u['id']) >= MIN_REFERRALS_FOR_FREE_COURSE) {
                if (!hasSeenAllSessions($u['id'])) {
                    if (sendCourseReminder($u['id'])) {
                        $reminded_count++;
                    }
                }
            }
        }
        
        sendMessage(ADMIN_ID, "📢 یادآوری به <b>$reminded_count</b> کاربر ناقص دوره ارسال شد.");
        exit;
    }

    // دستور تست کمپین اصلی (ادمین)
    if ($text === "/test_campaign" && $user_id == ADMIN_ID) {
        $result = startCampaign(ADMIN_ID);
        $message_text = $result ? "✅ کمپین تست شروع شد" : "❌ خطا در شروع کمپین تست";
        sendMessage(ADMIN_ID, $message_text);
        exit;
    }

    // ✅ دستور تست کمپین جشنواره (ادمین) - جدید
    if ($text === "/test_inactive_campaign" && $user_id == ADMIN_ID) {
        $test_msg = "🧪 <b>تست کمپین جشنواره</b>\n\n";
        
        global $pdo;
        $stmt = $pdo->query("
            SELECT id, first_name, last_activity, seen_sessions
            FROM users 
            WHERE 
                (type = 'free' OR type = 'user')
                AND last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND (campaign_started IS NULL OR campaign_started = 0)
                AND (inactive_campaign_started IS NULL OR inactive_campaign_started = 0)
            LIMIT 1
        ");
        
        $test_user = $stmt->fetch();
        
        if ($test_user) {
            $days_inactive = floor((time() - strtotime($test_user['last_activity'])) / 86400);
            $test_msg .= "کاربر نمونه پیدا شد:\n";
            $test_msg .= "🆔 ID: {$test_user['id']}\n";
            $test_msg .= "👤 نام: {$test_user['first_name']}\n";
            $test_msg .= "⏰ غیرفعال: $days_inactive روز\n\n";
            
            if (function_exists('isEligibleForInactiveCampaign') && isEligibleForInactiveCampaign($test_user['id'])) {
                $test_msg .= "✅ واجد شرایط است\n\n";
                $test_msg .= "آیا کمپین براش شروع بشه؟\n";
                $test_msg .= "دستور: /confirm_test_{$test_user['id']}";
            } else {
                $test_msg .= "❌ واجد شرایط نیست";
            }
        } else {
            $test_msg .= "❌ هیچ کاربر واجد شرایطی پیدا نشد";
        }
        
        sendMessage(ADMIN_ID, $test_msg);
        exit;
    }
    
    // ✅ تایید تست کمپین جشنواره (ادمین) - جدید
    if (preg_match('/^\/confirm_test_([0-9]+)$/', $text, $matches) && $user_id == ADMIN_ID) {
        $test_user_id = $matches[1];
        
        sendMessage(ADMIN_ID, "🔄 در حال شروع کمپین تست...");
        
        if (function_exists('startInactiveCampaign')) {
            $result = startInactiveCampaign($test_user_id);
            $msg = $result ? "✅ کمپین تست شروع شد!" : "❌ خطا در شروع کمپین";
        } else {
            $msg = "❌ تابع startInactiveCampaign یافت نشد";
        }
        
        sendMessage(ADMIN_ID, $msg);
        exit;
    }

    // دستور تست کرون جاب (ادمین) - ✅ بروزرسانی شده
    if ($text === "/test_cron" && $user_id == ADMIN_ID) {
        $campaign_result = processCampaignNotifications();
        $inactivity_result = sendInactivityReminders();
        
        // ✅ تست کمپین جشنواره
        $inactive_campaign_result = false;
        if (function_exists('processInactiveCampaigns')) {
            $inactive_campaign_result = processInactiveCampaigns();
        }
        
        $stats_message = "🧪 <b>نتیجه تست کرون جاب‌ها:</b>\n\n";
        $stats_message .= "📧 کمپین اصلی: " . ($campaign_result ? "✅ موفق" : "❌ ناموفق") . "\n";
        $stats_message .= "🔔 یادآوری غیرفعال‌ها: $inactivity_result پیام ارسال شد\n";
        $stats_message .= "🎉 کمپین جشنواره: " . ($inactive_campaign_result !== false ? "✅ $inactive_campaign_result کمپین شروع شد" : "❌ ناموفق") . "\n";
        $stats_message .= "⏰ زمان: " . date('Y-m-d H:i:s');
        
        sendMessage(ADMIN_ID, $stats_message);
        exit;
    }

    // دستور آمار سیستم (ادمین)
    if ($text === "/stats" && $user_id == ADMIN_ID) {
        $stats = getSystemStats();
        
        $stats_message = "📊 <b>آمار سیستم:</b>\n\n";
        $stats_message .= "👥 کل کاربران: <b>{$stats['total_users']}</b>\n";
        $stats_message .= "🟢 فعال امروز: <b>{$stats['active_users_today']}</b>\n";
        $stats_message .= "🎓 کل جلسات: <b>{$stats['total_sessions']}</b>\n";
        $stats_message .= "📧 کمپین‌های فعال: <b>{$stats['active_campaigns']}</b>\n";
        $stats_message .= "⏰ زمان سرور: " . date('Y-m-d H:i:s');
        
        sendMessage(ADMIN_ID, $stats_message);
        exit;
    }

    // ✅ دستور چک امنیت (ادمین) - جدید
    if ($text === "/security" && $user_id == ADMIN_ID) {
        if (function_exists('systemHealthCheck')) {
            $health = systemHealthCheck();
            
            $security_msg = "🛡️ <b>گزارش امنیت سیستم:</b>\n\n";
            
            // بررسی فایل‌های مهم
            $security_msg .= "📁 <b>فایل‌های اصلی:</b>\n";
            foreach ($health as $key => $value) {
                if (is_array($value) && isset($value['exists'])) {
                    $icon = $value['exists'] ? '✅' : '❌';
                    $security_msg .= "$icon $key\n";
                }
            }
            
            // بررسی دیتابیس
            if (isset($health['database'])) {
                $db_icon = $health['database']['status'] === 'connected' ? '✅' : '❌';
                $security_msg .= "\n🗄️ <b>دیتابیس:</b> $db_icon {$health['database']['status']}\n";
            }
            
            // بررسی فضای دیسک
            if (isset($health['disk_space'])) {
                $disk_icon = $health['disk_space']['status'] === 'ok' ? '✅' : '⚠️';
                $security_msg .= "\n💾 <b>فضای دیسک:</b> $disk_icon {$health['disk_space']['used_percentage']}% استفاده شده\n";
            }
            
            $security_msg .= "\n⏰ زمان بررسی: " . date('Y-m-d H:i:s');
        } else {
            $security_msg = "❌ تابع systemHealthCheck یافت نشد";
        }
        
        sendMessage(ADMIN_ID, $security_msg);
        exit;
    }

    // --- ثبت بازدید جلسات برای یادآوری ---
    $sessions = loadSessions();
    if (!empty($sessions)) {
        foreach ($sessions as $sess) {
            if ($text == $sess['title']) {
                if (function_exists('markSessionSeen')) {
                    markSessionSeen($user_id, $sess['title']);
                }
                error_log("Session marked as seen: {$sess['title']} by user $user_id");
                break;
            }
        }
    }

    // ثبت آخرین فعالیت کاربر (غیر از ادمین)
    if ($user_id != ADMIN_ID) {
        updateLastActivity($user_id);
    }

    // هندل ادمین و کاربر
    if (handleAdmin($message, $chat_id, $text, $user_id)) {
        error_log("Message handled by admin handler for user $user_id");
        exit;
    }

    if (handleUser($message, $chat_id, $text, $user_id)) {
        error_log("Message handled by user handler for user $user_id");
        exit;
    }

    // پیام راهنما برای پیام‌های نامشخص
    sendMessage($chat_id, "❔ لطفا یکی از گزینه‌های منو را انتخاب کنید.");
    error_log("Unknown message from user $user_id: $text");

} catch (Exception $e) {
    error_log("❌ Critical error in main.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // ✅ لاگ امنیتی در صورت خطای جدی
    if (function_exists('securityLog')) {
        securityLog('CRITICAL_ERROR', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    
    // اطلاع به ادمین در صورت خطای جدی
    if (defined('ADMIN_ID')) {
        try {
            sendMessage(ADMIN_ID, "🚨 <b>خطای جدی در ربات:</b>\n\n<code>" . $e->getMessage() . "</code>\n\n⏰ زمان: " . date('Y-m-d H:i:s'));
        } catch (Exception $e2) {
            error_log("Failed to send error notification to admin: " . $e2->getMessage());
        }
    }
    
    // پاسخ HTTP مناسب
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        echo "Internal Server Error";
    }
}
?>      try {
            $result = processCampaignNotifications();
            echo $result ? "✅ Success\n" : "❌ Failed\n";
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
        
        echo "🏁 Finished\n";
        exit;
    }

    // **🔔 کرون جاب یادآوری**
    if (isset($_GET['inactivity_cron'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "🔔 Inactivity Cron Started\n";
        
        try {
            $count = sendInactivityReminders();
            echo "📤 Sent to $count users\n";
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
        
        echo "🏁 Finished\n";
        exit;
    }

    // **🎉 کرون جاب کمپین جشنواره**
    if (isset($_GET['inactive_campaign_cron'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "🎉 Inactive Campaign Cron Started\n";
        
        try {
            $result = processInactiveCampaigns();
            echo "✅ Started: $result campaigns\n";
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
        
        echo "🏁 Finished\n";
        exit;
    }

    // دریافت آپدیت از تلگرام
    $input = file_get_contents('php://input');
    
    // نمایش وضعیت برای مرورگر
    if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo "🤖 <b>Telegram Bot Status</b>\n\n";
        echo "✅ Status: <b>Fully Operational</b>\n";
        echo "⏰ Time: <b>" . date('Y-m-d H:i:s') . "</b>\n";
        echo "🌐 IP: <b>" . $client_ip . "</b>\n\n";
        
        // آمار سریع
        try {
            $stats = getSystemStats();
            echo "📊 <b>Stats:</b>\n";
            echo "👥 Users: {$stats['total_users']}\n";
            echo "🟢 Active Today: {$stats['active_users_today']}\n";
            echo "🎓 Sessions: {$stats['total_sessions']}\n";
            echo "🚀 Campaigns: {$stats['active_campaigns']}\n";
        } catch (Exception $e) {
            echo "📊 Stats: Error\n";
        }
        
        exit;
    }
    
    if (empty($input)) {
        exit;
    }
    
    $update = json_decode($input, true);
    if (!$update) {
        exit;
    }

    // --- هندل callback ها ---
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $data = $callback['data'];
        $user_id = $callback['from']['id'];
        $callback_id = $callback['id'];

        mainLog("Callback: $data from user $user_id");

        // بررسی rate limit
        if (!checkRateLimit($user_id, 50)) {
            $url = API_URL . "answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callback_id,
                'text' => "درخواست‌های شما زیاد است.",
                'show_alert' => true
            ]);
            file_get_contents($url);
            exit;
        }

        // پاسخ پشتیبانی
        if (strpos($data, 'support_reply_') === 0 && $user_id == ADMIN_ID) {
            $reply_user_id = str_replace('support_reply_', '', $data);
            $support_state = loadSupportState();
            $support_state['admin_reply_to'] = $reply_user_id;
            saveSupportState($support_state);
            
            $url = API_URL . "answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callback_id,
                'text' => "حالت پاسخ فعال شد.",
                'show_alert' => false
            ]);
            file_get_contents($url);
            
            sendMessage(ADMIN_ID, "✏️ پیام خود را برای کاربر ارسال کنید.\nلغو: /cancel");
            exit;
        }

        // callback های تمرین
        if (preg_match('/^exercise_(accept|reject|view)_([0-9]+)_([0-9]+)$/', $data)) {
            $result = false;
            
            if (function_exists('handleExerciseCallback')) {
                $result = handleExerciseCallback($data);
            }
            
            $url = API_URL . "answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callback_id,
                'text' => $result ? "عملیات انجام شد." : "خطا در پردازش.",
                'show_alert' => false
            ]);
            file_get_contents($url);
            exit;
        }

        // پاسخ عمومی
        $url = API_URL . "answerCallbackQuery?" . http_build_query([
            'callback_query_id' => $callback_id,
            'text' => "دستور پردازش شد.",
            'show_alert' => false
        ]);
        file_get_contents($url);
        exit;
    }

    // بررسی وجود پیام
    if (!isset($update['message'])) {
        exit;
    }

    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $user_id = $message['from']['id'];

    // اعتبارسنجی user_id
    if (!is_numeric($user_id) || $user_id <= 0) {
        exit;
    }

    // بررسی rate limit
    if (!checkRateLimit($user_id, 30)) {
        sendMessage($chat_id, "⚠️ تعداد پیام‌های شما زیاد است. لطفاً صبر کنید.");
        exit;
    }

    // بررسی طول پیام
    if (strlen($text) > 4000) {
        sendMessage($chat_id, "⚠️ پیام شما خیلی طولانی است.");
        exit;
    }

    // --- دستورات ادمین ویژه ---
    
    // تست کمپین
    if ($text === "/test_campaign" && $user_id == ADMIN_ID) {
        $result = startCampaign(ADMIN_ID);
        $msg = $result ? "✅ کمپین تست شروع شد" : "❌ خطا در شروع کمپین";
        sendMessage(ADMIN_ID, $msg);
        exit;
    }

    // آمار سیستم
    if ($text === "/stats" && $user_id == ADMIN_ID) {
        try {
            $stats = getSystemStats();
            $msg = "📊 <b>آمار سیستم</b>\n\n";
            $msg .= "👥 کاربران: <b>{$stats['total_users']}</b>\n";
            $msg .= "🟢 فعال امروز: <b>{$stats['active_users_today']}</b>\n";
            $msg .= "🎓 جلسات: <b>{$stats['total_sessions']}</b>\n";
            $msg .= "🚀 کمپین‌ها: <b>{$stats['active_campaigns']}</b>\n";
            $msg .= "⏰ زمان: " . date('Y-m-d H:i:s');
            sendMessage(ADMIN_ID, $msg);
        } catch (Exception $e) {
            sendMessage(ADMIN_ID, "❌ خطا در دریافت آمار: " . $e->getMessage());
        }
        exit;
    }

    // تست کرون جاب‌ها
    if ($text === "/test_cron" && $user_id == ADMIN_ID) {
        $campaign_result = processCampaignNotifications();
        $inactivity_result = sendInactivityReminders();
        $inactive_result = processInactiveCampaigns();
        
        $msg = "🧪 <b>نتایج تست کرون‌ها</b>\n\n";
        $msg .= "📧 کمپین اصلی: " . ($campaign_result ? "✅" : "❌") . "\n";
        $msg .= "🔔 یادآوری: $inactivity_result پیام\n";
        $msg .= "🎉 کمپین جشنواره: $inactive_result شروع\n";
        $msg .= "⏰ زمان: " . date('H:i:s');
        
        sendMessage(ADMIN_ID, $msg);
        exit;
    }

    // ثبت فعالیت
    if ($user_id != ADMIN_ID) {
        updateLastActivity($user_id);
    }

    // ثبت مشاهده جلسات
    $sessions = loadSessions();
    foreach ($sessions as $sess) {
        if ($text == $sess['title']) {
            markSessionSeen($user_id, $sess['title']);
            break;
        }
    }

    // --- هندل ادمین و کاربر ---
    if (handleAdmin($message, $chat_id, $text, $user_id)) {
        mainLog("Handled by admin for user $user_id");
        exit;
    }

    if (handleUser($message, $chat_id, $text, $user_id)) {
        mainLog("Handled by user for user $user_id");
        exit;
    }

    // پیام راهنما
    if ($user_id == ADMIN_ID) {
        sendMessage($chat_id, "❔ دستور نامشخص. از منوی ادمین استفاده کنید.");
    } else {
        sendMessage($chat_id, "❔ لطفاً یکی از گزینه‌های منو را انتخاب کنید.");
    }

} catch (Exception $e) {
    mainLog("Critical error: " . $e->getMessage());
    
    // اطلاع به ادمین
    if (defined('ADMIN_ID')) {
        try {
            $error_msg = "🚨 <b>خطای ربات</b>\n\n";
            $error_msg .= "📄 فایل: " . basename($e->getFile()) . "\n";
            $error_msg .= "📍 خط: " . $e->getLine() . "\n";
            $error_msg .= "💬 پیام: " . $e->getMessage() . "\n";
            $error_msg .= "⏰ زمان: " . date('Y-m-d H:i:s');
            
            sendMessage(ADMIN_ID, $error_msg);
        } catch (Exception $e2) {
            error_log("Failed to send error notification: " . $e2->getMessage());
        }
    }
    
    http_response_code(500);
    echo "Internal Server Error";
}
?>