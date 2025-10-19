<?php
/**
 * مدیریت کاربران عادی ربات - نسخه نهایی بهبود یافته
 * نسخه کامل با تمام بهبودها - 15 اکتبر 2025 
 * با رفع مشکلات سیستم تمرین‌ها و UX حرفه‌ای
 */

// محافظت از دسترسی مستقیم
if (!defined('BOT_ACCESS')) {
    die('Access Denied');
}

require_once 'functions.php';
require_once 'referral.php';
require_once 'exercises.php';
require_once 'campaign.php';

// دکمه‌ها و متون سفارشی
$btn_captain = loadBtnCaptain();
$btn_advanced = loadBtnAdvanced();

/**
 * ✅ تابع کمکی برای پردازش ایمن JSON - بهبود یافته
 */
function safeDecodeUserData($data, $default = []) {
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

/**
 * ✅ Debug logging برای user
 */
function userDebugLog($message, $data = null) {
    $log_message = "[USER_DEBUG] $message";
    if ($data !== null) {
        $log_message .= " - Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    error_log($log_message);
}

/**
 * ✅ تولید کیبورد اصلی بر اساس وضعیت کاربر - بهبود یافته
 */
function getMainKeyboard($user_id) {
    global $btn_advanced, $btn_captain;
    
    try {
        $refCount = getReferralCount($user_id);
        $user = getUserById($user_id);
        $user_type = $user['type'] ?? 'user';
        
        $mainKeyboard = [
            ["🎓 ثبت‌نام دوره رایگان"],
            [$btn_advanced['btn']],
            ["📊 آمار دعوت‌ها"],
            [$btn_captain['btn']],
            ["💬 پشتیبانی آنلاین"]
        ];
        
        // دکمه ویژه برای کاربران ۲۰+ دعوت
        if ($refCount >= MIN_REFERRALS_FOR_ADVANCED_DISCOUNT) {
            $mainKeyboard[] = ["🚀 ثبت‌نام ویژه پیشرفته با تخفیف"];
        }
        
        // دکمه ویژه برای کاربران free که دوره را تکمیل کرده‌اند
        if ($user_type === 'free' && function_exists('isUserEligibleForCampaign')) {
            if (isUserEligibleForCampaign($user_id)) {
                $mainKeyboard[] = ["🎉 دریافت کد تخفیف ویژه"];
            }
        }
        
        return $mainKeyboard;
    } catch (Exception $e) {
        userDebugLog("Error generating main keyboard for user $user_id", ['error' => $e->getMessage()]);
        return [
            ["🎓 ثبت‌نام دوره رایگان"],
            [$btn_advanced['btn']],
            [$btn_captain['btn']],
            ["💬 پشتیبانی آنلاین"]
        ];
    }
}

/**
 * ✅ پیام خوش‌آمدگویی - بهبود یافته
 */
function welcomeMessage($user_id) {
    try {
        $user = getUserById($user_id);
        $name = $user['first_name'] ?? 'کاربر گرامی';
        $total_users = count(loadUsers());
        $stats = getReferralStats($user_id);
        
        $message = "👋 سلام <b>$name</b> عزیز!\n\n"
            . "به ربات آموزشی <b>کاپیتان تریدر</b> خوش اومدی! 🚀\n\n"
            . "تا این لحظه <b>$total_users نفر</b> به ربات پیوسته‌اند!\n\n"
            . "در این ربات، قراره با هم یک دوره رایگان و حرفه‌ای رو قدم به قدم پیش بریم و به دنیای معامله‌گری حرفه‌ای وارد بشیم!\n\n"
            . "✅ <b>مزایای ربات:</b>\n"
            . "✔️ آموزش صفر تا صد فارکس و پراپ‌فرم\n"
            . "✔️ استراتژی شخصی کاپیتان برای موفقیت\n"
            . "✔️ بکتست‌گیری، ژورنال‌نویسی و پلن معاملاتی حرفه‌ای\n"
            . "✔️ پشتیبانی آنلاین و پاسخ به سوالات شما\n\n";
        
        // اضافه کردن آمار شخصی
        if ($stats['total'] > 0) {
            $message .= "📈 <b>آمار شما:</b>\n";
            $message .= "👥 تعداد دعوت‌ها: <b>{$stats['total']}</b>\n";
            
            if ($stats['can_access_free']) {
                $message .= "✅ دسترسی به دوره رایگان: <b>فعال</b>\n";
            } else {
                $message .= "⏳ برای دوره رایگان: <b>{$stats['needed_for_free']} دعوت باقی‌مانده</b>\n";
            }
            
            if ($stats['can_access_discount']) {
                $message .= "🎯 تخفیف دوره پیشرفته: <b>فعال</b>\n";
            }
            
            $message .= "\n";
        }
        
        // نمایش پیشرفت دوره برای کاربران free
        $user_type = $user['type'] ?? 'user';
        if ($user_type === 'free' && function_exists('getUserProgress')) {
            $progress = getUserProgress($user_id);
            if ($progress['total_sessions'] > 0) {
                $percentage = round(($progress['seen_sessions'] / $progress['total_sessions']) * 100);
                $message .= "📚 <b>پیشرفت دوره:</b> {$progress['seen_sessions']}/{$progress['total_sessions']} ($percentage%)\n\n";
            }
        }
        
        $message .= "برای شروع، روی دکمه‌ها کلیک کن.";
        
        return $message;
    } catch (Exception $e) {
        userDebugLog("Error generating welcome message for user $user_id", ['error' => $e->getMessage()]);
        return "👋 خوش آمدید! برای شروع، روی دکمه‌ها کلیک کنید.";
    }
}

/**
 * ✅ ارسال درخواست شماره تماس - بهبود یافته
 */
function sendContactBtn($chat_id, $user_id) {
    $btn = [
        [
            [
                "text" => "📱 ارسال شماره موبایل",
                "request_contact" => true
            ]
        ]
    ];
    
    $welcome_text = welcomeMessage($user_id) . "\n\n" 
        . "🔐 <b>احراز هویت ضروری:</b>\n"
        . "برای امنیت بیشتر و دریافت اطلاعیه‌های مهم، لطفاً شماره موبایل خود را ارسال کنید.";
    
    sendMessage($chat_id, $welcome_text, $btn);
}

/**
 * ✅ ارسال درخواست عضویت در کانال‌ها - بهبود یافته
 */
function sendJoinChannels($chat_id) {
    $btn = [
        [
            [
                "text" => "📢 عضویت در کانال اول",
                "url" => "https://t.me/" . str_replace('@', '', CHANNEL1)
            ],
            [
                "text" => "📢 عضویت در کانال دوم", 
                "url" => "https://t.me/" . str_replace('@', '', CHANNEL2)
            ]
        ],
        [
            [
                "text" => "✅ عضو شدم"
            ]
        ]
    ];
    
    $message = "🎯 <b>مرحله آخر ثبت‌نام:</b>\n\n"
        . "برای دریافت اطلاعیه‌های مهم و آموزش‌های رایگان، لطفاً عضو هر دو کانال زیر شوید:\n\n"
        . "🔗 کانال اول: " . CHANNEL1 . "\n"
        . "🔗 کانال دوم: " . CHANNEL2 . "\n\n"
        . "پس از عضویت، دکمه 'عضو شدم' را بزنید.";
        
    sendMessage($chat_id, $message, $btn);
}

/**
 * ✅ نمایش آمار دعوت‌ها - بهبود یافته
 */
function showReferralStats($chat_id, $user_id) {
    try {
        $stats = getReferralStats($user_id);
        $rank = getUserReferralRank($user_id);
        
        $message = "📊 <b>آمار دعوت‌های شما</b>\n\n";
        
        // آمار کلی
        $message .= "👥 <b>تعداد کل دعوت‌ها:</b> {$stats['total']}\n";
        $message .= "📅 امروز: {$stats['today']} | این هفته: {$stats['this_week']} | این ماه: {$stats['this_month']}\n\n";
        
        // رتبه
        $message .= "🏆 <b>رتبه شما:</b> {$rank['rank']} از {$rank['total_referrers']}\n\n";
        
        // نوار پیشرفت بصری
        $free_progress = min(100, ($stats['total'] / max(1, MIN_REFERRALS_FOR_FREE_COURSE)) * 100);
        $discount_progress = min(100, ($stats['total'] / max(1, MIN_REFERRALS_FOR_ADVANCED_DISCOUNT)) * 100);
        
        $message .= "📈 <b>پیشرفت اهداف:</b>\n";
        $message .= "🎓 دوره رایگان: " . generateProgressBar($free_progress) . " " . round($free_progress) . "%\n";
        $message .= "🚀 تخفیف پیشرفته: " . generateProgressBar($discount_progress) . " " . round($discount_progress) . "%\n\n";
        
        // وضعیت دسترسی‌ها
        $message .= "🎯 <b>وضعیت دسترسی‌ها:</b>\n";
        
        if ($stats['can_access_free']) {
            $message .= "✅ دوره رایگان: <b>فعال</b>\n";
        } else {
            $message .= "⏳ دوره رایگان: <b>{$stats['needed_for_free']} دعوت باقی‌مانده</b>\n";
        }
        
        if ($stats['can_access_discount']) {
            $message .= "✅ تخفیف دوره پیشرفته: <b>فعال</b>\n";
        } else {
            $message .= "⏳ تخفیف دوره پیشرفته: <b>{$stats['needed_for_discount']} دعوت باقی‌مانده</b>\n";
        }
        
        $message .= "\n🔗 <b>لینک دعوت شما:</b>\n" . getReferralLink($user_id);
        
        // نمایش آخرین دعوت‌ها
        if (!empty($stats['referrals'])) {
            $message .= "\n\n👥 <b>آخرین دعوت‌ها:</b>\n";
            $recent = array_slice($stats['referrals'], 0, 5);
            foreach ($recent as $ref) {
                $date = date('m/d H:i', strtotime($ref['date']));
                $message .= "▪️ {$ref['name']} - $date\n";
            }
            
            if (count($stats['referrals']) > 5) {
                $remaining = count($stats['referrals']) - 5;
                $message .= "... و $remaining نفر دیگر\n";
            }
        }
        
        $keyboard = [
            ["🎁 دریافت بنر تبلیغاتی"],
            ["🏆 لیست برترین معرف‌ها"],
            ["بازگشت"]
        ];
        
        sendMessage($chat_id, $message, $keyboard);
    } catch (Exception $e) {
        userDebugLog("Error showing referral stats for user $user_id", ['error' => $e->getMessage()]);
        sendMessage($chat_id, "❌ خطا در دریافت آمار. لطفاً دوباره تلاش کنید.", [["بازگشت"]]);
    }
}

/**
 * ✅ تولید نوار پیشرفت بصری
 */
function generateProgressBar($percentage, $length = 10) {
    $filled = round(($percentage / 100) * $length);
    $empty = $length - $filled;
    return str_repeat('🟩', $filled) . str_repeat('⬜', $empty);
}

/**
 * ✅ نمایش لیست برترین معرف‌ها
 */
function showTopReferrers($chat_id, $user_id) {
    try {
        if (!function_exists('getTopReferrers')) {
            sendMessage($chat_id, "❌ این قابلیت در دسترس نیست.", [["بازگشت"]]);
            return;
        }
        
        $top_referrers = getTopReferrers(10);
        $user_rank = getUserReferralRank($user_id);
        
        $message = "🏆 <b>لیست برترین معرف‌ها</b>\n\n";
        
        if (empty($top_referrers)) {
            $message .= "هنوز هیچ معرفی ثبت نشده است.";
        } else {
            foreach ($top_referrers as $index => $referrer) {
                $rank = $index + 1;
                $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank - 1] : "$rank.";
                $name = $referrer['name'] ?: 'نامشخص';
                $count = $referrer['referral_count'];
                
                $is_current_user = ($referrer['user_id'] == $user_id) ? ' <b>(شما)</b>' : '';
                $message .= "$medal $name - $count دعوت$is_current_user\n";
            }
            
            $message .= "\n📊 <b>رتبه شما:</b> {$user_rank['rank']} از {$user_rank['total_referrers']}\n";
            $message .= "👥 <b>دعوت‌های شما:</b> {$user_rank['user_referrals']}";
        }
        
        sendMessage($chat_id, $message, [["بازگشت"]]);
        
    } catch (Exception $e) {
        userDebugLog("Error showing top referrers", ['error' => $e->getMessage()]);
        sendMessage($chat_id, "❌ خطا در دریافت لیست. لطفاً دوباره تلاش کنید.", [["بازگشت"]]);
    }
}

/**
 * ✅ ارسال بنر تبلیغاتی - بهبود یافته
 */
function sendInviteBannerToUser($chat_id, $user_id) {
    try {
        $banner = getInviteBanner($user_id);
        
        // ارسال عکس
        if (!empty($banner['photo'])) {
            sendFile($chat_id, 'photo', $banner['photo'], '🎁 بنر تبلیغاتی شما');
        }
        
        // ارسال متن
        sendMessage($chat_id, $banner['text'], [["📊 آمار دعوت‌ها"], ["بازگشت"]]);
        
        // راهنمای استفاده بهبود یافته
        $guide = "📝 <b>راهنمای استفاده از بنر:</b>\n\n"
            . "🎯 <b>بهترین مکان‌های اشتراک:</b>\n"
            . "▪️ گروه‌های تلگرام مرتبط با معاملات\n"
            . "▪️ استوری و پست اینستاگرام\n" 
            . "▪️ وضعیت واتساپ\n"
            . "▪️ ارسال مستقیم به دوستان علاقه‌مند\n\n"
            . "💡 <b>نکات مهم:</b>\n"
            . "✅ هر کلیک روی لینک، آمار شما را افزایش می‌دهد\n"
            . "✅ با ۵ دعوت موفق، دوره رایگان فعال می‌شود\n"
            . "✅ با ۲۰ دعوت، تخفیف ویژه دوره پیشرفته فعال می‌شود\n"
            . "✅ پیگیری مداوم آمار از بخش 'آمار دعوت‌ها'";
        
        sendMessage($chat_id, $guide, [["📊 آمار دعوت‌ها"], ["بازگشت"]]);
    } catch (Exception $e) {
        userDebugLog("Error sending invite banner to user $user_id", ['error' => $e->getMessage()]);
        sendMessage($chat_id, "❌ خطا در ارسال بنر. لطفاً دوباره تلاش کنید.", [["بازگشت"]]);
    }
}

/**
 * ✅ دریافت لیست نام جلسات
 */
function getSessionTitles() {
    $sessions = loadSessions();
    $titles = [];
    foreach ($sessions as $sess) {
        $titles[] = $sess['title'];
    }
    return $titles;
}

/**
 * ✅ بررسی وضعیت تمرین برای یک جلسه خاص - بهبود یافته با لاگ‌گیری پیشرفته
 */
function getUserExerciseStatusForSession($user_id, $session_id) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            userDebugLog("User not found when checking exercise status", ['user_id' => $user_id]);
            return 'not_found';
        }
        
        $exercises = safeDecodeUserData($user['exercises'] ?? null, []);
        
        // نرمال‌سازی session_id به integer
        $normalized_session_id = intval($session_id);
        
        userDebugLog("Checking exercise status", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'normalized_id' => $normalized_session_id,
            'exercises_keys' => array_keys($exercises)
        ]);
        
        // جستجوی تمرین با بررسی هر دو نوع کلید
        $exercise = null;
        
        // اول بررسی کلید integer
        if (isset($exercises[$normalized_session_id])) {
            $exercise = $exercises[$normalized_session_id];
            userDebugLog("Found exercise with integer key", ['session_id' => $normalized_session_id]);
        }
        // سپس بررسی کلید string  
        elseif (isset($exercises[strval($normalized_session_id)])) {
            $exercise = $exercises[strval($normalized_session_id)];
            userDebugLog("Found exercise with string key", ['session_id' => strval($normalized_session_id)]);
        }
        // بررسی تمام کلیدها به صورت دستی برای مطابقت
        else {
            foreach ($exercises as $key => $ex) {
                if (intval($key) === $normalized_session_id) {
                    $exercise = $ex;
                    userDebugLog("Found exercise with manual key match", ['key' => $key, 'session_id' => $normalized_session_id]);
                    break;
                }
            }
        }
        
        if (!$exercise) {
            userDebugLog("Exercise not found for session", [
                'user_id' => $user_id,
                'session_id' => $normalized_session_id,
                'available_sessions' => array_keys($exercises)
            ]);
            return 'not_submitted';
        }
        
        $status = $exercise['status'] ?? 'unknown';
        userDebugLog("Exercise status retrieved", [
            'user_id' => $user_id,
            'session_id' => $normalized_session_id,
            'status' => $status
        ]);
        
        return $status;
        
    } catch (Exception $e) {
        userDebugLog("Error checking exercise status", [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return 'error';
    }
}

/**
 * ✅ تشخیص اینکه کاربر منتظر پاسخ تمرین است - بهبود یافته با لاگ‌گیری پیشرفته
 */
function findPendingExerciseForUser($user_id, $text) {
    try {
        userDebugLog("Finding pending exercise for user", [
            'user_id' => $user_id,
            'text_length' => strlen($text)
        ]);
        
        $user = getUserById($user_id);
        if (!$user) {
            userDebugLog("User not found in findPendingExerciseForUser", ['user_id' => $user_id]);
            return null;
        }
        
        $exercises = safeDecodeUserData($user['exercises'] ?? null, []);
        $seen_sessions = safeDecodeUserData($user['seen_sessions'] ?? null, []);
        $sessions = loadSessions();
        
        userDebugLog("User exercise data", [
            'user_id' => $user_id,
            'exercises_count' => count($exercises),
            'exercises_keys' => array_keys($exercises),
            'seen_sessions_count' => count($seen_sessions),
            'total_sessions' => count($sessions)
        ]);
        
        // جستجو برای جلسه‌ای که کاربر دیده ولی تمرینش pending یا rejected یا not_submitted هست
        foreach ($sessions as $sess) {
            // بررسی اینکه آیا کاربر این جلسه را دیده
            if (!is_array($seen_sessions) || !in_array($sess['title'], $seen_sessions)) {
                continue;
            }
            
            // نرمال‌سازی session_id
            $session_id = intval($sess['id']);
            
            // دریافت وضعیت تمرین با تابع بهبود یافته
            $exercise_status = getUserExerciseStatusForSession($user_id, $session_id);
            
            userDebugLog("Checking session for pending exercise", [
                'session_id' => $session_id,
                'session_title' => $sess['title'],
                'exercise_status' => $exercise_status,
                'has_exercise' => isset($sess['exercise']) && !empty(trim($sess['exercise']))
            ]);
            
            // اگر تمرین وجود نداره، pending هست یا rejected شده
            if ($exercise_status === 'not_submitted' || 
                $exercise_status === 'pending' || 
                $exercise_status === 'rejected') {
                
                // اطمینان از اینکه این جلسه exercise داره
                if (isset($sess['exercise']) && !empty(trim($sess['exercise']))) {
                    userDebugLog("Found pending exercise for user", [
                        'user_id' => $user_id,
                        'session' => $sess['title'], 
                        'session_id' => $session_id, 
                        'status' => $exercise_status
                    ]);
                    return $sess;
                } else {
                    userDebugLog("Session has no exercise content", [
                        'session_id' => $session_id,
                        'session_title' => $sess['title']
                    ]);
                }
            }
        }
        
        userDebugLog("No pending exercise found for user", ['user_id' => $user_id]);
        return null;
        
    } catch (Exception $e) {
        userDebugLog("Error finding pending exercise", [
            'user_id' => $user_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return null;
    }
}

/**
 * ✅ دریافت پیشرفت کاربر در دوره
 */
function getUserProgress($user_id) {
    try {
        $user = getUserById($user_id);
        $sessions = loadSessions();
        
        $seen_sessions = safeDecodeUserData($user['seen_sessions'] ?? null, []);
        $exercises = safeDecodeUserData($user['exercises'] ?? null, []);
        
        $completed_exercises = 0;
        foreach ($exercises as $exercise) {
            if (isset($exercise['status']) && $exercise['status'] === 'accepted') {
                $completed_exercises++;
            }
        }
        
        return [
            'total_sessions' => count($sessions),
            'seen_sessions' => is_array($seen_sessions) ? count($seen_sessions) : 0,
            'completed_exercises' => $completed_exercises
        ];
    } catch (Exception $e) {
        userDebugLog("Error getting user progress for $user_id", ['error' => $e->getMessage()]);
        return ['total_sessions' => 0, 'seen_sessions' => 0, 'completed_exercises' => 0];
    }
}

/**
 * ✅ هندل کاربران عادی - نسخه کامل بهبود یافته
 */
function handleUser($message, $chat_id, $text, $user_id) {
    global $btn_captain, $btn_advanced;

    try {
        $user = getUserById($user_id);
        $user_mobile = isset($user['mobile']) ? $user['mobile'] : null;
        $is_admin = ($user_id == ADMIN_ID);

        userDebugLog("Processing user message", [
            'user_id' => $user_id, 
            'text' => substr($text, 0, 50),
            'is_admin' => $is_admin,
            'has_mobile' => !empty($user_mobile)
        ]);

        // بررسی استارت با لینک معرف
        if (isset($message['text']) && strpos($message['text'], "/start") === 0) {
            $params = explode(" ", $message['text']);
            if (isset($params[1])) {
                $ref_id = intval($params[1]);
                if (handleReferralStart($user_id, $ref_id)) {
                    userDebugLog("User registered with referrer", ['user_id' => $user_id, 'ref_id' => $ref_id]);
                }
            }
            
            registerUser([
                'id' => $user_id,
                'first_name' => $message['from']['first_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'type' => 'user'
            ]);
            
            sendMessage($chat_id, welcomeMessage($user_id), getMainKeyboard($user_id));
            return true;
        }

        // لغو عملیات
        if ($text == "/cancel") {
            if ($is_admin) {
                sendMessage($chat_id, "❌ عملیات لغو شد. به منوی مدیریت برگشتید.", [["پنل مدیریت"]]);
            } else {
                sendMessage($chat_id, "❌ عملیات لغو شد. به منوی اصلی برگشتید.", getMainKeyboard($user_id));
            }
            
            $support_state = loadSupportState();
            unset($support_state[$user_id]);
            saveSupportState($support_state);
            return true;
        }

        // بررسی شماره موبایل
        if (!$is_admin && empty($user_mobile) && !isset($message['contact'])) {
            if ($text == "/start" || $text == "بازگشت") {
                sendContactBtn($chat_id, $user_id);
                return true;
            }
            sendContactBtn($chat_id, $user_id);
            return true;
        }

        // دریافت شماره تماس
        if (isset($message['contact']) && $message['contact']['phone_number']) {
            $mobile = $message['contact']['phone_number'];
            userDebugLog("Contact received", ['user_id' => $user_id, 'mobile' => substr($mobile, 0, 5) . 'XXX']);
            
            registerUser([
                'id' => $user_id,
                'first_name' => $message['from']['first_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'type' => 'user',
                'mobile' => $mobile
            ]);
            sendJoinChannels($chat_id);
            return true;
        }

        // بررسی عضویت در کانال‌ها
        if (!$is_admin && (empty($user['channels_checked']) || intval($user['channels_checked']) == 0)) {
            if ($text == "✅ عضو شدم") {
                userDebugLog("Checking channel membership", ['user_id' => $user_id]);
                
                $joined1 = checkChannelMember($user_id, CHANNEL1);
                $joined2 = checkChannelMember($user_id, CHANNEL2);
                
                if ($joined1 && $joined2) {
                    $user['channels_checked'] = 1;
                    saveUser($user);
                    
                    $welcome_complete = "🎉 <b>ثبت‌نام شما کامل شد!</b>\n\n"
                        . "✅ عضویت در کانال‌ها تایید شد\n"
                        . "✅ دسترسی به تمام امکانات ربات فعال است\n\n"
                        . "حالا می‌توانید از منوی زیر استفاده کنید:";
                    
                    sendMessage($chat_id, $welcome_complete, getMainKeyboard($user_id));
                } else {
                    sendJoinChannels($chat_id);
                    sendMessage($chat_id, "❌ لطفاً ابتدا عضو هر دو کانال شوید سپس دکمه 'عضو شدم' را بزنید.");
                }
                return true;
            }
            sendJoinChannels($chat_id);
            return true;
        }

        // پشتیبانی آنلاین
        $support_state = loadSupportState();
        if (isset($support_state[$user_id]) && $support_state[$user_id] == "waiting_for_reply") {
            if ($text || isset($message['photo']) || isset($message['voice'])) {
                $from = $message['from']['first_name'] ?? 'کاربر';
                $reply_markup = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✉️ پاسخ به کاربر',
                                'callback_data' => "support_reply_" . $user_id
                            ]
                        ]
                    ]
                ];
                
                if ($text) {
                    sendMessage(ADMIN_ID, "📩 <b>پیام جدید از $from</b>\n👤 ID: <code>$user_id</code>\n\n💬 پیام:\n$text", null);
                }
                
                if (isset($message['photo'])) {
                    $photos = $message['photo'];
                    $file_id = $photos[count($photos) - 1]['file_id'];
                    sendFile(ADMIN_ID, 'photo', $file_id, "📩 عکس از $from (ID: $user_id)");
                }
                
                if (isset($message['voice'])) {
                    $file_id = $message['voice']['file_id'];
                    sendFile(ADMIN_ID, 'voice', $file_id, "📩 ویس از $from (ID: $user_id)");
                }
                
                $url = API_URL . "sendMessage?" . http_build_query([
                    'chat_id' => ADMIN_ID,
                    'text' => "پاسخ به همین کاربر:",
                    'reply_markup' => json_encode($reply_markup)
                ]);
                file_get_contents($url);
                
                sendMessage($chat_id, "✅ پیام شما برای پشتیبانی ارسال شد. منتظر پاسخ بمانید.", [["بازگشت"]]);
                
                userDebugLog("Support message sent", ['user_id' => $user_id]);
            } else {
                sendMessage($chat_id, "فقط پیام متنی، عکس یا ویس مجاز است.", [["بازگشت"]]);
            }
            
            unset($support_state[$user_id]);
            saveSupportState($support_state);
            return true;
        }

        // شروع پشتیبانی
        if ($text == "💬 پشتیبانی آنلاین") {
            $support_state[$user_id] = "waiting_for_reply";
            saveSupportState($support_state);
            
            $support_msg = "📞 <b>پشتیبانی آنلاین فعال شد</b>\n\n"
                . "لطفاً پیام، سوال یا مشکل خود را برای تیم پشتیبانی ارسال کنید.\n\n"
                . "📝 قابلیت‌های پشتیبانی:\n"
                . "✅ ارسال پیام متنی\n"
                . "✅ ارسال عکس\n"  
                . "✅ ارسال ویس\n\n"
                . "⏱ زمان پاسخ: کمتر از 24 ساعت";
                
            sendMessage($chat_id, $support_msg, [["بازگشت"]]);
            return true;
        }

        // منوهای اصلی (بعد از تکمیل فرآیند عضویت)
        if (!empty($user['channels_checked'])) {
            
            // دوره رایگان
            if ($text == "🎓 ثبت‌نام دوره رایگان") {
                $stats = getReferralStats($user_id);
                
                if ($stats['can_access_free']) {
                    registerUser([
                        'id' => $user_id,
                        'first_name' => $message['from']['first_name'] ?? '',
                        'username' => $message['from']['username'] ?? '',
                        'type' => 'free'
                    ]);
                    
                    $sessions = loadSessions();
                    if (count($sessions) > 0) {
                        $sessionBtns = [];
                        foreach ($sessions as $sess) {
                            $sessionBtns[] = [$sess['title']];
                        }
                        $sessionBtns[] = ["📊 آمار دعوت‌ها"];
                        $sessionBtns[] = ["بازگشت"];
                        
                        $course_msg = "🎉 <b>تبریک! ثبت‌نام در دوره رایگان انجام شد</b>\n\n"
                            . "📚 دوره شامل <b>" . count($sessions) . " جلسه</b> آموزشی است\n"
                            . "⭐ هر جلسه شامل ویدیو، متن و تمرین عملی\n"
                            . "🎯 برای دریافت گواهی، تمام تمرین‌ها را تکمیل کنید\n\n"
                            . "🎬 <b>جلسات آموزشی:</b>";
                        
                        sendMessage($chat_id, $course_msg, $sessionBtns);
                        
                        userDebugLog("User enrolled in free course", ['user_id' => $user_id, 'sessions_count' => count($sessions)]);
                    } else {
                        sendMessage($chat_id, "❌ هنوز جلسات آموزشی توسط ادمین تعریف نشده است.", getMainKeyboard($user_id));
                    }
                } else {
                    $msg = "⛔️ <b>دسترسی به دوره رایگان فرمول ۵ مرحله‌ای کاپیتان!</b>\n\n"
                        . "برای استفاده از این دوره ارزشمند باید حداقل <b>" . MIN_REFERRALS_FOR_FREE_COURSE . " نفر</b> را با لینک دعوت اختصاصی خودت به ربات معرفی کنی.\n\n"
                        . "📊 وضعیت فعلی شما:\n"
                        . "✅ تعداد دعوت موفق: <b>{$stats['total']}</b> نفر\n"
                        . "⏳ باقی‌مانده: <b>{$stats['needed_for_free']}</b> نفر\n\n"
                        . "🔗 لینک اختصاصی تو:\n"
                        . getReferralLink($user_id);

                    sendMessage($chat_id, $msg, [["🎁 دریافت بنر تبلیغاتی"], ["📊 آمار دعوت‌ها"], ["بازگشت"]]);
                }
                return true;
            }

            // آمار دعوت‌ها
            if ($text == "📊 آمار دعوت‌ها") {
                showReferralStats($chat_id, $user_id);
                return true;
            }

            // دریافت بنر تبلیغاتی
            if ($text == "🎁 دریافت بنر تبلیغاتی") {
                sendInviteBannerToUser($chat_id, $user_id);
                return true;
            }

            // لیست برترین معرف‌ها
            if ($text == "🏆 لیست برترین معرف‌ها") {
                showTopReferrers($chat_id, $user_id);
                return true;
            }

            // ثبت‌نام ویژه با تخفیف
            if ($text == "🚀 ثبت‌نام ویژه پیشرفته با تخفیف") {
                $refCount = getReferralCount($user_id);
                if ($refCount >= MIN_REFERRALS_FOR_ADVANCED_DISCOUNT) {
                    registerUser([
                        'id' => $user_id,
                        'first_name' => $message['from']['first_name'] ?? '',
                        'username' => $message['from']['username'] ?? '',
                        'type' => 'pls_discount'
                    ]);
                    
                    $vip_msg = "🎉 <b>تبریک! واجد شرایط تخفیف ویژه شدید</b>\n\n"
                        . "🎯 با دعوت <b>$refCount نفر</b> شما واجد شرایط ثبت‌نام دوره پیشرفته با تخفیف ویژه هستید!\n\n"
                        . "💎 مزایای شما:\n"
                        . "✅ تخفیف ویژه روی دوره پیشرفته\n"
                        . "✅ دسترسی اولویت‌دار به محتوا\n"
                        . "✅ پشتیبانی اختصاصی\n\n"
                        . "📞 برای ثبت‌نام و دریافت کد تخفیف، با پشتیبانی تماس بگیرید.";
                    
                    sendMessage($chat_id, $vip_msg, getMainKeyboard($user_id));
                } else {
                    $needed = MIN_REFERRALS_FOR_ADVANCED_DISCOUNT - $refCount;
                    $msg = "⚠️ <b>شرایط تخفیف ویژه:</b>\n\n"
                        . "برای استفاده از تخفیف باید حداقل <b>" . MIN_REFERRALS_FOR_ADVANCED_DISCOUNT . " نفر</b> را دعوت کنید.\n\n"
                        . "📊 وضعیت فعلی شما:\n"
                        . "▪️ دعوت‌شده: <b>$refCount</b> نفر\n"
                        . "▪️ باقی‌مانده: <b>$needed</b> نفر\n\n"
                        . "🚀 با دعوت $needed نفر دیگر، تخفیف ویژه را دریافت کنید!";
                    
                    sendMessage($chat_id, $msg, [["🎁 دریافت بنر تبلیغاتی"], ["📊 آمار دعوت‌ها"], ["بازگشت"]]);
                }
                return true;
            }

            // دریافت کد تخفیف ویژه (برای کاربران تکمیل‌کننده دوره)
            if ($text == "🎉 دریافت کد تخفیف ویژه") {
                if (function_exists('isUserEligibleForCampaign') && isUserEligibleForCampaign($user_id)) {
                    if (function_exists('startCampaign')) {
                        $campaign_started = startCampaign($user_id);
                        if ($campaign_started) {
                            $success_msg = "🎉 <b>تبریک! کمپین ویژه شما شروع شد</b>\n\n"
                                . "✅ کد تخفیف اختصاصی شما در حال ارسال است\n"
                                . "📧 طی چند لحظه پیام‌های ویژه دریافت خواهید کرد\n"
                                . "⏰ این تخفیف محدود به زمان است\n\n"
                                . "💎 از این فرصت طلایی استفاده کنید!";
                            
                            sendMessage($chat_id, $success_msg, getMainKeyboard($user_id));
                        } else {
                            sendMessage($chat_id, "❌ خطا در شروع کمپین. لطفاً با پشتیبانی تماس بگیرید.", getMainKeyboard($user_id));
                        }
                    } else {
                        sendMessage($chat_id, "❌ سیستم کمپین در دسترس نیست.", getMainKeyboard($user_id));
                    }
                } else {
                    sendMessage($chat_id, "⚠️ شما هنوز واجد شرایط دریافت کد تخفیف نیستید.\n\nابتدا دوره رایگان را کامل کنید.", getMainKeyboard($user_id));
                }
                return true;
            }

            // دکمه‌های سفارشی
            if ($text == $btn_advanced['btn']) {
                registerUser([
                    'id' => $user_id,
                    'first_name' => $message['from']['first_name'] ?? '',
                    'username' => $message['from']['username'] ?? '',
                    'type' => 'pls'
                ]);
                sendMessage($chat_id, $btn_advanced['msg'], getMainKeyboard($user_id));
                return true;
            }

            if ($text == $btn_captain['btn']) {
                sendMessage($chat_id, $btn_captain['msg'], getMainKeyboard($user_id));
                return true;
            }

            // **🎯 جلسات دوره - منطق تصحیح شده**
            $session_titles = getSessionTitles();
            if (in_array($text, $session_titles)) {
                $sessions = loadSessions();
                foreach ($sessions as $sess) {
                    if ($text == $sess['title']) {
                        // ✅ بررسی دسترسی به جلسه
                        if (function_exists('canSeeNextSession') && !canSeeNextSession($user_id, $sess['title'])) {
                            sendMessage($chat_id, "⛔️ تمرین جلسه قبلی شما هنوز تایید نشده است.\n\nابتدا تمرین را به درستی ارسال کنید و منتظر تایید ادمین باشید.", [["بازگشت"]]);
                            return true;
                        }

                        // ثبت مشاهده جلسه
                        markSessionSeen($user_id, $sess['title']);

                        // ارسال محتوای جلسه
                        $msg = "🎓 <b>{$sess['title']}</b>";
                        if (isset($sess['text']) && strlen(trim($sess['text'])) > 0) {
                            $msg .= "\n\n" . $sess['text'];
                        }
                        sendMessage($chat_id, $msg, [["بازگشت"]]);

                        // ارسال فایل‌های جلسه
                        if (!empty($sess['files'])) {
                            foreach ($sess['files'] as $file) {
                                if ($file['type'] == 'text') {
                                    sendMessage($chat_id, $file['content']);
                                } else {
                                    $caption = isset($file['caption']) && $file['caption'] !== "" ? $file['caption'] : $sess['title'];
                                    sendFile($chat_id, $file['type'], $file['file_id'], $caption);
                                }
                            }
                        }

                        // ✅ ارسال تمرین (اگر وجود دارد)
                        if (function_exists('sendExercise')) {
                            sendExercise($user_id, $sess['title']);
                        }
                        
                        userDebugLog("Session accessed", ['user_id' => $user_id, 'session' => $sess['title']]);
                        return true;
                    }
                }
            }

            // **🔥 هندل پاسخ تمرین - منطق کاملاً تصحیح شده**
            if ($text && 
                $text != "/start" && 
                $text != "بازگشت" && 
                $text != "📊 آمار دعوت‌ها" && 
                $text != "🎁 دریافت بنر تبلیغاتی" && 
                $text != "🏆 لیست برترین معرف‌ها" &&
                $text != "💬 پشتیبانی آنلاین" &&
                $text != "🎓 ثبت‌نام دوره رایگان" &&
                $text != $btn_captain['btn'] &&
                $text != $btn_advanced['btn'] &&
                $text != "🚀 ثبت‌نام ویژه پیشرفته با تخفیف" &&
                $text != "🎉 دریافت کد تخفیف ویژه" &&
                $text != "✅ عضو شدم" &&
                !in_array($text, $session_titles)) {
                
                // ✅ جستجوی هوشمند برای تمرین pending
                $pending_session = findPendingExerciseForUser($user_id, $text);
                
                if ($pending_session && function_exists('handleExerciseAnswer')) {
                    if (handleExerciseAnswer($user_id, $pending_session['title'], $text)) {
                        $success_msg = "✅ <b>پاسخ تمرین ثبت شد</b>\n\n"
                            . "📝 تمرین جلسه: <b>{$pending_session['title']}</b>\n"
                            . "📤 پاسخ شما برای ادمین ارسال شد\n"
                            . "⏳ منتظر بررسی و تایید باشید\n\n"
                            . "🔔 نتیجه از طریق همین ربات اطلاع‌رسانی می‌شود";
                        
                        sendMessage($chat_id, $success_msg, [["بازگشت"]]);
                        
                        userDebugLog("Exercise answer submitted", [
                            'user_id' => $user_id, 
                            'session' => $pending_session['title'],
                            'answer_length' => strlen($text)
                        ]);
                        return true;
                    }
                }
            }

            // بازگشت به منوی اصلی
            if ($text == "/start" || $text == "بازگشت") {
                sendMessage($chat_id, welcomeMessage($user_id), getMainKeyboard($user_id));
                return true;
            }
        } else {
            // کاربر هنوز فرآیند عضویت را تکمیل نکرده
            if ($text == "/start" || $text == "بازگشت") {
                if (empty($user_mobile)) {
                    sendContactBtn($chat_id, $user_id);
                } else {
                    sendJoinChannels($chat_id);
                }
                return true;
            }
        }

        // پیام راهنما برای پیام‌های نامشخص
        $help_msg = "❔ <b>پیام نامشخص</b>\n\n"
            . "لطفاً از دکمه‌های منو استفاده کنید.\n"
            . "در صورت نیاز به راهنمایی، از بخش 'پشتیبانی آنلاین' استفاده کنید.";
        
        sendMessage($chat_id, $help_msg, getMainKeyboard($user_id));
        return true;

    } catch (Exception $e) {
        userDebugLog("Error in handleUser for user $user_id", ['error' => $e->getMessage()]);
        sendMessage($chat_id, "❌ خطایی رخ داد. لطفاً دوباره تلاش کنید.", getMainKeyboard($user_id));
        return true;
    }
}

/**
 * ✅ تابع کمکی برای سازگاری با admin.php
 */
function getMainKeyboardPro($user_id) {
    return getMainKeyboard($user_id);
}
?>