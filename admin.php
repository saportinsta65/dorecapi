<?php
/**
 * منطق مدیریت ادمین - فقط برای ADMIN_ID فعال است
 * نسخه کاملاً تصحیح شده - 12 اکتبر 2025
 * با رفع مشکلات callback و sync با exercises.php
 */

// محافظت از دسترسی مستقیم
if (!defined('BOT_ACCESS')) {
    die('Access Denied');
}

require_once 'functions.php';
require_once 'referral.php';
require_once 'campaign.php';
require_once 'inactive_campaign.php';
require_once 'exercises.php';

/**
 * ✅ Debug logging برای admin
 */
function adminDebugLog($message, $data = null) {
    $log_message = "[ADMIN_DEBUG] $message";
    if ($data !== null) {
        $log_message .= " - Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    error_log($log_message);
}

/**
 * ✅ تابع کمکی برای پردازش ایمن JSON در admin
 */
function safeJsonDecode($data, $default = []) {
    if (empty($data)) {
        return $default;
    }
    
    if (is_array($data)) {
        return $data;
    }
    
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    
    return $default;
}

// دکمه‌ها و کیبوردها
$adminKeyboard = [
    ["🗂 مدیریت دوره رایگان"],
    ["📊 آمار کاربران"],
    ["📊 آمار دعوتی‌ها"],
    ["📊 آمار پیشرفته دوره"],
    ["💼 مدیریت کمپین پایان دوره"],
    ["📊 آمار کمپین‌ها"],
    ["📊 آمار کمپین جشنواره"],
    ["📋 تمرین‌های منتظر"],
    ["📝 تغییر متن دکمه‌ها"],
    ["📢 ارسال پیام همگانی"],
    ["🔙 بازگشت به منوی کاربری"]
];

$courseKeyboard = [
    ["➕ افزودن جلسه جدید"],
    ["❌ حذف جلسات"],
    ["📋 تمرین‌های منتظر"],
    ["🔙 بازگشت به منوی مدیریت"]
];

$campaignKeyboard = [
    ["➕ پیام جدید کمپین"],
    ["📜 مشاهده پیام‌ها"],
    ["🗑 حذف پیام کمپین"],
    ["🧪 تست کمپین"],
    ["🔄 اجرای دستی کمپین"],
    ["🔙 بازگشت به منوی مدیریت"]
];

$campaignAddFileKeyboard = [
    ["پایان"],
    ["انصراف"]
];

$addFileKeyboard = [
    ["انصراف"]
];

// آمار پیشرفته دوره بر اساس تعداد جلسات (داینامیک از sessions جدول)
function showAdvancedStats($chat_id) {
    try {
        $users = loadUsers();
        $sessions = loadSessions();
        $msg = "📊 <b>آمار پیشرفته دوره</b>\n\n";
        $msg .= "کل کاربران: <b>" . count($users) . "</b>\n\n";

        foreach ($sessions as $sess) {
            $seen = 0; $answered = 0; $accepted = 0; $rejected = 0; $pending = 0;
            foreach ($users as $u) {
                $seen_sessions = safeJsonDecode($u['seen_sessions'] ?? null, []);
                $exercises = safeJsonDecode($u['exercises'] ?? null, []);
                
                if (is_array($seen_sessions) && in_array($sess['title'], $seen_sessions)) $seen++;
                
                $sid = intval($sess['id']);
                if (isset($exercises[$sid]) || isset($exercises[strval($sid)])) {
                    $exercise = $exercises[$sid] ?? $exercises[strval($sid)];
                    $answered++;
                    $st = $exercise['status'] ?? '';
                    if ($st == 'accepted') $accepted++;
                    elseif ($st == 'rejected') $rejected++;
                    elseif ($st == 'pending') $pending++;
                }
            }
            $msg .= "جلسه <b>{$sess['title']}</b>:\n";
            $msg .= "- دیده‌اند: <b>{$seen}</b>\n";
            $msg .= "- تمرین ارسال‌شده: <b>{$answered}</b> (تایید: {$accepted} | رد: {$rejected} | منتظر: {$pending})\n\n";
        }

        // کاربران کامل دوره و کمپین فعال
        $completed = 0; $with_campaign = 0;
        $last_session_id = count($sessions) > 0 ? intval($sessions[count($sessions)-1]['id']) : null;
        foreach ($users as $u) {
            $seen_sessions = safeJsonDecode($u['seen_sessions'] ?? null, []);
            $exercises = safeJsonDecode($u['exercises'] ?? null, []);
            
            $has_all_seen = is_array($seen_sessions) && count($seen_sessions) == count($sessions);
            $last_ex = false;
            if ($last_session_id) {
                $last_exercise = $exercises[$last_session_id] ?? $exercises[strval($last_session_id)] ?? null;
                $last_ex = $last_exercise && ($last_exercise['status'] ?? '') == 'accepted';
            }
            
            if ($has_all_seen && $last_ex) $completed++;
            if (isset($u['campaign_started']) && $u['campaign_started']) $with_campaign++;
        }
        $msg .= "تعداد افرادی که کل جلسات را دیده‌اند و آخرین تمرین‌شان تایید شده: <b>{$completed}</b>\n";
        $msg .= "تعداد کمپین‌های فعال‌شده: <b>{$with_campaign}</b>\n";

        sendMessage($chat_id, $msg);
    } catch (Exception $e) {
        error_log("Error in showAdvancedStats: " . $e->getMessage());
        sendMessage($chat_id, "❌ خطا در دریافت آمار: " . $e->getMessage());
    }
}

// آمار کمپین‌های اصلی (تکمیل دوره)
function showCampaignStats($chat_id) {
    try {
        global $pdo;
        
        // آمار کلی
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE campaign_started = 1");
        $total_campaigns = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as today FROM users WHERE campaign_started = 1 AND DATE(campaign_start_time) = CURDATE()");
        $today_campaigns = $stmt->fetch()['today'];
        
        // آمار مراحل
        $stmt = $pdo->query("
            SELECT 
                campaign_sent_steps,
                COUNT(*) as count 
            FROM users 
            WHERE campaign_started = 1 
            GROUP BY campaign_sent_steps
        ");
        
        $steps_stats = [];
        while ($row = $stmt->fetch()) {
            $steps = safeJsonDecode($row['campaign_sent_steps'], []);
            $step_count = count($steps);
            $steps_stats[$step_count] = ($steps_stats[$step_count] ?? 0) + $row['count'];
        }
        
        $msg = "📊 <b>آمار کمپین‌های اصلی (تکمیل دوره)</b>\n\n";
        $msg .= "🎯 کل کمپین‌های فعال: <b>$total_campaigns</b>\n";
        $msg .= "📅 کمپین‌های امروز: <b>$today_campaigns</b>\n\n";
        
        $msg .= "📈 توزیع مراحل:\n";
        if (function_exists('getCampaignSteps')) {
            $campaign_steps = getCampaignSteps();
            $total_steps = count($campaign_steps);
            
            for ($i = 0; $i <= $total_steps; $i++) {
                $count = $steps_stats[$i] ?? 0;
                if ($i == 0) {
                    $msg .= "▪️ شروع کمپین: <b>$count</b>\n";
                } elseif ($i == $total_steps) {
                    $msg .= "▪️ کمپین کامل: <b>$count</b>\n";
                } else {
                    $msg .= "▪️ مرحله $i: <b>$count</b>\n";
                }
            }
        } else {
            $msg .= "❌ تابع getCampaignSteps یافت نشد\n";
        }
        
        // کمپین‌های اخیر
        $stmt = $pdo->query("
            SELECT id, campaign_start_time, campaign_discount_code 
            FROM users 
            WHERE campaign_started = 1 
            ORDER BY campaign_start_time DESC 
            LIMIT 5
        ");
        
        $msg .= "\n🕒 آخرین کمپین‌ها:\n";
        while ($row = $stmt->fetch()) {
            $start_time = date('m/d H:i', strtotime($row['campaign_start_time']));
            $msg .= "▪️ کاربر {$row['id']} - $start_time\n";
        }
        
        sendMessage($chat_id, $msg);
        
    } catch (Exception $e) {
        error_log("Error in showCampaignStats: " . $e->getMessage());
        sendMessage($chat_id, "❌ خطا در دریافت آمار کمپین: " . $e->getMessage());
    }
}

// آمار کمپین جشنواره (غیرفعالی)
function showInactiveCampaignStats($chat_id) {
    try {
        global $pdo;
        
        // کاربران واجد شرایط
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM users 
            WHERE 
                (type = 'free' OR type = 'user')
                AND last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND (campaign_started IS NULL OR campaign_started = 0)
                AND (inactive_campaign_started IS NULL OR inactive_campaign_started = 0)
        ");
        $eligible = $stmt->fetchColumn();
        
        // کمپین‌های فعال شده
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE inactive_campaign_started = 1");
        $total_started = $stmt->fetchColumn();
        
        // امروز
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM users 
            WHERE DATE(inactive_campaign_start_time) = CURDATE()
        ");
        $today = $stmt->fetchColumn();
        
        // این هفته
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM users 
            WHERE inactive_campaign_start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $this_week = $stmt->fetchColumn();
        
        // این ماه
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM users 
            WHERE MONTH(inactive_campaign_start_time) = MONTH(CURDATE())
            AND YEAR(inactive_campaign_start_time) = YEAR(CURDATE())
        ");
        $this_month = $stmt->fetchColumn();
        
        // آخرین کمپین‌ها
        $stmt = $pdo->query("
            SELECT id, first_name, inactive_campaign_start_time 
            FROM users 
            WHERE inactive_campaign_started = 1 
            ORDER BY inactive_campaign_start_time DESC 
            LIMIT 5
        ");
        
        $recent = [];
        while ($row = $stmt->fetch()) {
            $recent[] = $row;
        }
        
        $msg = "🎉 <b>آمار کمپین جشنواره (کاربران غیرفعال)</b>\n\n";
        $msg .= "👥 کاربران واجد شرایط (30+ روز): <b>$eligible</b>\n";
        $msg .= "🎯 کل فعال شده: <b>$total_started</b>\n";
        $msg .= "📅 امروز: <b>$today</b>\n";
        $msg .= "📆 این هفته: <b>$this_week</b>\n";
        $msg .= "📆 این ماه: <b>$this_month</b>\n\n";
        
        if (count($recent) > 0) {
            $msg .= "🕒 <b>آخرین فعال‌سازی‌ها:</b>\n";
            foreach ($recent as $r) {
                $time = date('m/d H:i', strtotime($r['inactive_campaign_start_time']));
                $msg .= "▪️ {$r['first_name']} ({$r['id']}) - $time\n";
            }
        } else {
            $msg .= "❌ هنوز کمپین جشنواره‌ای فعال نشده است.\n";
        }
        
        $msg .= "\n⏰ " . date('Y-m-d H:i:s');
        
        sendMessage($chat_id, $msg);
        
    } catch (Exception $e) {
        error_log("Error in showInactiveCampaignStats: " . $e->getMessage());
        sendMessage($chat_id, "❌ خطا در دریافت آمار: " . $e->getMessage());
    }
}

// ✅ نمایش تمرین‌های منتظر - نسخه تصحیح شده و sync شده
function showPendingExercises($chat_id) {
    try {
        adminDebugLog("Admin requesting pending exercises");
        
        if (!function_exists('getPendingExercises')) {
            adminDebugLog("getPendingExercises function not found");
            sendMessage($chat_id, "❌ تابع getPendingExercises یافت نشد. لطفاً exercises.php را بررسی کنید.");
            return;
        }
        
        $pending_exercises = getPendingExercises();
        
        adminDebugLog("Retrieved pending exercises", ['count' => count($pending_exercises)]);
        
        if (empty($pending_exercises)) {
            $msg = "✅ <b>تمرین‌های منتظر</b>\n\n";
            $msg .= "هیچ تمرینی در انتظار بررسی نیست! 🎉\n\n";
            $msg .= "همه تمرین‌ها بررسی شده‌اند.";
            sendMessage($chat_id, $msg);
            return;
        }
        
        $count = count($pending_exercises);
        
        // ارسال پیام خلاصه اول
        $summary_msg = "📋 <b>تمرین‌های منتظر بررسی ($count مورد)</b>\n\n";
        $summary_msg .= "🔍 در حال ارسال جزئیات هر تمرین...\n\n";
        $summary_msg .= "💡 <b>راهنما:</b>\n";
        $summary_msg .= "✅ تایید = تمرین پذیرفته می‌شود\n";
        $summary_msg .= "❌ رد = تمرین رد می‌شود\n";
        $summary_msg .= "👁 مشاهده کامل = نمایش جزئیات بیشتر";
        
        sendMessage($chat_id, $summary_msg);
        
        // ارسال هر تمرین جداگانه
        foreach ($pending_exercises as $index => $exercise) {
            $user_id = $exercise['user_id'];
            $user_name = $exercise['user_name'] ?: 'نامشخص';
            $session_title = $exercise['session_title'] ?: "جلسه شماره {$exercise['session_id']}";
            $answer = $exercise['answer'] ?: 'پاسخ خالی';
            $submitted_at = $exercise['submitted_at'] ?: 'نامشخص';
            $session_id = intval($exercise['session_id']);
            
            adminDebugLog("Processing pending exercise", [
                'index' => $index + 1,
                'user_id' => $user_id,
                'session_id' => $session_id,
                'session_title' => $session_title
            ]);
            
            // محاسبه زمان گذشته
            $time_ago = '';
            if ($submitted_at && $submitted_at != 'نامشخص') {
                $diff = time() - strtotime($submitted_at);
                if ($diff < 3600) {
                    $minutes = floor($diff / 60);
                    $time_ago = $minutes . ' دقیقه پیش';
                } elseif ($diff < 86400) {
                    $hours = floor($diff / 3600);
                    $time_ago = $hours . ' ساعت پیش';
                } else {
                    $days = floor($diff / 86400);
                    $time_ago = $days . ' روز پیش';
                }
            } else {
                $time_ago = 'نامشخص';
            }
            
            // محدود کردن طول پاسخ برای نمایش
            $short_answer = mb_strlen($answer) > 150 ? mb_substr($answer, 0, 150) . '...' : $answer;
            
            // ایجاد پیام تمرین
            $exercise_msg = "📝 <b>تمرین #" . ($index + 1) . "</b>\n\n";
            $exercise_msg .= "👤 کاربر: <b>$user_name</b> (#$user_id)\n";
            $exercise_msg .= "📚 جلسه: <b>$session_title</b>\n";
            $exercise_msg .= "📅 ارسال: $time_ago\n\n";
            $exercise_msg .= "💬 <b>متن تمرین:</b>\n";
            $exercise_msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $exercise_msg .= "<code>$short_answer</code>\n";
            $exercise_msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            
            // ایجاد inline keyboard
            $reply_markup = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ تایید',
                            'callback_data' => "exercise_accept_{$user_id}_{$session_id}"
                        ],
                        [
                            'text' => '❌ رد',
                            'callback_data' => "exercise_reject_{$user_id}_{$session_id}"
                        ]
                    ],
                    [
                        [
                            'text' => '👁 مشاهده کامل',
                            'callback_data' => "exercise_view_{$user_id}_{$session_id}"
                        ]
                    ]
                ]
            ];
            
            adminDebugLog("Sending exercise with callbacks", [
                'accept_callback' => "exercise_accept_{$user_id}_{$session_id}",
                'reject_callback' => "exercise_reject_{$user_id}_{$session_id}",
                'view_callback' => "exercise_view_{$user_id}_{$session_id}"
            ]);
            
            // ارسال با inline keyboard
            $url = API_URL . "sendMessage";
            $data = [
                'chat_id' => $chat_id,
                'text' => $exercise_msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($reply_markup)
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code !== 200) {
                adminDebugLog("Failed to send exercise message", ['http_code' => $http_code, 'response' => $response]);
            }
            
            // تاخیر کوتاه بین پیام‌ها
            usleep(500000); // 0.5 ثانیه
        }
        
        // ارسال پیام پایانی
        $final_msg = "✅ <b>تمام تمرین‌های منتظر نمایش داده شد</b>\n\n";
        $final_msg .= "📊 کل: <b>$count</b> تمرین منتظر بررسی\n";
        $final_msg .= "⏰ آخرین بروزرسانی: " . date('H:i:s');
        
        sendMessage($chat_id, $final_msg);
        
        adminDebugLog("Pending exercises display completed", ['total_sent' => $count]);
        
    } catch (Exception $e) {
        error_log("Error in showPendingExercises: " . $e->getMessage());
        adminDebugLog("Exception in showPendingExercises", ['error' => $e->getMessage()]);
        sendMessage($chat_id, "❌ خطا در دریافت تمرین‌های منتظر: " . $e->getMessage());
    }
}

// تست کمپین برای ادمین
function testCampaignForAdmin($chat_id, $admin_id) {
    try {
        // ریست کمپین ادمین
        global $pdo;
        $stmt = $pdo->prepare("
            UPDATE users SET 
                campaign_started = 0,
                campaign_start_time = NULL,
                campaign_sent_steps = '[]',
                campaign_discount_code = ''
            WHERE id = ?
        ");
        $stmt->execute([$admin_id]);
        
        // شروع کمپین تست
        if (function_exists('startCampaign')) {
            $result = startCampaign($admin_id);
            
            if ($result) {
                sendMessage($chat_id, "✅ کمپین تست برای شما شروع شد. پیام اول را دریافت کردید؟");
            } else {
                sendMessage($chat_id, "❌ خطا در شروع کمپین تست. لاگ‌ها را بررسی کنید.");
            }
        } else {
            sendMessage($chat_id, "❌ تابع startCampaign یافت نشد.");
        }
        
    } catch (Exception $e) {
        error_log("Error in testCampaignForAdmin: " . $e->getMessage());
        sendMessage($chat_id, "❌ خطا در تست کمپین: " . $e->getMessage());
    }
}

// اجرای دستی کمپین
function manualCampaignExecution($chat_id) {
    try {
        sendMessage($chat_id, "🔄 در حال پردازش کمپین‌ها...");
        
        if (function_exists('processCampaignNotifications')) {
            ob_start();
            $result = processCampaignNotifications();
            $output = ob_get_clean();
            
            $msg = $result ? "✅ پردازش کمپین تکمیل شد." : "⚠️ پردازش کمپین بدون نتیجه.";
            $msg .= "\n\nجزئیات در error.log موجود است.";
            
            sendMessage($chat_id, $msg);
        } else {
            sendMessage($chat_id, "❌ تابع processCampaignNotifications یافت نشد.");
        }
        
    } catch (Exception $e) {
        error_log("Error in manualCampaignExecution: " . $e->getMessage());
        sendMessage($chat_id, "❌ خطا در اجرای دستی کمپین: " . $e->getMessage());
    }
}

// هندل پیام‌های ادمین
function handleAdmin($message, $chat_id, $text, $user_id) {
    global $adminKeyboard, $courseKeyboard, $addFileKeyboard, $campaignKeyboard, $campaignAddFileKeyboard;
    $is_admin = ($user_id == ADMIN_ID);

    // --- هندل پاسخ پشتیبانی آنلاین (ادمین) ---
    $support_state = loadSupportState();
    if (isset($support_state['admin_reply_to']) && $support_state['admin_reply_to']) {
        $target_user = $support_state['admin_reply_to'];
        if ($text == "لغو" || $text == "/cancel") {
            unset($support_state['admin_reply_to']);
            saveSupportState($support_state);
            sendMessage($chat_id, "پاسخ به کاربر لغو شد.");
            return true;
        }
        if ($text) sendMessage($target_user, "📢 پاسخ پشتیبانی:\n$text");
        if (isset($message['photo'])) {
            $photos = $message['photo'];
            $file_id = $photos[count($photos)-1]['file_id'];
            sendFile($target_user, 'photo', $file_id, "📢 پاسخ پشتیبانی (عکس)");
        }
        if (isset($message['voice'])) {
            $file_id = $message['voice']['file_id'];
            sendFile($target_user, 'voice', $file_id, "📢 پاسخ پشتیبانی (ویس)");
        }
        unset($support_state['admin_reply_to']);
        saveSupportState($support_state);
        sendMessage($chat_id, "✅ پاسخ شما برای کاربر ارسال شد.");
        return true;
    }

    if (!$is_admin) return false;
    if ($text == "/cancel") {
        saveAdminState([]);
        sendMessage($chat_id, "❌ عملیات لغو شد. به منوی مدیریت برگشتید.", $adminKeyboard);
        return true;
    }

    $admin_state = loadAdminState();

    // ✅ دستور تست کمپین جشنواره
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
        return true;
    }
    
    // تایید تست
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
        return true;
    }

    // مدیریت کمپین پایان دوره
    if (isset($admin_state['step']) && $admin_state['step'] && $admin_state['user_id'] == $user_id) {
        // افزودن پیام کمپین: وارد کردن زمان تاخیر
        if ($admin_state['step'] == 'campaign_add_delay') {
            if (!is_numeric($text)) {
                sendMessage($chat_id, "زمان تاخیر (بر حسب ثانیه) را فقط به صورت عدد وارد کنید.\n\n⏱ مثال‌های زمان‌بندی:\n▪️ 0 = فوری\n▪️ 3600 = 1 ساعت\n▪️ 86400 = 1 روز\n▪️ 259200 = 3 روز");
                return true;
            }
            $admin_state['new_campaign']['delay'] = intval($text);
            $admin_state['step'] = 'campaign_add_content';
            $admin_state['new_campaign']['contents'] = [];
            saveAdminState($admin_state);
            sendMessage($chat_id, "📝 متن پیام کمپین، عکس، ویس، ویدیو یا فایل را یکی یکی ارسال کنید.\n\n💡 نکته: از <code>{discount_code}</code> برای نمایش کد تخفیف استفاده کنید.\n\nبرای پایان، دکمه <b>پایان</b> یا <b>انصراف</b> را بزن.", $campaignAddFileKeyboard);
            return true;
        }
        
        // افزودن پیام کمپین: ثبت محتوا
        if ($admin_state['step'] == 'campaign_add_content') {
            if ($text == "انصراف" || $text == "/cancel") {
                saveAdminState([]);
                sendMessage($chat_id, "افزودن پیام کمپین لغو شد.", $campaignKeyboard);
                return true;
            }
            if ($text == "پایان") {
                // ذخیره پیام کمپین
                $campaigns = [];
                if (file_exists(CAMPAIGN_MESSAGES_FILE))
                    $campaigns = json_decode(file_get_contents(CAMPAIGN_MESSAGES_FILE), true) ?: [];
                $campaigns[] = $admin_state['new_campaign'];
                
                // مرتب‌سازی بر اساس delay
                usort($campaigns, function($a, $b) {
                    return $a['delay'] - $b['delay'];
                });
                
                file_put_contents(CAMPAIGN_MESSAGES_FILE, json_encode($campaigns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                saveAdminState([]);
                sendMessage($chat_id, "✅ پیام کمپین با موفقیت ثبت شد و بر اساس زمان مرتب گردید.", $campaignKeyboard);
                return true;
            }
            
            // ذخیره فایل یا متن
            $file_id = '';
            $type = '';
            $caption = '';
            if (isset($message['document'])) {
                $file_id = $message['document']['file_id'];
                $type = 'document';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['audio'])) {
                $file_id = $message['audio']['file_id'];
                $type = 'audio';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['voice'])) {
                $file_id = $message['voice']['file_id'];
                $type = 'voice';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['video'])) {
                $file_id = $message['video']['file_id'];
                $type = 'video';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['photo'])) {
                $photo_arr = $message['photo'];
                $file_id = $photo_arr[count($photo_arr)-1]['file_id'];
                $type = 'photo';
                $caption = $message['caption'] ?? '';
            }
            
            if ($file_id) {
                $admin_state['new_campaign']['contents'][] = [
                    'type' => $type,
                    'file_id' => $file_id,
                    'caption' => $caption
                ];
                saveAdminState($admin_state);
                sendMessage($chat_id, "✔️ فایل دریافت شد. اگر فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $campaignAddFileKeyboard);
                return true;
            }
            if ($text && $text != "پایان" && $text != "انصراف" && $text != "/cancel") {
                $admin_state['new_campaign']['contents'][] = [
                    'type' => 'text',
                    'content' => $text
                ];
                saveAdminState($admin_state);
                sendMessage($chat_id, "✔️ متن دریافت شد. اگر فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $campaignAddFileKeyboard);
                return true;
            }
            sendMessage($chat_id, "فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $campaignAddFileKeyboard);
            return true;
        }
        
        // نمایش لیست پیام‌های کمپین
        if ($admin_state['step'] == 'campaign_list') {
            if (file_exists(CAMPAIGN_MESSAGES_FILE)) {
                $campaigns = json_decode(file_get_contents(CAMPAIGN_MESSAGES_FILE), true) ?: [];
                if (count($campaigns) == 0) {
                    sendMessage($chat_id, "هیچ پیام کمپینی ثبت نشده است.", $campaignKeyboard);
                } else {
                    $msg = "📜 <b>لیست پیام‌های کمپین:</b>\n\n";
                    foreach ($campaigns as $i => $camp) {
                        $delay_text = $camp['delay'] == 0 ? "فوری" : $camp['delay'] . " ثانیه";
                        $msg .= "<b>" . ($i+1) . ".</b> زمان: <b>$delay_text</b>\n";
                        foreach ($camp['contents'] as $c) {
                            if ($c['type'] == 'text') {
                                $preview = substr($c['content'], 0, 50);
                                if (strlen($c['content']) > 50) $preview .= "...";
                                $msg .= "📝 متن: $preview\n";
                            } else {
                                $msg .= "📎 فایل: [{$c['type']}]\n";
                            }
                        }
                        $msg .= "\n";
                    }
                    sendMessage($chat_id, $msg, $campaignKeyboard);
                }
            } else {
                sendMessage($chat_id, "هنوز هیچ پیام کمپینی ثبت نشده است.", $campaignKeyboard);
            }
            saveAdminState([]);
            return true;
        }
        
        // حذف پیام کمپین
        if ($admin_state['step'] == 'campaign_delete') {
            if ($text == "انصراف" || $text == "/cancel") {
                saveAdminState([]);
                sendMessage($chat_id, "حذف لغو شد.", $campaignKeyboard);
                return true;
            }
            
            if (is_numeric($text)) {
                $index = intval($text) - 1;
                if (file_exists(CAMPAIGN_MESSAGES_FILE)) {
                    $campaigns = json_decode(file_get_contents(CAMPAIGN_MESSAGES_FILE), true) ?: [];
                    
                    if (isset($campaigns[$index])) {
                        unset($campaigns[$index]);
                        $campaigns = array_values($campaigns); // re-index
                        file_put_contents(CAMPAIGN_MESSAGES_FILE, json_encode($campaigns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        saveAdminState([]);
                        sendMessage($chat_id, "✅ پیام کمپین حذف شد.", $campaignKeyboard);
                    } else {
                        sendMessage($chat_id, "❌ شماره نامعتبر است.");
                    }
                } else {
                    sendMessage($chat_id, "❌ فایل پیام‌های کمپین یافت نشد.");
                }
            } else {
                sendMessage($chat_id, "شماره پیام را وارد کنید:");
            }
            return true;
        }
        
        // بقیه مراحل (افزودن جلسه، حذف، تغییر دکمه‌ها، پیام همگانی) بدون تغییر
        // ... (کد قبلی بدون تغییر)
        
        // مراحل افزودن جلسه جدید (دیتابیس)
        if ($admin_state['step'] == 'add_title') {
            if ($text != "") {
                $admin_state['new_session']['title'] = $text;
                $admin_state['step'] = 'add_text';
                saveAdminState($admin_state);
                sendMessage($chat_id, "📝 توضیح جلسه را ارسال کنید (می‌تواند خالی باشد):");
            }
            return true;
        }
        
        // مرحله جدید: تمرین جلسه
        if ($admin_state['step'] == 'add_text') {
            $admin_state['new_session']['text'] = $text;
            $admin_state['step'] = 'add_exercise';
            saveAdminState($admin_state);
            sendMessage($chat_id, "📝 متن تمرین این جلسه را بنویسید (اگر تمرین ندارد، فقط عدد 0 یا - بنویسید):");
            return true;
        }
        
        if ($admin_state['step'] == 'add_exercise') {
            $admin_state['new_session']['exercise'] = ($text != "0" && $text != "-") ? $text : "";
            $admin_state['new_session']['files'] = [];
            $admin_state['step'] = 'add_files';
            saveAdminState($admin_state);
            sendMessage($chat_id, "📎 فایل یا متن‌های جلسه را یکی یکی ارسال کنید (عکس، صوت، ویدیو، PDF و ... یا متن).\nاگر نیاز به فایل/متن نیست یا ارسال فایل‌ها تمام شد، <b>پایان</b> یا <b>انصراف</b> را بزن.", $addFileKeyboard);
            return true;
        }
        
        if ($admin_state['step'] == 'add_files') {
            if ($text == "انصراف" || $text == "/cancel") {
                $admin_state['step'] = '';
                saveAdminState($admin_state);
                sendMessage($chat_id, "افزودن جلسه لغو شد.", $courseKeyboard);
                return true;
            }
            if ($text == "پایان") {
                $new_session = $admin_state['new_session'];
                if (function_exists('saveSession')) {
                    saveSession($new_session);
                    saveAdminState([]);
                    sendMessage($chat_id, "✅ جلسه با موفقیت اضافه شد.", $courseKeyboard);
                } else {
                    sendMessage($chat_id, "❌ تابع saveSession یافت نشد.");
                }
                return true;
            }
            
            $file_id = '';
            $type = '';
            $caption = '';
            if (isset($message['document'])) {
                $file_id = $message['document']['file_id'];
                $type = 'document';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['audio'])) {
                $file_id = $message['audio']['file_id'];
                $type = 'audio';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['voice'])) {
                $file_id = $message['voice']['file_id'];
                $type = 'voice';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['video'])) {
                $file_id = $message['video']['file_id'];
                $type = 'video';
                $caption = $message['caption'] ?? '';
            } elseif (isset($message['photo'])) {
                $photo_arr = $message['photo'];
                $file_id = $photo_arr[count($photo_arr)-1]['file_id'];
                $type = 'photo';
                $caption = $message['caption'] ?? '';
            }
            
            if ($file_id) {
                $admin_state['new_session']['files'][] = [
                    'type' => $type,
                    'file_id' => $file_id,
                    'caption' => $caption
                ];
                saveAdminState($admin_state);
                sendMessage($chat_id, "✔️ فایل دریافت شد. اگر فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $addFileKeyboard);
                return true;
            }
            if ($text && $text != "پایان" && $text != "انصراف" && $text != "/cancel") {
                $admin_state['new_session']['files'][] = [
                    'type' => 'text',
                    'content' => $text
                ];
                saveAdminState($admin_state);
                sendMessage($chat_id, "✔️ متن دریافت شد. اگر فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $addFileKeyboard);
                return true;
            }
            sendMessage($chat_id, "📎 فایل یا متن دیگری داری ارسال کن یا <b>پایان</b> یا <b>انصراف</b> را بزن.", $addFileKeyboard);
            return true;
        }
        
        // حذف جلسه
        if ($admin_state['step'] == 'delete_select_session') {
            $sessions = loadSessions();
            foreach ($sessions as $sess) {
                if ($text == $sess['title']) {
                    $admin_state['delete_session_id'] = $sess['id'];
                    $admin_state['step'] = 'delete_confirm';
                    saveAdminState($admin_state);
                    sendMessage($chat_id, "آیا مطمئن هستید می‌خواهید جلسه <b>{$sess['title']}</b> را حذف کنید؟", [
                        ["✅ تایید حذف"],
                        ["❌ لغو حذف"]
                    ]);
                    return true;
                }
            }
            sendMessage($chat_id, "❌ جلسه پیدا نشد. لطفا از لیست انتخاب کنید.", [["🔙 بازگشت به مدیریت جلسات"]]);
            return true;
        }
        
        if ($admin_state['step'] == 'delete_confirm') {
            if ($text == "✅ تایید حذف") {
                try {
                    global $pdo;
                    $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
                    $stmt->execute([$admin_state['delete_session_id']]);
                    saveAdminState([]);
                    sendMessage($chat_id, "✅ جلسه حذف شد.", $courseKeyboard);
                } catch (Exception $e) {
                    error_log("Error deleting session: " . $e->getMessage());
                    sendMessage($chat_id, "❌ خطا در حذف جلسه: " . $e->getMessage(), $courseKeyboard);
                }
                return true;
            }
            if ($text == "❌ لغو حذف" || $text == "/cancel") {
                saveAdminState([]);
                sendMessage($chat_id, "عملیات حذف لغو شد.", $courseKeyboard);
                return true;
            }
        }
        
        // تغییر متن دکمه‌ها
        if ($admin_state['step'] == 'change_btn_select') {
            if ($text == "تغییر ارتباط با کاپیتان") {
                $admin_state['step'] = 'change_btn_captain';
                saveAdminState($admin_state);
                sendMessage($chat_id, "متن جدید دکمه و پیام را با خط جدید جداگانه ارسال کن:\nمثال:\nارتباط با مدیر\nاین متن برای کاربر نمایش داده می‌شود.");
                return true;
            }
            if ($text == "تغییر ثبت‌نام دوره پیشرفته") {
                $admin_state['step'] = 'change_btn_advanced';
                saveAdminState($admin_state);
                sendMessage($chat_id, "متن جدید دکمه و پیام را با خط جدید جداگانه ارسال کن:\nمثال:\nثبت‌نام دوره پیشرفته\nاین متن برای کاربر نمایش داده می‌شود.");
                return true;
            }
            if ($text == "🔙 بازگشت به منوی مدیریت" || $text == "/cancel") {
                saveAdminState([]);
                sendMessage($chat_id, "🎩 <b>پنل مدیریت ادمین</b>\n\nلطفا یکی از گزینه‌های زیر را انتخاب کنید:", $adminKeyboard);
                return true;
            }
        }
        
        if ($admin_state['step'] == 'change_btn_captain') {
            $parts = explode("\n", $text, 2);
            if (count($parts) == 2) {
                file_put_contents(BTN_CAPTAIN_FILE, json_encode(['btn' => $parts[0], 'msg' => $parts[1]], JSON_UNESCAPED_UNICODE));
                saveAdminState([]);
                sendMessage($chat_id, "✅ دکمه ارتباط با کاپیتان تغییر کرد.", $adminKeyboard);
            } else {
                sendMessage($chat_id, "فرمت ارسال صحیح نیست! لطفا طبق مثال ارسال کن.");
            }
            return true;
        }
        
        if ($admin_state['step'] == 'change_btn_advanced') {
            $parts = explode("\n", $text, 2);
            if (count($parts) == 2) {
                file_put_contents(BTN_ADVANCED_FILE, json_encode(['btn' => $parts[0], 'msg' => $parts[1]], JSON_UNESCAPED_UNICODE));
                saveAdminState([]);
                sendMessage($chat_id, "✅ دکمه ثبت‌نام دوره پیشرفته تغییر کرد.", $adminKeyboard);
            } else {
                sendMessage($chat_id, "فرمت ارسال صحیح نیست! لطفا طبق مثال ارسال کن.");
            }
            return true;
        }
        
       
        // ✅ پیام همگانی با جلوگیری از ارسال چندباره
if ($admin_state['step'] == 'broadcast') {
    if ($text == "انصراف" || $text == "/cancel") {
        saveAdminState([]);
        sendMessage($chat_id, "ارسال پیام همگانی لغو شد.", $adminKeyboard);
        return true;
    }
    
    // تولید ID منحصر به فرد برای این broadcast
    $broadcast_id = 'bcast_' . time() . '_' . $user_id;
    
    // ذخیره broadcast_id در admin_state
    if (!isset($admin_state['broadcast_id'])) {
        $admin_state['broadcast_id'] = $broadcast_id;
        saveAdminState($admin_state);
    } else {
        $broadcast_id = $admin_state['broadcast_id'];
    }
    
    $users = loadUsers();
    $sent = 0;
    $failed = 0;
    
    // فایل موقت برای ردگیری کاربران
    $data_dir = __DIR__ . "/data";
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }
    
    $sent_file = $data_dir . "/{$broadcast_id}_sent.json";
    $sent_users = [];
    
    if (file_exists($sent_file)) {
        $sent_users = json_decode(file_get_contents($sent_file), true) ?: [];
    }
    
    foreach ($users as $u) {
        $uid = $u['id'];
        
        // چک کردن که قبلاً ارسال نکردیم
        if (in_array($uid, $sent_users)) {
            error_log("[BROADCAST] Already sent to user $uid, skipping");
            continue;
        }
        
        try {
            $message_sent = false;
            
            // ارسال متن
            if ($text && !isset($message['document']) && !isset($message['audio']) && 
                !isset($message['voice']) && !isset($message['video']) && !isset($message['photo'])) {
                if (sendMessage($uid, $text)) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            // ارسال فایل
            if (isset($message['document'])) {
                $caption = $message['caption'] ?? '';
                if (sendFile($uid, 'document', $message['document']['file_id'], $caption)) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            if (isset($message['audio'])) {
                $caption = $message['caption'] ?? '';
                if (sendFile($uid, 'audio', $message['audio']['file_id'], $caption)) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            if (isset($message['voice'])) {
                if (sendFile($uid, 'voice', $message['voice']['file_id'], '')) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            if (isset($message['video'])) {
                $caption = $message['caption'] ?? '';
                if (sendFile($uid, 'video', $message['video']['file_id'], $caption)) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            if (isset($message['photo'])) {
                $photo_arr = $message['photo'];
                $file_id = $photo_arr[count($photo_arr)-1]['file_id'];
                $caption = $message['caption'] ?? '';
                if (sendFile($uid, 'photo', $file_id, $caption)) {
                    $sent++;
                    $message_sent = true;
                }
            }
            
            if (!$message_sent) {
                $failed++;
            } else {
                // ذخیره که به این کاربر فرستادیم
                $sent_users[] = $uid;
                file_put_contents($sent_file, json_encode($sent_users));
                error_log("[BROADCAST] Sent to user $uid");
            }
            
        } catch(Exception $e) {
            $failed++;
            error_log("[BROADCAST] Failed to send to user $uid: " . $e->getMessage());
        }
        
        // تاخیر برای جلوگیری از rate limit
        usleep(300000); // 0.3 ثانیه
    }
    
    // پاک کردن فایل موقت
    if (file_exists($sent_file)) {
        unlink($sent_file);
    }
    
    // پاک کردن state
    saveAdminState([]);
    
    $total_users = count($users);
    $report = "📢 <b>گزارش پیام همگانی:</b>\n\n";
    $report .= "✅ موفق: <b>$sent</b>\n";
    $report .= "❌ ناموفق: <b>$failed</b>\n";
    $report .= "👥 کل: <b>$total_users</b>\n";
    $report .= "⏰ زمان: " . date('H:i:s');
    
    sendMessage($chat_id, $report, $adminKeyboard);
    return true;
    
}
    }

    // دستورات اصلی پنل مدیریت
    switch ($text) {
        case "/admin":
        case "پنل مدیریت":
            sendMessage($chat_id, "🎩 <b>پنل مدیریت ادمین</b>\n\nلطفا یکی از گزینه‌های زیر را انتخاب کنید:", $adminKeyboard);
            return true;
            
        case "🗂 مدیریت دوره رایگان":
            sendMessage($chat_id, "📚 <b>مدیریت دوره رایگان</b>\n\nچه کاری می‌خواهید انجام دهید؟", $courseKeyboard);
            return true;
            
        case "➕ افزودن جلسه جدید":
            $admin_state = [
                'user_id' => $user_id,
                'step' => 'add_title',
                'new_session' => []
            ];
            saveAdminState($admin_state);
            sendMessage($chat_id, "📛 نام جلسه را وارد کنید:");
            return true;
            
        case "❌ حذف جلسات":
            $sessions = loadSessions();
            if (count($sessions) > 0) {
                $sessionBtns = [];
                foreach ($sessions as $sess) $sessionBtns[] = [$sess['title']];
                $sessionBtns[] = ["🔙 بازگشت به منوی مدیریت"];
                $admin_state = [
                    'user_id' => $user_id,
                    'step' => 'delete_select_session'
                ];
                saveAdminState($admin_state);
                sendMessage($chat_id, "کدام جلسه را می‌خواهید حذف کنید؟", $sessionBtns);
            } else {
                sendMessage($chat_id, "هنوز هیچ جلسه‌ای وجود ندارد.", $courseKeyboard);
            }
            return true;
            
        case "📊 آمار کاربران":
            $users = loadUsers();
            $now = time();
            $daily = $weekly = $monthly = $total = 0;
            $openedFree = 0;
            $plsDiscount = 0;
            
            foreach ($users as $u) {
                $total++;
                $reg = strtotime($u['registered_at']);
                if ($reg >= strtotime(date('Y-m-d 00:00:00', $now))) $daily++;
                if ($reg >= strtotime('-7 days', $now)) $weekly++;
                if ($reg >= strtotime('-30 days', $now)) $monthly++;
                if (($u['type'] ?? '') == 'free' || getReferralCount($u['id']) >= 5) $openedFree++;
                if (getReferralCount($u['id']) >= 20) $plsDiscount++;
            }
            
            $msg = "📊 <b>آمار کاربران</b>\n\n"
                . "امروز: <b>$daily</b>\n"
                . "هفته اخیر: <b>$weekly</b>\n"
                . "ماه اخیر: <b>$monthly</b>\n"
                . "کل ثبت‌نام: <b>$total</b>\n\n"
                . "🎓 <b>تعداد کسانی که دوره رایگان را باز کردند (۵ دعوت):</b> <b>$openedFree</b>\n"
                . "🚀 <b>تعداد کسانی که به تخفیف ویژه پیشرفته دسترسی دارند (۲۰ دعوت):</b> <b>$plsDiscount</b>";
            sendMessage($chat_id, $msg, $adminKeyboard);
            return true;
            
        case "📊 آمار دعوتی‌ها":
            $users = loadUsers();
            $invited = [];
            foreach ($users as $u) {
                if (isset($u['ref']) && $u['ref'] > 0) {
                    $invited[] = $u['id'];
                }
            }
            $allInvitedCount = count($invited);
            $msg = "📈 <b>آمار پیشرفته دعوتی‌ها</b>\n\n"
                . "تعداد افرادی که با لینک دعوت وارد ربات شده‌اند: <b>$allInvitedCount</b>\n\n"
                . "این عدد مجموع تمام ثبت‌نام‌هایی است که توسط کاربران با لینک اختصاصی وارد شده‌اند.";
            sendMessage($chat_id, $msg, $adminKeyboard);
            return true;
            
        case "📊 آمار پیشرفته دوره":
            showAdvancedStats($chat_id);
            return true;
            
        case "📊 آمار کمپین‌ها":
            showCampaignStats($chat_id);
            return true;
            
        case "📊 آمار کمپین جشنواره":
            showInactiveCampaignStats($chat_id);
            return true;
            
        case "📋 تمرین‌های منتظر": // ✅ قابلیت اصلی که نیاز به تصحیح داشت
            showPendingExercises($chat_id);
            return true;
            
        case "📝 تغییر متن دکمه‌ها":
            $changeBtnKeyboard = [
                ["تغییر ارتباط با کاپیتان"],
                ["تغییر ثبت‌نام دوره پیشرفته"],
                ["🔙 بازگشت به منوی مدیریت"]
            ];
            $admin_state = ['user_id' => $user_id, 'step' => 'change_btn_select'];
            saveAdminState($admin_state);
            sendMessage($chat_id, "کدام دکمه را تغییر می‌دهید؟", $changeBtnKeyboard);
            return true;
            
        case "📢 ارسال پیام همگانی":
            $admin_state = ['user_id' => $user_id, 'step' => 'broadcast'];
            saveAdminState($admin_state);
            sendMessage($chat_id, "📢 <b>ارسال پیام همگانی</b>\n\nمتن یا فایل یا پیام صوتی/ویدیویی خود را ارسال کنید.\n\nبرای انصراف، عبارت 'انصراف' یا /cancel را بفرستید.");
            return true;
        
        case "💼 مدیریت کمپین پایان دوره":
            saveAdminState(['user_id'=>$user_id,'step'=>'campaign_menu']);
            sendMessage($chat_id, "🔔 <b>مدیریت پیام‌های کمپین پایان دوره</b>\n\nیکی از گزینه‌های زیر را انتخاب کنید:", $campaignKeyboard);
            return true;
            
        case "➕ پیام جدید کمپین":
            $admin_state = [
                'user_id' => $user_id,
                'step' => 'campaign_add_delay',
                'new_campaign' => []
            ];
            saveAdminState($admin_state);
            sendMessage($chat_id, "⏳ <b>زمان تاخیر پیام کمپین</b>\n\nزمان تاخیر را به ثانیه وارد کنید:\n\n⏱ مثال‌های زمان‌بندی:\n▪️ 0 = فوری\n▪️ 3600 = 1 ساعت\n▪️ 86400 = 1 روز\n▪️ 259200 = 3 روز");
            return true;
            
        case "📜 مشاهده پیام‌ها":
            $admin_state = [
                'user_id' => $user_id,
                'step' => 'campaign_list'
            ];
            saveAdminState($admin_state);
            handleAdmin($message, $chat_id, '', $user_id); // trigger list display
            return true;
            
        case "🗑 حذف پیام کمپین":
            if (file_exists(CAMPAIGN_MESSAGES_FILE)) {
                $campaigns = json_decode(file_get_contents(CAMPAIGN_MESSAGES_FILE), true) ?: [];
                if (count($campaigns) > 0) {
                    $msg = "🗑 <b>حذف پیام کمپین</b>\n\nشماره پیام مورد نظر را وارد کنید:\n\n";
                    foreach ($campaigns as $i => $camp) {
                        $delay_text = $camp['delay'] == 0 ? "فوری" : $camp['delay'] . " ثانیه";
                        $msg .= "<b>" . ($i+1) . ".</b> زمان: $delay_text\n";
                    }
                    $admin_state = ['user_id' => $user_id, 'step' => 'campaign_delete'];
                    saveAdminState($admin_state);
                    sendMessage($chat_id, $msg);
                } else {
                    sendMessage($chat_id, "هیچ پیام کمپینی برای حذف وجود ندارد.", $campaignKeyboard);
                }
            } else {
                sendMessage($chat_id, "هیچ پیام کمپینی برای حذف وجود ندارد.", $campaignKeyboard);
            }
            return true;
            
        case "🧪 تست کمپین":
            testCampaignForAdmin($chat_id, $user_id);
            return true;
            
        case "🔄 اجرای دستی کمپین":
            manualCampaignExecution($chat_id);
            return true;
            
        case "🔙 بازگشت به منوی مدیریت":
            saveAdminState([]);
            sendMessage($chat_id, "🎩 <b>پنل مدیریت ادمین</b>\n\nلطفا یکی از گزینه‌های زیر را انتخاب کنید:", $adminKeyboard);
            return true;
            
        case "🔙 بازگشت به مدیریت جلسات":
            saveAdminState([]);
            sendMessage($chat_id, "📚 <b>مدیریت دوره رایگان</b>\n\nچه کاری می‌خواهید انجام دهید؟", $courseKeyboard);
            return true;
            
        case "🔙 بازگشت به منوی کاربری":
            saveAdminState([]);
            // بازگشت به منوی کاربری عادی
            if (function_exists('getMainKeyboard')) {
                sendMessage($chat_id, "🔙 بازگشت به منوی کاربری", getMainKeyboard($user_id));
            } else {
                $userKeyboard = [
                    ["🎓 ثبت‌نام دوره رایگان"],
                    ["📊 آمار دعوت‌ها"],
                    ["💬 پشتیبانی آنلاین"]
                ];
                sendMessage($chat_id, "🔙 بازگشت به منوی کاربری", $userKeyboard);
            }
            return true;
    }

    return false;
}

// ✅ تابع callback واحد - جایگزین handleExerciseCallbackEnhanced
function handleExerciseCallbackEnhanced($data) {
    adminDebugLog("Enhanced callback received", ['data' => $data]);
    
    // مشاهده کامل تمرین
    if (preg_match('/^exercise_view_([0-9]+)_([0-9]+)$/', $data, $matches)) {
        $user_id = intval($matches[1]);
        $session_id = intval($matches[2]);
        
        adminDebugLog("Exercise view callback", ['user_id' => $user_id, 'session_id' => $session_id]);
        
        $user = getUserById($user_id);
        if (!$user) {
            adminDebugLog("User not found for exercise view", ['user_id' => $user_id]);
            sendMessage(ADMIN_ID, "❌ کاربر یافت نشد: $user_id");
            return false;
        }
        
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        // ✅ بررسی هر دو حالت: string و integer session_id
        $exercise = null;
        if (isset($exercises[$session_id])) {
            $exercise = $exercises[$session_id];
        } elseif (isset($exercises[strval($session_id)])) {
            $exercise = $exercises[strval($session_id)];
        }
        
        if (!$exercise) {
            adminDebugLog("Exercise not found for user", [
                'user_id' => $user_id, 
                'session_id' => $session_id,
                'available_sessions' => array_keys($exercises)
            ]);
            sendMessage(ADMIN_ID, "❌ تمرین برای این کاربر و جلسه یافت نشد.\n\nکاربر: $user_id\nجلسه: $session_id\nموجود: " . implode(', ', array_keys($exercises)));
            return false;
        }
        
        // پیدا کردن نام جلسه
        $session_title = $exercise['session_title'] ?? '';
        if (empty($session_title)) {
            if (function_exists('loadSessions')) {
                $sessions = loadSessions();
                foreach ($sessions as $sess) {
                    if (intval($sess['id']) == intval($session_id)) {
                        $session_title = $sess['title'];
                        break;
                    }
                }
            }
        }
        
        if (empty($session_title)) {
            $session_title = "جلسه شماره $session_id";
        }
        
        $detailed_msg = "🔍 <b>جزئیات کامل تمرین</b>\n\n";
        $detailed_msg .= "👤 کاربر: <b>{$user['first_name']}</b> (#{$user_id})\n";
        $detailed_msg .= "📚 جلسه: <b>$session_title</b>\n";
        $detailed_msg .= "🆔 Session ID: <code>$session_id</code>\n";
        $detailed_msg .= "📅 زمان ارسال: " . ($exercise['submitted_at'] ?? 'نامشخص') . "\n";
        $detailed_msg .= "📊 وضعیت: <b>منتظر بررسی</b>\n\n";
        $detailed_msg .= "💬 <b>متن کامل تمرین:</b>\n";
        $detailed_msg .= "─────────────────────────\n";
        $detailed_msg .= ($exercise['answer'] ?? 'پاسخ خالی');
        $detailed_msg .= "\n─────────────────────────\n\n";
        
        // اطلاعات اضافی کاربر
        if (function_exists('loadSessions')) {
            $seen_sessions = safeJsonDecode($user['seen_sessions'] ?? null, []);
            $seen_count = is_array($seen_sessions) ? count($seen_sessions) : 0;
            
            $sessions = loadSessions();
            $total_sessions = count($sessions);
            
            $detailed_msg .= "📈 <b>پیشرفت کاربر:</b>\n";
            $detailed_msg .= "جلسات دیده شده: $seen_count / $total_sessions\n";
            if ($total_sessions > 0) {
                $detailed_msg .= "درصد پیشرفت: " . round(($seen_count / $total_sessions) * 100) . "%\n\n";
            }
        }
        
        $detailed_msg .= "🕒 زمان نمایش: " . date('H:i:s');
        
        // ارسال پیام جزئیات
        sendMessage(ADMIN_ID, $detailed_msg);
        
        // ارسال دکمه‌های تایید/رد بعد از نمایش جزئیات
        $reply_markup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ تایید تمرین',
                        'callback_data' => "exercise_accept_{$user_id}_{$session_id}"
                    ],
                    [
                        'text' => '❌ رد تمرین',
                        'callback_data' => "exercise_reject_{$user_id}_{$session_id}"
                    ]
                ]
            ]
        ];
        
        if (defined('API_URL')) {
            $url = API_URL . "sendMessage";
            $data_send = [
                'chat_id' => ADMIN_ID,
                'text' => "🎯 <b>اکنون می‌توانید تمرین را تایید یا رد کنید:</b>",
                'reply_markup' => json_encode($reply_markup)
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_send);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        }
        
        adminDebugLog("Exercise view completed", ['user_id' => $user_id, 'session_id' => $session_id]);
        return true;
    }
    
    // استفاده از تابع اصلی exercises.php برای سایر callback ها
    if (function_exists('handleExerciseCallback')) {
        $result = handleExerciseCallback($data);
        adminDebugLog("Exercise callback result", ['result' => $result, 'data' => $data]);
        return $result;
    } else {
        adminDebugLog("handleExerciseCallback function not found");
        return false;
    }
}
?>
