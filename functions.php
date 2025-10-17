<?php
/**
 * توابع اصلی ربات تلگرام
 * نسخه کامل و بهبود یافته - 11 اکتبر 2025
 * با پشتیبانی لاگ هوشمند و مدیریت بهینه خطاها - نسخه ایمن
 */

require_once 'config.php';
require_once 'db.php'; // فایل اتصال PDO به دیتابیس

/**
 * لاگ هوشمند - فقط خطاهای مهم
 */
function smartLog($message, $level = 'INFO') {
    // فقط خطاهای مهم لاگ شوند
    $important_levels = ['ERROR', 'CRITICAL', 'WARNING'];
    
    if (in_array($level, $important_levels) || 
        (defined('DEBUG_MODE') && DEBUG_MODE)) {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [$level] $message");
    }
}

// ارسال پیام متنی ساده (با یا بدون کیبورد) - نسخه اصلاح شده
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        // محدودیت طول پیام تلگرام (4096 کاراکتر)
        if (strlen($text) > 4096) {
            $text = substr($text, 0, 4093) . '...';
        }
        
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $data['reply_markup'] = json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true]);
        }
        
        $ch = curl_init(API_URL . "sendMessage");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // بررسی خطاهای cURL
        if ($curl_error) {
            smartLog("cURL error in sendMessage to $chat_id: $curl_error", 'ERROR');
            return false;
        }
        
        // بررسی کد HTTP
        if ($http_code !== 200) {
            smartLog("HTTP error in sendMessage to $chat_id: $http_code", 'WARNING');
            return false;
        }
        
        // بررسی پاسخ تلگرام
        $response_data = json_decode($response, true);
        if ($response_data && isset($response_data['ok']) && $response_data['ok']) {
            return true;
        } else {
            $error_desc = $response_data['description'] ?? 'Unknown error';
            
            // خطاهای مهم را لاگ کن، مسدود شدن کاربر را نه
            if (strpos($error_desc, 'blocked') !== false || strpos($error_desc, 'user is deactivated') !== false) {
                // کاربر ربات را بلاک کرده - لاگ نکن
                return false;
            } else {
                smartLog("Telegram API error in sendMessage to $chat_id: $error_desc", 'WARNING');
            }
            
            return false;
        }
        
    } catch (Exception $e) {
        smartLog("Exception in sendMessage to $chat_id: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// ارسال انواع فایل به کاربر (document, audio, voice, video, photo) - نسخه اصلاح شده
function sendFile($chat_id, $type, $file_id, $caption = '') {
    try {
        // محدودیت طول caption (1024 کاراکتر)
        if (strlen($caption) > 1024) {
            $caption = substr($caption, 0, 1021) . '...';
        }
        
        $data = [
            'chat_id' => $chat_id
        ];
        
        if (!empty($caption)) {
            $data['caption'] = $caption;
            $data['parse_mode'] = 'HTML';
        }
        
        $api_method = '';
        switch ($type) {
            case 'document':
                $api_method = 'sendDocument'; 
                $data['document'] = $file_id;
                break;
            case 'video':
                $api_method = 'sendVideo'; 
                $data['video'] = $file_id;
                break;
            case 'audio':
                $api_method = 'sendAudio'; 
                $data['audio'] = $file_id;
                break;
            case 'voice':
                $api_method = 'sendVoice'; 
                $data['voice'] = $file_id;
                break;
            case 'photo':
                $api_method = 'sendPhoto'; 
                $data['photo'] = $file_id;
                break;
            default:
                smartLog("Unknown file type: $type", 'ERROR');
                return false;
        }
        
        $ch = curl_init(API_URL . $api_method);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // فایل‌ها زمان بیشتری نیاز دارند
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // بررسی خطاهای cURL
        if ($curl_error) {
            smartLog("cURL error in sendFile to $chat_id: $curl_error", 'ERROR');
            return false;
        }
        
        // بررسی کد HTTP
        if ($http_code !== 200) {
            smartLog("HTTP error in sendFile to $chat_id: $http_code", 'WARNING');
            return false;
        }
        
        // بررسی پاسخ تلگرام
        $response_data = json_decode($response, true);
        if ($response_data && isset($response_data['ok']) && $response_data['ok']) {
            return true;
        } else {
            $error_desc = $response_data['description'] ?? 'Unknown error';
            smartLog("Telegram API error in sendFile to $chat_id: $error_desc", 'WARNING');
            return false;
        }
        
    } catch (Exception $e) {
        smartLog("Exception in sendFile to $chat_id: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// --- ثبت و بروزرسانی آخرین فعالیت کاربر ---
function updateLastActivity($user_id) {
    try {
        $user = getUserById($user_id);
        if ($user) {
            $user['last_activity'] = date('Y-m-d H:i:s');
            return saveUser($user);
        }
        return false;
    } catch (Exception $e) {
        smartLog("Error updating last activity for user $user_id: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// --- دریافت آخرین فعالیت کاربر (زمان یونیکس) ---
function getLastActivity($user_id) {
    try {
        $user = getUserById($user_id);
        if (isset($user['last_activity']) && strtotime($user['last_activity']) > 0) {
            return strtotime($user['last_activity']);
        }
        if (isset($user['registered_at']) && strtotime($user['registered_at']) > 0) {
            return strtotime($user['registered_at']);
        }
        return 0;
    } catch (Exception $e) {
        smartLog("Error getting last activity for user $user_id: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// خواندن تمام کاربران از دیتابیس
function loadUsers() {
    try {
        global $pdo;
        $stmt = $pdo->query("SELECT * FROM users");
        $users = [];
        
        while ($row = $stmt->fetch()) {
            // اصلاح: بررسی نوع داده قبل از json_decode
            $row['campaign_sent_steps'] = $row['campaign_sent_steps'] ? 
                (is_string($row['campaign_sent_steps']) ? json_decode($row['campaign_sent_steps'], true) : $row['campaign_sent_steps']) : [];
            
            $row['exercises'] = isset($row['exercises']) && $row['exercises'] ? 
                (is_string($row['exercises']) ? json_decode($row['exercises'], true) : $row['exercises']) : [];
            
            $row['seen_sessions'] = isset($row['seen_sessions']) && $row['seen_sessions'] ? 
                (is_string($row['seen_sessions']) ? json_decode($row['seen_sessions'], true) : $row['seen_sessions']) : [];
            
            $row['campaign'] = isset($row['campaign']) && $row['campaign'] ? 
                (is_string($row['campaign']) ? json_decode($row['campaign'], true) : $row['campaign']) : [];
            
            $users[$row['id']] = $row;
        }
        
        return $users;
    } catch (Exception $e) {
        smartLog("Error loading users: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// دریافت یک کاربر از دیتابیس - اصلاح شده
function getUserById($user_id) {
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        
        if ($row) {
            // اصلاح: بررسی نوع داده قبل از json_decode
            $row['campaign_sent_steps'] = $row['campaign_sent_steps'] ? 
                (is_string($row['campaign_sent_steps']) ? json_decode($row['campaign_sent_steps'], true) : $row['campaign_sent_steps']) : [];
            
            $row['exercises'] = isset($row['exercises']) && $row['exercises'] ? 
                (is_string($row['exercises']) ? json_decode($row['exercises'], true) : $row['exercises']) : [];
            
            $row['seen_sessions'] = isset($row['seen_sessions']) && $row['seen_sessions'] ? 
                (is_string($row['seen_sessions']) ? json_decode($row['seen_sessions'], true) : $row['seen_sessions']) : [];
            
            $row['campaign'] = isset($row['campaign']) && $row['campaign'] ? 
                (is_string($row['campaign']) ? json_decode($row['campaign'], true) : $row['campaign']) : [];
            
            return $row;
        }
        
        return null;
    } catch (Exception $e) {
        smartLog("Error getting user $user_id: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

// ✅ ذخیره یا بروزرسانی یک کاربر در دیتابیس - نسخه ایمن و تصحیح شده
function saveUser($user) {
    try {
        global $pdo;
        
        // اعتبارسنجی داده‌های ورودی
        if (!isset($user['id']) || empty($user['id'])) {
            smartLog("Cannot save user: ID is missing", 'ERROR');
            return false;
        }
        
        // ✅ بررسی هوشمند وجود فیلدهای inactive_campaign در دیتابیس
        try {
            // تست سریع برای بررسی وجود فیلدها
            $test_stmt = $pdo->prepare("SELECT inactive_campaign_started FROM users LIMIT 1");
            $test_stmt->execute();
            $has_inactive_fields = true;
        } catch (Exception $e) {
            // فیلدها وجود ندارند
            $has_inactive_fields = false;
        }
        
        if ($has_inactive_fields) {
            // ✅ دیتابیس شامل فیلدهای جدید است
            $sql = "REPLACE INTO users 
                (id, first_name, username, registered_at, type, mobile, last_activity, channels_checked, inactivity_remind, ref, discount_code, campaign_started, campaign_start_time, campaign_sent_steps, campaign_discount_code, exercises, seen_sessions, campaign, inactive_campaign_started, inactive_campaign_start_time)
                VALUES (:id, :first_name, :username, :registered_at, :type, :mobile, :last_activity, :channels_checked, :inactivity_remind, :ref, :discount_code, :campaign_started, :campaign_start_time, :campaign_sent_steps, :campaign_discount_code, :exercises, :seen_sessions, :campaign, :inactive_campaign_started, :inactive_campaign_start_time)";
            
            $params = [
                ':id' => $user['id'],
                ':first_name' => $user['first_name'] ?? '',
                ':username' => $user['username'] ?? '',
                ':registered_at' => $user['registered_at'] ?? date('Y-m-d H:i:s'),
                ':type' => $user['type'] ?? '',
                ':mobile' => $user['mobile'] ?? '',
                ':last_activity' => $user['last_activity'] ?? date('Y-m-d H:i:s'),
                ':channels_checked' => $user['channels_checked'] ?? 0,
                ':inactivity_remind' => $user['inactivity_remind'] ?? 0,
                ':ref' => $user['ref'] ?? null,
                ':discount_code' => $user['discount_code'] ?? '',
                ':campaign_started' => $user['campaign_started'] ?? 0,
                ':campaign_start_time' => $user['campaign_start_time'] ?? null,
                ':campaign_sent_steps' => isset($user['campaign_sent_steps']) ? json_encode($user['campaign_sent_steps'], JSON_UNESCAPED_UNICODE) : '[]',
                ':campaign_discount_code' => $user['campaign_discount_code'] ?? '',
                ':exercises' => isset($user['exercises']) ? json_encode($user['exercises'], JSON_UNESCAPED_UNICODE) : '{}',
                ':seen_sessions' => isset($user['seen_sessions']) ? json_encode($user['seen_sessions'], JSON_UNESCAPED_UNICODE) : '[]',
                ':campaign' => isset($user['campaign']) ? json_encode($user['campaign'], JSON_UNESCAPED_UNICODE) : '{}',
                ':inactive_campaign_started' => $user['inactive_campaign_started'] ?? 0,
                ':inactive_campaign_start_time' => $user['inactive_campaign_start_time'] ?? null
            ];
        } else {
            // ✅ دیتابیس فاقد فیلدهای جدید است - استفاده از ساختار قدیمی
            $sql = "REPLACE INTO users 
                (id, first_name, username, registered_at, type, mobile, last_activity, channels_checked, inactivity_remind, ref, discount_code, campaign_started, campaign_start_time, campaign_sent_steps, campaign_discount_code, exercises, seen_sessions, campaign)
                VALUES (:id, :first_name, :username, :registered_at, :type, :mobile, :last_activity, :channels_checked, :inactivity_remind, :ref, :discount_code, :campaign_started, :campaign_start_time, :campaign_sent_steps, :campaign_discount_code, :exercises, :seen_sessions, :campaign)";
            
            $params = [
                ':id' => $user['id'],
                ':first_name' => $user['first_name'] ?? '',
                ':username' => $user['username'] ?? '',
                ':registered_at' => $user['registered_at'] ?? date('Y-m-d H:i:s'),
                ':type' => $user['type'] ?? '',
                ':mobile' => $user['mobile'] ?? '',
                ':last_activity' => $user['last_activity'] ?? date('Y-m-d H:i:s'),
                ':channels_checked' => $user['channels_checked'] ?? 0,
                ':inactivity_remind' => $user['inactivity_remind'] ?? 0,
                ':ref' => $user['ref'] ?? null,
                ':discount_code' => $user['discount_code'] ?? '',
                ':campaign_started' => $user['campaign_started'] ?? 0,
                ':campaign_start_time' => $user['campaign_start_time'] ?? null,
                ':campaign_sent_steps' => isset($user['campaign_sent_steps']) ? json_encode($user['campaign_sent_steps'], JSON_UNESCAPED_UNICODE) : '[]',
                ':campaign_discount_code' => $user['campaign_discount_code'] ?? '',
                ':exercises' => isset($user['exercises']) ? json_encode($user['exercises'], JSON_UNESCAPED_UNICODE) : '{}',
                ':seen_sessions' => isset($user['seen_sessions']) ? json_encode($user['seen_sessions'], JSON_UNESCAPED_UNICODE) : '[]',
                ':campaign' => isset($user['campaign']) ? json_encode($user['campaign'], JSON_UNESCAPED_UNICODE) : '{}'
            ];
        }
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            return true;
        } else {
            $errorInfo = $pdo->errorInfo();
            smartLog("Failed to save user {$user['id']}: " . json_encode($errorInfo), 'ERROR');
            return false;
        }
        
    } catch (Exception $e) {
        smartLog("Exception in saveUser for user {$user['id']}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// ثبت یا آپدیت اطلاعات کاربر
function registerUser($user) {
    try {
        $old = getUserById($user['id']);
        
        if (!$old) {
            // کاربر جدید
            $user['registered_at'] = date('Y-m-d H:i:s');
            $user['last_activity'] = date('Y-m-d H:i:s');
            $user['exercises'] = $user['exercises'] ?? [];
            $user['seen_sessions'] = $user['seen_sessions'] ?? [];
            $user['campaign'] = $user['campaign'] ?? [];
        } else {
            // کاربر موجود
            if (!empty($user['mobile'])) $old['mobile'] = $user['mobile'];
            if (!empty($user['type'])) $old['type'] = $user['type'];
            if (isset($user['first_name'])) $old['first_name'] = $user['first_name'];
            if (isset($user['username'])) $old['username'] = $user['username'];
            
            $old['last_activity'] = date('Y-m-d H:i:s');
            $user = array_merge($old, $user);
        }
        
        return saveUser($user);
    } catch (Exception $e) {
        smartLog("Error registering user {$user['id']}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// خواندن جلسات از دیتابیس
function loadSessions() {
    try {
        global $pdo;
        $stmt = $pdo->query("SELECT * FROM sessions ORDER BY id ASC");
        $sessions = [];
        
        while ($row = $stmt->fetch()) {
            $row['files'] = $row['files'] ? 
                (is_string($row['files']) ? json_decode($row['files'], true) : $row['files']) : [];
            $sessions[] = $row;
        }
        
        return $sessions;
    } catch (Exception $e) {
        smartLog("Error loading sessions: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// ذخیره یک جلسه جدید در دیتابیس
function saveSession($session) {
    try {
        global $pdo;
        
        if (isset($session['id']) && $session['id']) {
            $sql = "UPDATE sessions SET title=:title, text=:text, exercise=:exercise, files=:files WHERE id=:id";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                ':title' => $session['title'] ?? '',
                ':text' => $session['text'] ?? '',
                ':exercise' => $session['exercise'] ?? '',
                ':files' => json_encode($session['files'] ?? [], JSON_UNESCAPED_UNICODE),
                ':id' => $session['id']
            ]);
        } else {
            $sql = "INSERT INTO sessions (title, text, exercise, files) VALUES (:title, :text, :exercise, :files)";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                ':title' => $session['title'] ?? '',
                ':text' => $session['text'] ?? '',
                ':exercise' => $session['exercise'] ?? '',
                ':files' => json_encode($session['files'] ?? [], JSON_UNESCAPED_UNICODE)
            ]);
        }
        
        return $result;
    } catch (Exception $e) {
        smartLog("Error saving session: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// ذخیره همه جلسات (در صورت نیاز به آپدیت دسته‌ای)
function saveSessions($sessions) {
    $success = true;
    foreach ($sessions as $session) {
        if (!saveSession($session)) {
            $success = false;
        }
    }
    return $success;
}

// خواندن وضعیت ادمین از فایل
function loadAdminState() {
    try {
        if (!file_exists(ADMIN_STATE_FILE)) return [];
        $content = file_get_contents(ADMIN_STATE_FILE);
        return json_decode($content, true) ?: [];
    } catch (Exception $e) {
        smartLog("Error loading admin state: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// ذخیره وضعیت ادمین در فایل
function saveAdminState($state) {
    try {
        return file_put_contents(ADMIN_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    } catch (Exception $e) {
        smartLog("Error saving admin state: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// خواندن دکمه ارتباط با کاپیتان
function loadBtnCaptain() {
    try {
        if (file_exists(BTN_CAPTAIN_FILE)) {
            $content = file_get_contents(BTN_CAPTAIN_FILE);
            $data = json_decode($content, true);
            if ($data && isset($data['btn']) && isset($data['msg'])) {
                return $data;
            }
        }
        
        return ['btn'=>'💬 ارتباط با کاپیتان','msg'=>'📞 برای ارتباط با مدیر با آی‌دی زیر تماس بگیرید:\n@capitantraderfx'];
    } catch (Exception $e) {
        smartLog("Error loading captain button: " . $e->getMessage(), 'ERROR');
        return ['btn'=>'💬 ارتباط با کاپیتان','msg'=>'📞 برای ارتباط با مدیر با آی‌دی زیر تماس بگیرید:\n@capitantraderfx'];
    }
}

// خواندن دکمه ثبت‌نام دوره پیشرفته
function loadBtnAdvanced() {
    try {
        if (file_exists(BTN_ADVANCED_FILE)) {
            $content = file_get_contents(BTN_ADVANCED_FILE);
            $data = json_decode($content, true);
            if ($data && isset($data['btn']) && isset($data['msg'])) {
                return $data;
            }
        }
        
        return ['btn'=>'🚀 ثبت‌نام دوره پیشرفته PLS','msg'=>'✨ ثبت‌نام شما در دوره پیشرفته ثبت شد!'];
    } catch (Exception $e) {
        smartLog("Error loading advanced button: " . $e->getMessage(), 'ERROR');
        return ['btn'=>'🚀 ثبت‌نام دوره پیشرفته PLS','msg'=>'✨ ثبت‌نام شما در دوره پیشرفته ثبت شد!'];
    }
}

// چک عضویت کاربر در کانال (API تلگرام)
function checkChannelMember($user_id, $channel) {
    try {
        $url = API_URL . "getChatMember?chat_id=" . urlencode($channel) . "&user_id=" . urlencode($user_id);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);
        
        $result = file_get_contents($url, false, $context);
        if (!$result) {
            smartLog("Failed to check channel membership for user $user_id in channel $channel", 'WARNING');
            return false;
        }
        
        $data = json_decode($result, true);
        
        if (!isset($data['result']['status'])) {
            return false;
        }
        
        $status = $data['result']['status'];
        return in_array($status, ['member', 'creator', 'administrator']);
        
    } catch (Exception $e) {
        smartLog("Exception checking channel membership: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// --- پشتیبانی آنلاین ---
function loadSupportState() {
    try {
        if (!file_exists(SUPPORT_STATE_FILE)) return [];
        $content = file_get_contents(SUPPORT_STATE_FILE);
        return json_decode($content, true) ?: [];
    } catch (Exception $e) {
        smartLog("Error loading support state: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function saveSupportState($state) {
    try {
        return file_put_contents(SUPPORT_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    } catch (Exception $e) {
        smartLog("Error saving support state: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// --- توابع یادآوری و نوتیفیکیشن دوره ---
function sendCourseReminder($user_id) {
    try {
        require_once 'referral.php';
        $unseen = getUnseenSessions($user_id);
        
        if (count($unseen) > 0) {
            $msg = "⏰ <b>یادآوری دوره رایگان!</b>\n\n"
                 . "شما هنوز جلسات زیر را ندیده‌اید:\n";
            
            foreach ($unseen as $title) {
                $msg .= "• $title\n";
            }
            
            $msg .= "\nبهت پیشنهاد می‌کنم همین امروز ادامه بده تا زودتر به موفقیت برسی 💪";
            
            return sendMessage($user_id, $msg);
        }
        
        return true;
    } catch (Exception $e) {
        smartLog("Error sending course reminder to user $user_id: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// --- یادآوری کاربران غیرفعال (۳، ۱۰، ۳۰ روز بدون فعالیت) ---
function sendInactivityReminders() {
    try {
        $users = loadUsers();
        $now = time();
        $reminders_sent = 0;
        
        foreach ($users as $user) {
            $reminder_state = isset($user['inactivity_remind']) ? intval($user['inactivity_remind']) : 0;
            $last = getLastActivity($user['id']);
            $days = floor(($now - $last) / 86400);
            
            $message = '';
            $new_state = $reminder_state;
            
            if ($days >= 30 && $reminder_state < 3) {
                $message = "⏰ <b>آخرین فرصت مشاهده دوره رایگان!</b>\n\nمدت زیادیست هیچ فعالیتی نداشتی! اگر می‌خواهی همچنان به دوره دسترسی داشته باشی، همین حالا ادامه بده. احتمال دارد دوره رایگان به زودی برای شما غیرفعال شود.";
                $new_state = 3;
            } elseif ($days >= 10 && $reminder_state < 2) {
                $message = "🚨 هنوز دوره رایگان را کامل نکردی!\n\n۱۰ روز گذشته و هیچ فعالیتی نداشتی. اگر نمی‌خواهی فرصت طلایی ت رو از دست بدی همین الان دوره را ادامه بده. بعداً شاید دیگر به این دوره دسترسی نداشته باشی!";
                $new_state = 2;
            } elseif ($days >= 3 && $reminder_state < 1) {
                $message = "👀 هنوز دوره رایگان را کامل ندیدی!\n\n۳ روز است هیچ فعالیتی نداشتی. اگر می‌خواهی از این فرصت عالی استفاده کنی همین الان ادامه بده. شاید بعداً این فرصت از دستت بره!";
                $new_state = 1;
            }
            
            if ($message) {
                if (sendMessage($user['id'], $message)) {
                    $user['inactivity_remind'] = $new_state;
                    saveUser($user);
                    $reminders_sent++;
                }
            }
        }
        
        if ($reminders_sent > 0) {
            smartLog("Sent $reminders_sent inactivity reminders", 'INFO');
        }
        
        return $reminders_sent;
    } catch (Exception $e) {
        smartLog("Error sending inactivity reminders: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// --- توابع کمکی ---

// تابع کمکی برای اعتبارسنجی user_id
function isValidUserId($user_id) {
    return is_numeric($user_id) && $user_id > 0;
}

// تابع کمکی برای فرمت کردن تاریخ فارسی
function formatPersianDate($timestamp) {
    return date('Y/m/d H:i', $timestamp);
}

// تابع کمکی برای تمیز کردن متن
function cleanText($text) {
    return trim(strip_tags($text));
}

// تابع کمکی برای بررسی اینکه آیا کاربر ادمین است
function isAdmin($user_id) {
    return $user_id == ADMIN_ID;
}

// تابع کمکی برای دریافت تعداد کل کاربران
function getTotalUsersCount() {
    try {
        global $pdo;
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        smartLog("Error getting total users count: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// تابع کمکی برای دریافت آمار کلی سیستم
function getSystemStats() {
    try {
        global $pdo;
        
        $stats = [
            'total_users' => 0,
            'active_users_today' => 0,
            'total_sessions' => 0,
            'active_campaigns' => 0,
            'completed_exercises' => 0
        ];
        
        // کل کاربران
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = (int)$stmt->fetchColumn();
        
        // کاربران فعال امروز
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(last_activity) = CURDATE()");
        $stats['active_users_today'] = (int)$stmt->fetchColumn();
        
        // کل جلسات
        $stmt = $pdo->query("SELECT COUNT(*) FROM sessions");
        $stats['total_sessions'] = (int)$stmt->fetchColumn();
        
        // کمپین‌های فعال
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE campaign_started = 1");
        $stats['active_campaigns'] = (int)$stmt->fetchColumn();
        
        // تمرین‌های تکمیل شده
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE exercises LIKE '%accepted%'");
        $stats['completed_exercises'] = (int)$stmt->fetchColumn();
        
        return $stats;
    } catch (Exception $e) {
        smartLog("Error getting system stats: " . $e->getMessage(), 'ERROR');
        return [
            'total_users' => 0,
            'active_users_today' => 0,
            'total_sessions' => 0,
            'active_campaigns' => 0,
            'completed_exercises' => 0
        ];
    }
}

// تابع کمکی برای ارسال اطلاعیه به ادمین
function notifyAdmin($message, $priority = 'normal') {
    try {
        $priority_icon = $priority === 'high' ? '🚨' : ($priority === 'medium' ? '⚠️' : 'ℹ️');
        $full_message = "$priority_icon <b>اطلاعیه سیستم</b>\n\n$message\n\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        return sendMessage(ADMIN_ID, $full_message);
    } catch (Exception $e) {
        smartLog("Error notifying admin: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// تابع بهینه‌شده برای مدیریت error log
function rotateLogFile($max_size_mb = 10) {
    try {
        $log_file = __DIR__ . '/error.log';
        
        if (!file_exists($log_file)) {
            return false;
        }
        
        $file_size = filesize($log_file);
        $max_size = $max_size_mb * 1024 * 1024; // تبدیل به بایت
        
        if ($file_size < $max_size) {
            return false; // فایل هنوز کوچک است
        }
        
        // پشتیبان‌گیری و ایجاد فایل جدید
        $backup_name = $log_file . '.' . date('Y_m_d_H_i_s');
        
        if (rename($log_file, $backup_name)) {
            // ایجاد فایل لاگ جدید
            file_put_contents($log_file, "Log rotated on " . date('Y-m-d H:i:s') . " - Previous size: " . number_format($file_size / 1024 / 1024, 2) . " MB\n");
            chmod($log_file, 0644);
            
            // حذف فایل‌های پشتیبان قدیمی (نگه‌داری 5 فایل آخر)
            $backups = glob($log_file . '.*');
            if (count($backups) > 5) {
                usort($backups, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                $old_backups = array_slice($backups, 0, count($backups) - 5);
                foreach ($old_backups as $old_file) {
                    unlink($old_file);
                }
            }
            
            smartLog("Log file rotated. Previous size: " . number_format($file_size / 1024 / 1024, 2) . " MB", 'INFO');
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error rotating log file: " . $e->getMessage());
        return false;
    }
}

// اجرای تمیزکاری خودکار error log (1% احتمال در هر درخواست)
if (mt_rand(1, 1000) == 1) {
    rotateLogFile(10); // 10MB
}
?>