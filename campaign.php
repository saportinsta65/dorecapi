<?php
/**
 * مدیریت کمپین پیشرفته با تشخیص خودکار آخرین جلسه
 * نسخه بهبود یافته - اکتبر 2025
 */

require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

// تابع یافتن آخرین جلسه از دیتابیس
function getLastSessionNumber() {
    try {
        global $pdo;
        
        // یافتن بالاترین شماره جلسه از جدول sessions
        $stmt = $pdo->query("SELECT MAX(session_number) as max_session FROM sessions ORDER BY session_number DESC LIMIT 1");
        $result = $stmt->fetch();
        
        if ($result && $result['max_session']) {
            $last_session = (int)$result['max_session'];
            error_log("📚 Last session found in database: $last_session");
            return $last_session;
        }
        
        // اگر جدول sessions وجود نداشت، از جدول کاربران بررسی کن
        $stmt = $pdo->query("SELECT exercises FROM users WHERE exercises IS NOT NULL AND exercises != '[]' AND exercises != '' LIMIT 100");
        $max_found = 0;
        
        while ($row = $stmt->fetch()) {
            $exercises = json_decode($row['exercises'], true);
            if (is_array($exercises)) {
                $session_numbers = array_keys($exercises);
                foreach ($session_numbers as $session) {
                    $session_num = (int)$session;
                    if ($session_num > $max_found) {
                        $max_found = $session_num;
                    }
                }
            }
        }
        
        if ($max_found > 0) {
            error_log("📚 Last session found from user data: $max_found");
            return $max_found;
        }
        
        // پیش‌فرض: 17 جلسه
        error_log("⚠️ Could not determine last session, using default: 17");
        return 17;
        
    } catch (Exception $e) {
        error_log("❌ Error finding last session: " . $e->getMessage());
        return 17; // پیش‌فرض
    }
}

// تابع یافتن تعداد جلسات تکمیل شده توسط کاربر
function getUserCompletedSessions($user_id) {
    $user = getUserById($user_id);
    if (!$user) {
        return ['completed' => 0, 'total' => 0, 'sessions' => []];
    }
    
    $exercises = $user['exercises'] ?? [];
    if (is_string($exercises)) {
        $exercises = json_decode($exercises, true) ?: [];
    }
    
    $last_session = getLastSessionNumber();
    $completed_sessions = [];
    $completed_count = 0;
    
    for ($i = 1; $i <= $last_session; $i++) {
        if (isset($exercises[$i]) && $exercises[$i]['status'] === 'accepted') {
            $completed_sessions[] = $i;
            $completed_count++;
        }
    }
    
    return [
        'completed' => $completed_count,
        'total' => $last_session,
        'sessions' => $completed_sessions,
        'last_session' => $last_session
    ];
}

// بررسی اینکه آیا کاربر مجاز به دریافت کمپین است - نسخه هوشمند
function isUserEligibleForCampaign($user_id) {
    error_log("🔍 Checking eligibility for user $user_id");
    
    $user = getUserById($user_id);
    if (!$user) {
        error_log("❌ User $user_id not found in database");
        return false;
    }
    
    // دریافت اطلاعات جلسات کاربر
    $session_info = getUserCompletedSessions($user_id);
    $last_session = $session_info['last_session'];
    $completed_count = $session_info['completed'];
    $total_sessions = $session_info['total'];
    
    error_log("📊 User $user_id session stats: $completed_count/$total_sessions completed, last session: $last_session");
    
    // بررسی اینکه آیا آخرین تمرین تایید شده
    $exercises = $user['exercises'] ?? [];
    if (is_string($exercises)) {
        $exercises = json_decode($exercises, true) ?: [];
    }
    
    // روش 1: بررسی وضعیت کل دوره (اگر فیلد course_completed وجود دارد)
    if (isset($user['course_completed']) && $user['course_completed'] == 1) {
        error_log("✅ User $user_id has course_completed = 1");
        return true;
    }
    
    // روش 2: بررسی آخرین جلسه (جلسه dynamic)
    if (isset($exercises[$last_session]) && $exercises[$last_session]['status'] === 'accepted') {
        error_log("✅ User $user_id completed final session $last_session");
        return true;
    }
    
    // روش 3: بررسی که آیا همه جلسات تکمیل شده‌اند
    if ($completed_count >= $total_sessions) {
        error_log("✅ User $user_id completed all $total_sessions sessions");
        return true;
    }
    
    // روش 4: بررسی 90% جلسات (اختیاری - برای انعطاف)
    $required_percentage = 0.9; // 90%
    $required_sessions = ceil($total_sessions * $required_percentage);
    
    if ($completed_count >= $required_sessions) {
        error_log("✅ User $user_id completed $completed_count/$total_sessions sessions (≥90%)");
        return true;
    }
    
    error_log("❌ User $user_id not eligible: $completed_count/$total_sessions sessions completed");
    error_log("📋 Completed sessions: " . implode(', ', $session_info['sessions']));
    
    return false;
}

// ذخیره داده‌های کمپین با استفاده از ستون‌های موجود
function saveCampaignDataExisting($user_id, $campaign_data) {
    try {
        global $pdo;
        
        $started = $campaign_data['started'] ? 1 : 0;
        $start_time = $campaign_data['start_time'] ?? null;
        $sent_steps = json_encode($campaign_data['sent_steps'] ?? [], JSON_UNESCAPED_UNICODE);
        $discount_code = $campaign_data['discount_code'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE users SET 
                campaign_started = ?,
                campaign_start_time = ?,
                campaign_sent_steps = ?,
                campaign_discount_code = ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $started,
            $start_time,
            $sent_steps,
            $discount_code,
            $user_id
        ]);
        
        if ($result) {
            error_log("✅ Campaign data saved successfully for user $user_id");
            return true;
        } else {
            $errorInfo = $pdo->errorInfo();
            error_log("❌ Failed to save campaign data for user $user_id: " . json_encode($errorInfo));
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ Exception in saveCampaignDataExisting for user $user_id: " . $e->getMessage());
        return false;
    }
}

// خواندن داده‌های کمپین از ستون‌های موجود
function getCampaignDataExisting($user_id) {
    try {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT campaign_started, campaign_start_time, campaign_sent_steps, campaign_discount_code 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $sent_steps = [];
            if ($result['campaign_sent_steps']) {
                $decoded = json_decode($result['campaign_sent_steps'], true);
                if (is_array($decoded)) {
                    $sent_steps = $decoded;
                }
            }
            
            return [
                'started' => (bool)$result['campaign_started'],
                'start_time' => $result['campaign_start_time'],
                'sent_steps' => $sent_steps,
                'discount_code' => $result['campaign_discount_code'] ?? ''
            ];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log("❌ Exception in getCampaignDataExisting for user $user_id: " . $e->getMessage());
        return [];
    }
}

// شروع کمپین - نسخه بهبود یافته
function startCampaign($user_id) {
    try {
        error_log("🔄 Starting campaign for user $user_id");
        
        // بررسی وجود کاربر
        $user = getUserById($user_id);
        if (!$user) {
            error_log("❌ User $user_id not found for starting campaign");
            return false;
        }
        
        // دریافت آمار جلسات کاربر
        $session_info = getUserCompletedSessions($user_id);
        
        // لاگ اطلاعات کاربر
        error_log("👤 User $user_id info: " . json_encode([
            'id' => $user['id'] ?? 'N/A',
            'username' => $user['username'] ?? 'N/A',
            'completed_sessions' => $session_info['completed'] . '/' . $session_info['total'],
            'last_session_completed' => in_array($session_info['last_session'], $session_info['sessions']),
            'course_completed' => $user['course_completed'] ?? 'N/A'
        ]));
        
        // بررسی واجد شرایط بودن کاربر
        if (!isUserEligibleForCampaign($user_id)) {
            error_log("User $user_id completed course but not eligible for campaign");
            
            // ارسال اطلاعات تشخیصی به ادمین
            if (defined('ADMIN_ID')) {
                $debug_msg = "🔍 کاربر $user_id واجد شرایط کمپین نیست:\n\n";
                $debug_msg .= "📊 آمار جلسات:\n";
                $debug_msg .= "- تکمیل شده: {$session_info['completed']}/{$session_info['total']}\n";
                $debug_msg .= "- جلسه آخر: {$session_info['last_session']}\n";
                $debug_msg .= "- جلسات تکمیل: " . implode(', ', $session_info['sessions']) . "\n\n";
                $debug_msg .= "🔧 برای تست دستی: /test_campaign_$user_id";
                sendMessage(ADMIN_ID, $debug_msg);
            }
            
            return false;
        }
        
        // بررسی کمپین قبلی
        $existing_campaign = getCampaignDataExisting($user_id);
        if (isset($existing_campaign['started']) && $existing_campaign['started']) {
            error_log("⚠️ Campaign already started for user $user_id");
            return false;
        }
        
        // تولید کد تخفیف
        $discount_code = generateDiscountCode($user_id);
        error_log("🎯 Generated discount code for user $user_id: $discount_code");
        
        // ایجاد داده‌های کمپین
        $campaign = [
            'started' => true,
            'start_time' => date('Y-m-d H:i:s'),
            'sent_steps' => [],
            'discount_code' => $discount_code
        ];
        
        // ذخیره
        $save_result = saveCampaignDataExisting($user_id, $campaign);
        
        if ($save_result) {
            error_log("✅ Campaign started successfully for user $user_id, discount_code: $discount_code");
            
            // ارسال پیام اول
            $send_result = sendCampaignStep($user_id, 0);
            
            if ($send_result) {
                error_log("✅ First campaign message sent to user $user_id");
                
                // اطلاع به ادمین
                if (defined('ADMIN_ID')) {
                    $success_msg = "🎉 کمپین برای کاربر $user_id شروع شد!\n";
                    $success_msg .= "🎯 کد تخفیف: $discount_code\n";
                    $success_msg .= "📊 جلسات تکمیل: {$session_info['completed']}/{$session_info['total']}";
                    sendMessage(ADMIN_ID, $success_msg);
                }
            } else {
                error_log("⚠️ Campaign started but first message failed for user $user_id");
            }
            
            return true;
        } else {
            error_log("❌ Failed to save campaign data for user $user_id");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ Exception in startCampaign for user $user_id: " . $e->getMessage());
        return false;
    }
}

// تابع تست برای بررسی دستی
function testUserEligibility($user_id) {
    error_log("🧪 Testing eligibility for user $user_id");
    
    $user = getUserById($user_id);
    if (!$user) {
        return "❌ کاربر یافت نشد";
    }
    
    $session_info = getUserCompletedSessions($user_id);
    $exercises = $user['exercises'] ?? [];
    if (is_string($exercises)) {
        $exercises = json_decode($exercises, true) ?: [];
    }
    
    $result = [
        'user_id' => $user_id,
        'course_completed' => $user['course_completed'] ?? 'فیلد موجود نیست',
        'session_stats' => $session_info,
        'exercises_raw' => $exercises,
        'is_eligible' => isUserEligibleForCampaign($user_id),
        'existing_campaign' => getCampaignDataExisting($user_id)
    ];
    
    return "```json\n" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```";
}

// سایر توابع بدون تغییر...
function getCampaignSteps() {
    $json_file = 'campaign_messages.json';
    
    if (file_exists($json_file)) {
        $json_content = file_get_contents($json_file);
        $campaigns = json_decode($json_content, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($campaigns)) {
            error_log("Campaign steps loaded from JSON file: " . count($campaigns) . " steps");
            return $campaigns;
        } else {
            error_log("ERROR: Invalid JSON in campaign_messages.json - " . json_last_error_msg());
        }
    } else {
        error_log("WARNING: campaign_messages.json not found, using defaults");
    }
    
    // پیام‌های پیش‌فرض
    return [
        [
            'delay' => 0,
            'contents' => [
                [
                    'type' => 'text',
                    'content' => "🎁 تبریک! شما دوره رایگان را به پایان رساندید.\n\nکد تخفیف اختصاصی شما برای ثبت‌نام دوره پیشرفته:\n\n<code>{discount_code}</code>\n\nبرای استفاده فقط تا ۳ روز فرصت داری!"
                ]
            ]
        ],
        [
            'delay' => 3600, // 1 ساعت
            'contents' => [
                [
                    'type' => 'text',
                    'content' => "⏰ یادت نره! فقط ۳ روز فرصت داری با کد تخفیف <code>{discount_code}</code> عضو دوره پیشرفته بشی."
                ]
            ]
        ],
        [
            'delay' => 86400, // 1 روز
            'contents' => [
                [
                    'type' => 'text',
                    'content' => "🔔 فرصت ویژه برای عضویت در دوره پیشرفته با تخفیف هنوز فعال است!\nکد تخفیف: <code>{discount_code}</code>"
                ]
            ]
        ],
        [
            'delay' => 259200, // 3 روز
            'contents' => [
                [
                    'type' => 'text',
                    'content' => "⏳ آخرین روز تخفیف! همین حالا عضو شو و مسیر حرفه‌ای ترید را شروع کن.\nکد تخفیف: <code>{discount_code}</code>"
                ]
            ]
        ]
    ];
}

function generateDiscountCode($user_id) {
    $timestamp = date('md');
    return "FX" . $user_id . $timestamp . rand(10, 99);
}

// ارسال پیام کمپین
function sendCampaignStep($user_id, $step_index) {
    try {
        error_log("📤 Attempting to send campaign step $step_index to user $user_id");
        
        $campaign_steps = getCampaignSteps();
        $campaign = getCampaignDataExisting($user_id);
        
        if (!isset($campaign['started']) || !$campaign['started']) {
            error_log("❌ Campaign not started for user $user_id");
            return false;
        }
        
        if (!isset($campaign_steps[$step_index])) {
            error_log("❌ Campaign step $step_index not found");
            return false;
        }
        
        $discount_code = $campaign['discount_code'] ?? '';
        $step = $campaign_steps[$step_index];
        
        $message_sent = false;
        foreach ($step['contents'] as $content) {
            if ($content['type'] === 'text') {
                $msg = str_replace('{discount_code}', $discount_code, $content['content']);
                error_log("📨 Sending text message to user $user_id: " . substr($msg, 0, 50) . "...");
                
                $send_result = sendMessage($user_id, $msg);
                if ($send_result) {
                    $message_sent = true;
                    error_log("✅ Text message sent successfully to user $user_id");
                } else {
                    error_log("❌ Failed to send text message to user $user_id");
                }
                
            } elseif (in_array($content['type'], ['document', 'video', 'audio', 'voice', 'photo'])) {
                $caption = isset($content['caption']) ? str_replace('{discount_code}', $discount_code, $content['caption']) : '';
                error_log("📎 Sending file to user $user_id: type={$content['type']}");
                
                $send_result = sendFile($user_id, $content['type'], $content['file_id'], $caption);
                if ($send_result) {
                    $message_sent = true;
                    error_log("✅ File sent successfully to user $user_id");
                } else {
                    error_log("❌ Failed to send file to user $user_id");
                }
            }
        }
        
        // ثبت مرحله ارسال شده
        if (!in_array($step_index, $campaign['sent_steps'])) {
            $campaign['sent_steps'][] = $step_index;
            $save_result = saveCampaignDataExisting($user_id, $campaign);
            
            if ($save_result) {
                error_log("✅ Campaign step $step_index marked as sent for user $user_id");
            } else {
                error_log("❌ Failed to mark campaign step $step_index as sent for user $user_id");
            }
        }
        
        return $message_sent;
        
    } catch (Exception $e) {
        error_log("❌ Exception in sendCampaignStep: " . $e->getMessage());
        return false;
    }
}

// پردازش کمپین‌ها
function processCampaignNotifications() {
    try {
        error_log("🚀 Campaign processing started at " . date('Y-m-d H:i:s'));
        
        global $pdo;
        $campaign_steps = getCampaignSteps();
        $total_steps = count($campaign_steps);
        
        $stmt = $pdo->prepare("
            SELECT id, campaign_started, campaign_start_time, campaign_sent_steps 
            FROM users 
            WHERE campaign_started = 1
            AND (
                JSON_LENGTH(campaign_sent_steps) < ?
                OR campaign_sent_steps IS NULL 
                OR campaign_sent_steps = '[]'
            )
            ORDER BY campaign_start_time ASC
        ");
        $stmt->execute([$total_steps]);
        
        $users_processed = 0;
        $messages_sent = 0;
        $now = time();
        
        error_log("⏰ Current timestamp: $now (" . date('Y-m-d H:i:s', $now) . ")");
        
        while ($row = $stmt->fetch()) {
            $user_id = $row['id'];
            $users_processed++;
            
            $start_time = strtotime($row['campaign_start_time']);
            $sent_steps = json_decode($row['campaign_sent_steps'], true) ?: [];
            
            error_log("👤 Processing user $user_id - started: {$row['campaign_start_time']}");
            
            foreach ($campaign_steps as $step_index => $step) {
                if (in_array($step_index, $sent_steps)) {
                    continue;
                }
                
                $elapsed = $now - $start_time;
                
                if ($elapsed >= $step['delay']) {
                    error_log("✅ User $user_id eligible for step $step_index (elapsed: {$elapsed}s >= delay: {$step['delay']}s)");
                    
                    $send_result = sendCampaignStep($user_id, $step_index);
                    if ($send_result) {
                        $messages_sent++;
                    }
                } else {
                    $remaining = $step['delay'] - $elapsed;
                    error_log("⏳ User $user_id: Step $step_index waiting ({$remaining}s more)");
                    break;
                }
            }
        }
        
        error_log("📊 Campaign processing completed - Users: $users_processed, Messages sent: $messages_sent");
        
        if ($messages_sent > 0 && defined('ADMIN_ID')) {
            $admin_msg = "📊 گزارش کمپین:\n$messages_sent پیام به $users_processed کاربر ارسال شد.";
            sendMessage(ADMIN_ID, $admin_msg);
        }
        
    } catch (Exception $e) {
        error_log("❌ CRITICAL ERROR in processCampaignNotifications: " . $e->getMessage());
        
        if (defined('ADMIN_ID')) {
            sendMessage(ADMIN_ID, "🚨 خطای جدی در سیستم کمپین:\n" . $e->getMessage());
        }
    }
}

// اجرای خودکار توسط کرون جاب
if (php_sapi_name() === 'cli' || (isset($argv) && in_array('campaign_cron', $argv))) {
    error_log("🚀 Campaign cron triggered at " . date('Y-m-d H:i:s'));
    processCampaignNotifications();
}
?>