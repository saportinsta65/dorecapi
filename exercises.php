<?php
/**
 * سیستم تمرین‌های هوشمند و حرفه‌ای
 * نسخه کاملاً بهینه‌شده - 15 اکتبر 2025
 * رفع کامل مشکلات دیتای خالی و ذخیره دوبار
 */

// محافظت از دسترسی مستقیم
if (!defined('BOT_ACCESS')) {
    die('Access Denied');
}

// بارگذاری امن فایل‌های وابسته
if (file_exists('campaign.php') && !function_exists('startCampaign')) {
    require_once 'campaign.php';
}

/**
 * ✅ تابع کمکی برای پردازش ایمن JSON - بهبود یافته
 */
if (!function_exists('safeJsonDecode')) {
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
            error_log("JSON decode error for data: " . substr($data, 0, 100) . " - Error: " . json_last_error_msg());
        }
        
        return $default;
    }
}

/**
 * ✅ تابع debug logging هوشمند
 */
if (!function_exists('exerciseDebugLog')) {
    function exerciseDebugLog($message, $data = null) {
        $log_message = "[EXERCISE_PRO] $message";
        if ($data !== null) {
            $log_message .= " - Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        error_log($log_message);
    }
}

/**
 * ✅ ارسال تمرین جلسه - نسخه حرفه‌ای با کلید استاندارد integer
 */
function sendExercisePro($user_id, $session_title) {
    try {
        exerciseDebugLog("Sending exercise (PRO)", ['user_id' => $user_id, 'session' => $session_title]);
        
        if (!function_exists('loadSessions')) {
            error_log("❌ loadSessions function not found");
            return false;
        }
        
        $sessions = loadSessions();
        $session = null;
        
        foreach ($sessions as $sess) {
            if ($sess['title'] == $session_title) {
                $session = $sess;
                break;
            }
        }
        
        if (!$session || !isset($session['exercise']) || empty(trim($session['exercise']))) {
            exerciseDebugLog("No exercise found for session", ['session' => $session_title]);
            return false;
        }
        
        $user = getUserById($user_id);
        $name = $user['first_name'] ?? 'کاربر';
        $exercise_text = $session['exercise'];
        
        // پیام تمرین حرفه‌ای
        $message = "📝 <b>تمرین جلسه - $session_title</b>\n\n";
        $message .= "سلام $name عزیز! 👋\n\n";
        $message .= "🎯 <b>تمرین این جلسه:</b>\n\n";
        $message .= "$exercise_text\n\n";
        $message .= "💡 <b>نکات مهم:</b>\n";
        $message .= "▫️ پاسخ خود را کامل و دقیق بنویسید\n";
        $message .= "▫️ اگر سوالی دارید، از پشتیبانی بپرسید\n";
        $message .= "▫️ پس از تایید، جلسه بعدی فعال می‌شود\n\n";
        $message .= "⏰ <b>زمان بررسی:</b> معمولاً کمتر از 6 ساعت\n\n";
        $message .= "لطفاً پاسخ تمرین را به صورت پیام متنی ارسال کنید. 📤";
        
        if (!sendMessage($user_id, $message)) {
            error_log("❌ Failed to send exercise message");
            return false;
        }
        
        // ثبت وضعیت اولیه تمرین با کلید integer استاندارد
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        // ✅ نرمال‌سازی session_id به integer
        $session_id = intval($session['id']);
        
        // ✅ نرمال‌سازی exercises: تبدیل تمام کلیدهای string به integer
        $normalized_exercises = [];
        foreach ($exercises as $key => $ex) {
            $normalized_key = intval($key);
            $normalized_exercises[$normalized_key] = $ex;
        }
        
        // جلوگیری از ذخیره دوبار
        if (!isset($normalized_exercises[$session_id]) || $normalized_exercises[$session_id]['status'] !== 'pending') {
            $normalized_exercises[$session_id] = [
                'answer' => '',
                'status' => 'waiting_answer',
                'sent_at' => date('Y-m-d H:i:s'),
                'session_title' => $session_title,
                'session_id' => $session_id
            ];
            
            $user['exercises'] = json_encode($normalized_exercises, JSON_UNESCAPED_UNICODE);
            
            if (saveUser($user)) {
                exerciseDebugLog("Exercise ready status saved", [
                    'user_id' => $user_id,
                    'session_id' => $session_id,
                    'normalized_keys' => array_keys($normalized_exercises)
                ]);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error in sendExercisePro: " . $e->getMessage());
        exerciseDebugLog("Exception in sendExercisePro", [
            'user_id' => $user_id,
            'session' => $session_title,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✅ پردازش پاسخ تمرین - نسخه حرفه‌ای (بدون ذخیره دوبار، با کلید استاندارد integer)
 */
function handleExerciseAnswerPro($user_id, $session_title, $answer) {
    try {
        exerciseDebugLog("Handling exercise answer (PRO)", [
            'user_id' => $user_id, 
            'session' => $session_title, 
            'answer_length' => strlen($answer)
        ]);
        
        if (!function_exists('loadSessions') || !function_exists('getUserById') || !function_exists('saveUser')) {
            error_log("❌ Required functions not found");
            return false;
        }
        
        // پیدا کردن جلسه
        $sessions = loadSessions();
        $session = null;
        
        foreach ($sessions as $sess) {
            if ($sess['title'] == $session_title) {
                $session = $sess;
                break;
            }
        }
        
        if (!$session) {
            error_log("❌ Session not found: $session_title");
            return false;
        }
        
        // ✅ نرمال‌سازی session_id به integer برای استانداردسازی
        $session_id = intval($session['id']);
        $user = getUserById($user_id);
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        exerciseDebugLog("Exercise data before update", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'exercises_keys' => array_keys($exercises)
        ]);
        
        // ✅ جلوگیری از ذخیره دوبار
        if (isset($exercises[$session_id]) && 
            $exercises[$session_id]['status'] === 'pending' &&
            !empty(trim($exercises[$session_id]['answer'] ?? ''))) {
            
            exerciseDebugLog("Exercise already submitted", ['user_id' => $user_id, 'session_id' => $session_id]);
            
            sendMessage($user_id, "⚠️ <b>تمرین قبلاً ارسال شده</b>\n\nتمرین شما برای این جلسه قبلاً ثبت شده و در انتظار بررسی است.\n\n⏰ لطفاً منتظر نتیجه بررسی باشید.");
            return false;
        }
        
        // ✅ نرمال‌سازی exercises: تبدیل تمام کلیدهای string به integer
        $normalized_exercises = [];
        foreach ($exercises as $key => $ex) {
            $normalized_key = intval($key);
            $normalized_exercises[$normalized_key] = $ex;
        }
        
        // ذخیره پاسخ جدید با کلید integer استاندارد
        $normalized_exercises[$session_id] = [
            'answer' => trim($answer),
            'status' => 'pending',
            'submitted_at' => date('Y-m-d H:i:s'),
            'session_title' => $session_title,
            'session_id' => $session_id
        ];
        
        $user['exercises'] = json_encode($normalized_exercises, JSON_UNESCAPED_UNICODE);
        
        if (!saveUser($user)) {
            error_log("❌ Failed to save exercise answer");
            return false;
        }
        
        exerciseDebugLog("Exercise answer saved successfully", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'normalized_keys' => array_keys($normalized_exercises)
        ]);
        
        // اطلاع به کاربر - پیام حرفه‌ای
        $user_name = $user['first_name'] ?? 'کاربر';
        $success_msg = "✅ <b>تمرین با موفقیت ثبت شد!</b>\n\n";
        $success_msg .= "سلام $user_name عزیز! 👋\n\n";
        $success_msg .= "تمرین شما برای جلسه <b>$session_title</b> دریافت و در صف بررسی قرار گرفت.\n\n";
        $success_msg .= "📊 <b>وضعیت:</b> در انتظار بررسی\n";
        $success_msg .= "⏰ <b>زمان بررسی:</b> معمولاً کمتر از 6 ساعت\n";
        $success_msg .= "🔔 <b>اطلاع‌رسانی:</b> بلافاصله پس از بررسی اطلاع خواهید گرفت\n\n";
        $success_msg .= "💪 ادامه دهید! موفقیت در انتظار شماست!";
        
        sendMessage($user_id, $success_msg);
        
        // ✅ اطلاع به ادمین - فقط یک پیام واحد
        if (defined('ADMIN_ID')) {
            sendExerciseNotificationToAdmin($user_id, $session_title, $answer, $session_id);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error in handleExerciseAnswerPro: " . $e->getMessage());
        exerciseDebugLog("Exception in handleExerciseAnswerPro", [
            'user_id' => $user_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

/**
 * ✅ ارسال اطلاعیه تمرین به ادمین - تک پیام
 */
function sendExerciseNotificationToAdmin($user_id, $session_title, $answer, $session_id) {
    try {
        $user = getUserById($user_id);
        $user_name = $user['first_name'] ?? 'کاربر';
        
        // محدود کردن طول پاسخ برای نمایش
        $short_answer = mb_strlen($answer) > 300 ? mb_substr($answer, 0, 300) . '...' : $answer;
        
        $admin_msg = "📝 <b>تمرین جدید دریافت شد</b>\n\n";
        $admin_msg .= "👤 <b>کاربر:</b> $user_name (#$user_id)\n";
        $admin_msg .= "📚 <b>جلسه:</b> $session_title\n";
        $admin_msg .= "⏰ <b>زمان:</b> " . date('H:i') . "\n\n";
        $admin_msg .= "💬 <b>پاسخ تمرین:</b>\n";
        $admin_msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $admin_msg .= "<code>$short_answer</code>\n";
        $admin_msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $admin_msg .= "🔹 برای بررسی از دکمه‌های زیر استفاده کنید:";
        
        // کیبورد inline
        $keyboard = [
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
        
        // ارسال با API مستقیم
        if (defined('API_URL')) {
            $url = API_URL . "sendMessage";
            $data = [
                'chat_id' => ADMIN_ID,
                'text' => $admin_msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
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
                error_log("❌ Failed to send admin notification - HTTP: $http_code");
            } else {
                exerciseDebugLog("Admin notification sent successfully");
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ Error in sendExerciseNotificationToAdmin: " . $e->getMessage());
    }
}

/**
 * ✅ پردازش callback های تمرین - نسخه بهینه‌شده
 */
function handleExerciseCallbackPro($data) {
    try {
        exerciseDebugLog("Processing exercise callback (PRO)", ['data' => $data]);
        
        if (!function_exists('getUserById') || !function_exists('saveUser') || !function_exists('sendMessage')) {
            error_log("❌ Required functions not found");
            return false;
        }
        
        // ✅ مشاهده کامل تمرین
        if (preg_match('/^exercise_view_([0-9]+)_([0-9]+)$/', $data, $matches)) {
            $user_id = intval($matches[1]);
            $session_id = intval($matches[2]);
            
            return viewExerciseDetails($user_id, $session_id);
        }
        
        // ✅ تایید تمرین
        if (preg_match('/^exercise_accept_([0-9]+)_([0-9]+)$/', $data, $matches)) {
            $user_id = intval($matches[1]);
            $session_id = intval($matches[2]);
            
            return acceptExercise($user_id, $session_id);
        }
        
        // ✅ رد تمرین
        if (preg_match('/^exercise_reject_([0-9]+)_([0-9]+)$/', $data, $matches)) {
            $user_id = intval($matches[1]);
            $session_id = intval($matches[2]);
            
            return rejectExercise($user_id, $session_id);
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("❌ Error in handleExerciseCallbackPro: " . $e->getMessage());
        return false;
    }
}

/**
 * ✅ مشاهده جزئیات کامل تمرین
 */
function viewExerciseDetails($user_id, $session_id) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ کاربر یافت نشد: $user_id");
            }
            return false;
        }
        
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        // پیدا کردن تمرین
        $exercise = $exercises[$session_id] ?? $exercises[strval($session_id)] ?? null;
        
        if (!$exercise) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ تمرین یافت نشد برای کاربر $user_id، جلسه $session_id");
            }
            return false;
        }
        
        // پیدا کردن نام جلسه
        $session_title = $exercise['session_title'] ?? '';
        if (empty($session_title) && function_exists('loadSessions')) {
            $sessions = loadSessions();
            foreach ($sessions as $sess) {
                if (intval($sess['id']) == intval($session_id)) {
                    $session_title = $sess['title'];
                    break;
                }
            }
        }
        
        if (empty($session_title)) {
            $session_title = "جلسه شماره $session_id";
        }
        
        // محاسبه زمان گذشته
        $submitted_at = $exercise['submitted_at'] ?? '';
        $time_ago = '';
        if ($submitted_at) {
            $diff = time() - strtotime($submitted_at);
            if ($diff < 3600) {
                $time_ago = floor($diff / 60) . ' دقیقه پیش';
            } elseif ($diff < 86400) {
                $time_ago = floor($diff / 3600) . ' ساعت پیش';
            } else {
                $time_ago = floor($diff / 86400) . ' روز پیش';
            }
        }
        
        // آمار کاربر
        $user_stats = '';
        if (function_exists('loadSessions')) {
            $seen_sessions = safeJsonDecode($user['seen_sessions'] ?? null, []);
            $total_sessions = count(loadSessions());
            $seen_count = is_array($seen_sessions) ? count($seen_sessions) : 0;
            $progress = $total_sessions > 0 ? round(($seen_count / $total_sessions) * 100) : 0;
            
            $user_stats = "📈 <b>پیشرفت کاربر:</b>\n";
            $user_stats .= "🎓 جلسات دیده: $seen_count/$total_sessions ($progress%)\n";
            
            // تعداد تمرین‌های تایید شده
            $accepted_count = 0;
            foreach ($exercises as $ex) {
                if (($ex['status'] ?? '') === 'accepted') {
                    $accepted_count++;
                }
            }
            $user_stats .= "✅ تمرین‌های تایید شده: $accepted_count\n\n";
        }
        
        $detailed_msg = "🔍 <b>جزئیات کامل تمرین</b>\n\n";
        $detailed_msg .= "👤 <b>کاربر:</b> {$user['first_name']} (#{$user_id})\n";
        $detailed_msg .= "📚 <b>جلسه:</b> $session_title\n";
        $detailed_msg .= "🆔 <b>Session ID:</b> $session_id\n";
        $detailed_msg .= "📅 <b>زمان ارسال:</b> $time_ago\n";
        $detailed_msg .= "📊 <b>وضعیت:</b> منتظر بررسی\n\n";
        $detailed_msg .= $user_stats;
        $detailed_msg .= "💬 <b>متن کامل تمرین:</b>\n";
        $detailed_msg .= "════════════════════════════════════\n";
        $detailed_msg .= ($exercise['answer'] ?? 'پاسخ خالی');
        $detailed_msg .= "\n════════════════════════════════════\n\n";
        $detailed_msg .= "🕒 زمان مشاهده: " . date('H:i:s');
        
        if (defined('ADMIN_ID')) {
            sendMessage(ADMIN_ID, $detailed_msg);
            
            // دکمه‌های عملیات
            $keyboard = [
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
                $data = [
                    'chat_id' => ADMIN_ID,
                    'text' => "🎯 <b>حالا می‌توانید تمرین را تایید یا رد کنید:</b>",
                    'reply_markup' => json_encode($keyboard)
                ];
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($ch);
                curl_close($ch);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error in viewExerciseDetails: " . $e->getMessage());
        return false;
    }
}

/**
 * ✅ تایید تمرین - با پشتیبانی از کلیدهای integer و string
 */
function acceptExercise($user_id, $session_id) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ کاربر یافت نشد: $user_id");
            }
            return false;
        }
        
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        // ✅ نرمال‌سازی session_id
        $normalized_session_id = intval($session_id);
        
        // پیدا کردن تمرین با پشتیبانی از هر دو نوع کلید
        $exercise_key = null;
        $exercise = null;
        
        if (isset($exercises[$normalized_session_id])) {
            $exercise_key = $normalized_session_id;
            $exercise = $exercises[$normalized_session_id];
        } elseif (isset($exercises[strval($normalized_session_id)])) {
            $exercise_key = strval($normalized_session_id);
            $exercise = $exercises[$exercise_key];
        } else {
            // جستجوی دستی در تمام کلیدها
            foreach ($exercises as $key => $ex) {
                if (intval($key) === $normalized_session_id) {
                    $exercise_key = $key;
                    $exercise = $ex;
                    break;
                }
            }
        }
        
        if ($exercise_key === null) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ تمرین یافت نشد: کاربر $user_id، جلسه $session_id");
                exerciseDebugLog("Exercise not found for accept", [
                    'user_id' => $user_id,
                    'session_id' => $normalized_session_id,
                    'available_keys' => array_keys($exercises)
                ]);
            }
            return false;
        }
        
        // ✅ نرمال‌سازی exercises: تبدیل تمام کلیدها به integer
        $normalized_exercises = [];
        foreach ($exercises as $key => $ex) {
            $norm_key = intval($key);
            $normalized_exercises[$norm_key] = $ex;
        }
        
        // تایید تمرین
        $normalized_exercises[$normalized_session_id]['status'] = 'accepted';
        $normalized_exercises[$normalized_session_id]['approved_at'] = date('Y-m-d H:i:s');
        $user['exercises'] = json_encode($normalized_exercises, JSON_UNESCAPED_UNICODE);
        
        if (!saveUser($user)) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ خطا در ذخیره تایید تمرین");
            }
            return false;
        }
        
        $session_title = $exercise['session_title'] ?? "جلسه شماره $normalized_session_id";
        
        // اطلاع به کاربر - پیام انگیزشی
        $user_name = $user['first_name'] ?? 'کاربر';
        $user_msg = "🎉 <b>تبریک $user_name عزیز!</b>\n\n";
        $user_msg .= "✅ تمرین شما برای جلسه <b>$session_title</b> با موفقیت تایید شد!\n\n";
        $user_msg .= "🚀 <b>خبر خوب:</b> جلسه بعدی برای شما فعال شد!\n";
        $user_msg .= "💪 ادامه دهید! شما در مسیر موفقیت قدم برمی‌دارید!\n\n";
        $user_msg .= "⭐ امتیاز شما: +10 امتیاز";
        
        sendMessage($user_id, $user_msg);
        
        // اطلاع به ادمین
        if (defined('ADMIN_ID')) {
            sendMessage(ADMIN_ID, "✅ تمرین کاربر <b>$user_name</b> (#$user_id) برای جلسه <b>$session_title</b> تایید شد.");
        }
        
        // بررسی تکمیل دوره
        if (function_exists('isLastSession') && isLastSession($normalized_session_id)) {
            if (function_exists('isUserEligibleForCampaign') && isUserEligibleForCampaign($user_id)) {
                if (function_exists('startCampaign')) {
                    startCampaign($user_id);
                    if (defined('ADMIN_ID')) {
                        sendMessage(ADMIN_ID, "🎯 کاربر <b>$user_name</b> دوره را تکمیل کرد و کمپین شروع شد!");
                    }
                }
            }
        }
        
        exerciseDebugLog("Exercise accepted successfully", [
            'user_id' => $user_id,
            'session_id' => $normalized_session_id,
            'normalized_keys' => array_keys($normalized_exercises)
        ]);
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error in acceptExercise: " . $e->getMessage());
        exerciseDebugLog("Exception in acceptExercise", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✅ رد تمرین - با پشتیبانی از کلیدهای integer و string
 */
function rejectExercise($user_id, $session_id) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ کاربر یافت نشد: $user_id");
            }
            return false;
        }
        
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        // ✅ نرمال‌سازی session_id
        $normalized_session_id = intval($session_id);
        
        // پیدا کردن تمرین با پشتیبانی از هر دو نوع کلید
        $exercise_key = null;
        $exercise = null;
        
        if (isset($exercises[$normalized_session_id])) {
            $exercise_key = $normalized_session_id;
            $exercise = $exercises[$normalized_session_id];
        } elseif (isset($exercises[strval($normalized_session_id)])) {
            $exercise_key = strval($normalized_session_id);
            $exercise = $exercises[$exercise_key];
        } else {
            // جستجوی دستی در تمام کلیدها
            foreach ($exercises as $key => $ex) {
                if (intval($key) === $normalized_session_id) {
                    $exercise_key = $key;
                    $exercise = $ex;
                    break;
                }
            }
        }
        
        if ($exercise_key === null) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ تمرین یافت نشد: کاربر $user_id، جلسه $session_id");
                exerciseDebugLog("Exercise not found for reject", [
                    'user_id' => $user_id,
                    'session_id' => $normalized_session_id,
                    'available_keys' => array_keys($exercises)
                ]);
            }
            return false;
        }
        
        // ✅ نرمال‌سازی exercises: تبدیل تمام کلیدها به integer
        $normalized_exercises = [];
        foreach ($exercises as $key => $ex) {
            $norm_key = intval($key);
            $normalized_exercises[$norm_key] = $ex;
        }
        
        // رد تمرین
        $normalized_exercises[$normalized_session_id]['status'] = 'rejected';
        $normalized_exercises[$normalized_session_id]['rejected_at'] = date('Y-m-d H:i:s');
        $user['exercises'] = json_encode($normalized_exercises, JSON_UNESCAPED_UNICODE);
        
        if (!saveUser($user)) {
            if (defined('ADMIN_ID')) {
                sendMessage(ADMIN_ID, "❌ خطا در ذخیره رد تمرین");
            }
            return false;
        }
        
        $session_title = $exercise['session_title'] ?? "جلسه شماره $normalized_session_id";
        $user_name = $user['first_name'] ?? 'کاربر';
        
        // اطلاع به کاربر - پیام سازنده
        $user_msg = "🔄 <b>تمرین نیاز به بازبینی دارد</b>\n\n";
        $user_msg .= "سلام $user_name عزیز! 👋\n\n";
        $user_msg .= "تمرین شما برای جلسه <b>$session_title</b> نیاز به بهبود دارد.\n\n";
        $user_msg .= "💡 <b>توصیه‌ها:</b>\n";
        $user_msg .= "▫️ آموزش را مجدد مطالعه کنید\n";
        $user_msg .= "▫️ پاسخ کامل‌تر و دقیق‌تری ارسال کنید\n";
        $user_msg .= "▫️ در صورت سوال از پشتیبانی کمک بگیرید\n\n";
        $user_msg .= "💪 نگران نباشید! هر تریدر موفقی از این مرحله گذشته است.\n";
        $user_msg .= "🔄 تمرین جدید خود را ارسال کنید.";
        
        sendMessage($user_id, $user_msg);
        
        // اطلاع به ادمین
        if (defined('ADMIN_ID')) {
            sendMessage(ADMIN_ID, "❌ تمرین کاربر <b>$user_name</b> (#$user_id) برای جلسه <b>$session_title</b> رد شد.");
        }
        
        exerciseDebugLog("Exercise rejected successfully", [
            'user_id' => $user_id,
            'session_id' => $normalized_session_id,
            'normalized_keys' => array_keys($normalized_exercises)
        ]);
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error in rejectExercise: " . $e->getMessage());
        exerciseDebugLog("Exception in rejectExercise", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✅ دریافت تمرین‌های منتظر بررسی - بدون دیتای خالی
 */
function getPendingExercisesPro() {
    try {
        exerciseDebugLog("Getting pending exercises (PRO) - START");
        
        if (!function_exists('loadUsers') || !function_exists('loadSessions')) {
            exerciseDebugLog("Required functions not found");
            return [];
        }
        
        $users = loadUsers();
        $sessions = loadSessions();
        $pending = [];
        
        if (empty($users) || empty($sessions)) {
            exerciseDebugLog("No users or sessions found");
            return [];
        }
        
        foreach ($users as $user) {
            $exercises = safeJsonDecode($user['exercises'] ?? null, []);
            
            if (!is_array($exercises) || empty($exercises)) {
                continue;
            }
            
            foreach ($exercises as $session_id => $exercise) {
                // ✅ بررسی دقیق داده‌های معتبر
                if (!isset($exercise['status']) || 
                    !isset($exercise['answer']) || 
                    empty(trim($exercise['answer'])) ||
                    $exercise['status'] !== 'pending') {
                    continue;
                }
                
                // پیدا کردن نام جلسه
                $session_title = $exercise['session_title'] ?? '';
                if (empty($session_title)) {
                    foreach ($sessions as $sess) {
                        if (intval($sess['id']) == intval($session_id)) {
                            $session_title = $sess['title'];
                            break;
                        }
                    }
                }
                
                if (empty($session_title)) {
                    $session_title = "جلسه شماره $session_id";
                }
                
                $pending[] = [
                    'user_id' => $user['id'],
                    'user_name' => $user['first_name'] ?? 'نامشخص',
                    'session_id' => intval($session_id),
                    'session_title' => $session_title,
                    'answer' => trim($exercise['answer']),
                    'submitted_at' => $exercise['submitted_at'] ?? date('Y-m-d H:i:s')
                ];
            }
        }
        
        // مرتب‌سازی بر اساس زمان (جدیدترین اول)
        usort($pending, function($a, $b) {
            return strtotime($b['submitted_at']) - strtotime($a['submitted_at']);
        });
        
        exerciseDebugLog("Pending exercises result", ['count' => count($pending)]);
        return $pending;
        
    } catch (Exception $e) {
        error_log("❌ Error in getPendingExercisesPro: " . $e->getMessage());
        return [];
    }
}

// ✅ توابع سازگاری با نسخه قدیم
function sendExercise($user_id, $session_title) {
    return sendExercisePro($user_id, $session_title);
}

function handleExerciseAnswer($user_id, $session_title, $answer) {
    return handleExerciseAnswerPro($user_id, $session_title, $answer);
}

function handleExerciseCallback($data) {
    return handleExerciseCallbackPro($data);
}

function getPendingExercises() {
    return getPendingExercisesPro();
}

// سایر توابع بدون تغییر...
function canSeeNextSession($user_id, $session_title) {
    try {
        if (!function_exists('loadSessions') || !function_exists('getUserById')) {
            return true;
        }
        
        $sessions = loadSessions();
        $current_session = null;
        
        foreach ($sessions as $sess) {
            if ($sess['title'] == $session_title) {
                $current_session = $sess;
                break;
            }
        }
        
        if (!$current_session) {
            return true;
        }
        
        $current_id = intval($current_session['id']);
        
        if ($current_id == 1) {
            return true;
        }
        
        $previous_session = null;
        foreach ($sessions as $sess) {
            if (intval($sess['id']) == ($current_id - 1)) {
                $previous_session = $sess;
                break;
            }
        }
        
        if (!$previous_session) {
            return true;
        }
        
        $user = getUserById($user_id);
        $exercises = safeJsonDecode($user['exercises'] ?? null, []);
        
        $previous_session_id = intval($previous_session['id']);
        return isset($exercises[$previous_session_id]) && 
               $exercises[$previous_session_id]['status'] == 'accepted';
        
    } catch (Exception $e) {
        error_log("Error checking session access: " . $e->getMessage());
        return true;
    }
}

function isLastSession($session_id) {
    try {
        if (!function_exists('loadSessions')) {
            return false;
        }
        
        $sessions = loadSessions();
        if (empty($sessions)) {
            return false;
        }
        
        usort($sessions, function($a, $b) {
            return intval($a['id']) - intval($b['id']);
        });
        
        $last_session = end($sessions);
        return intval($last_session['id']) == intval($session_id);
        
    } catch (Exception $e) {
        error_log("Error checking if session is last: " . $e->getMessage());
        return false;
    }
}

function getExerciseStats() {
    try {
        if (!function_exists('loadUsers') || !function_exists('loadSessions')) {
            return false;
        }
        
        $users = loadUsers();
        $sessions = loadSessions();
        
        $stats = [
            'total_users' => count($users),
            'total_exercises' => 0,
            'pending_exercises' => 0,
            'accepted_exercises' => 0,
            'rejected_exercises' => 0,
            'completed_users' => 0
        ];
        
        foreach ($users as $user) {
            $exercises = safeJsonDecode($user['exercises'] ?? null, []);
            $completed_count = 0;
            
            foreach ($exercises as $session_id => $exercise) {
                $stats['total_exercises']++;
                
                switch ($exercise['status'] ?? 'unknown') {
                    case 'pending':
                        $stats['pending_exercises']++;
                        break;
                    case 'accepted':
                        $stats['accepted_exercises']++;
                        $completed_count++;
                        break;
                    case 'rejected':
                        $stats['rejected_exercises']++;
                        break;
                }
            }
            
            if ($completed_count == count($sessions)) {
                $stats['completed_users']++;
            }
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting exercise stats: " . $e->getMessage());
        return false;
    }
}

// ✅ توابع سازگاری اضافی
if (!function_exists('handleExerciseCallbackEnhanced')) {
    function handleExerciseCallbackEnhanced($data) {
        return handleExerciseCallbackPro($data);
    }
}

if (!function_exists('handleExerciseViewCallback')) {
    function handleExerciseViewCallback($data) {
        return handleExerciseCallbackPro($data);
    }
}
?>