<?php
/**
 * سیستم معرفی و جایزه (Referral) - نسخه کامل و اصلاح شده
 * پشتیبانی کامل از MySQL و ویژگی‌های پیشرفته
 */

require_once 'functions.php';
require_once 'config.php';

/**
 * تولید لینک اختصاصی کاربر
 */
function getReferralLink($user_id) {
    return "https://t.me/" . BOT_USERNAME . "?start=" . $user_id;
}

/**
 * ثبت معرف هنگام ورود با لینک اختصاصی
 */
function handleReferralStart($user_id, $ref_id) {
    try {
        // بررسی اعتبار
        if ($user_id == $ref_id) {
            error_log("User $user_id tried to refer themselves");
            return false; // خودش را معرفی نکرده باشد
        }
        
        if (!isValidUserId($ref_id)) {
            error_log("Invalid referrer ID: $ref_id");
            return false;
        }
        
        $user = getUserById($user_id);
        
        if (!$user) {
            // اگر کاربر هنوز ثبت نشده بود، ثبت اولیه با ref
            $user = [
                'id' => $user_id,
                'first_name' => '',
                'username' => '',
                'registered_at' => date('Y-m-d H:i:s'),
                'type' => '',
                'ref' => $ref_id,
                'last_activity' => date('Y-m-d H:i:s')
            ];
            $result = saveUser($user);
            
            if ($result) {
                error_log("New user $user_id registered with referrer $ref_id");
                
                // اطلاع به معرف
                $referrer_count = getReferralCount($ref_id);
                $milestone_message = "";
                
                if ($referrer_count == MIN_REFERRALS_FOR_FREE_COURSE) {
                    $milestone_message = "\n\n🎉 تبریک! شما به ۵ دعوت رسیدید و دوره رایگان برای شما فعال شد!";
                } elseif ($referrer_count == MIN_REFERRALS_FOR_ADVANCED_DISCOUNT) {
                    $milestone_message = "\n\n🚀 فوق‌العاده! شما به ۲۰ دعوت رسیدید و تخفیف ویژه دوره پیشرفته برای شما فعال شد!";
                }
                
                $notification = "🎯 کاربر جدیدی با لینک شما وارد شد!\n\nتعداد کل دعوت‌های شما: <b>$referrer_count</b>" . $milestone_message;
                sendMessage($ref_id, $notification);
            }
            
            return $result;
        }
        
        // اگر کاربر موجود است اما هنوز معرف ندارد
        if (!isset($user['ref']) || !$user['ref']) {
            $user['ref'] = $ref_id;
            $result = saveUser($user);
            
            if ($result) {
                error_log("Referrer $ref_id set for existing user $user_id");
                
                // اطلاع به معرف
                $referrer_count = getReferralCount($ref_id);
                $notification = "🎯 کاربری که قبلاً ثبت‌نام کرده بود، حالا از لینک شما استفاده کرد!\n\nتعداد کل دعوت‌های شما: <b>$referrer_count</b>";
                sendMessage($ref_id, $notification);
            }
            
            return $result;
        }
        
        error_log("User $user_id already has a referrer: {$user['ref']}");
        return false;
        
    } catch (Exception $e) {
        error_log("Error handling referral start: " . $e->getMessage());
        return false;
    }
}

/**
 * دریافت تعداد دعوت موفق کاربر
 */
function getReferralCount($user_id) {
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE ref = ?");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting referral count for user $user_id: " . $e->getMessage());
        return 0;
    }
}

/**
 * آیا کاربر مجاز به دریافت دوره رایگان است؟
 */
function canAccessFreeCourse($user_id) {
    return getReferralCount($user_id) >= MIN_REFERRALS_FOR_FREE_COURSE;
}

/**
 * آیا کاربر مجاز به تخفیف ویژه هست؟
 */
function canAccessPlsDiscount($user_id) {
    return getReferralCount($user_id) >= MIN_REFERRALS_FOR_ADVANCED_DISCOUNT;
}

/**
 * لیست آی‌دی کسانی که با لینک این کاربر وارد شده‌اند
 */
function getReferralUserIds($user_id) {
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT id, first_name, registered_at FROM users WHERE ref = ? ORDER BY registered_at DESC");
        $stmt->execute([$user_id]);
        
        $referrals = [];
        while ($row = $stmt->fetch()) {
            $referrals[] = [
                'id' => $row['id'],
                'name' => $row['first_name'],
                'date' => $row['registered_at']
            ];
        }
        
        return $referrals;
    } catch (Exception $e) {
        error_log("Error getting referral user IDs for user $user_id: " . $e->getMessage());
        return [];
    }
}

/**
 * دریافت آمار کامل دعوت‌ها برای کاربر
 */
function getReferralStats($user_id) {
    try {
        $referrals = getReferralUserIds($user_id);
        $total = count($referrals);
        
        // آمار زمانی
        $today = 0;
        $this_week = 0;
        $this_month = 0;
        
        $now = time();
        $today_start = strtotime(date('Y-m-d 00:00:00'));
        $week_start = strtotime('-7 days');
        $month_start = strtotime('-30 days');
        
        foreach ($referrals as $ref) {
            $ref_time = strtotime($ref['date']);
            
            if ($ref_time >= $today_start) $today++;
            if ($ref_time >= $week_start) $this_week++;
            if ($ref_time >= $month_start) $this_month++;
        }
        
        return [
            'total' => $total,
            'today' => $today,
            'this_week' => $this_week,
            'this_month' => $this_month,
            'referrals' => $referrals,
            'can_access_free' => canAccessFreeCourse($user_id),
            'can_access_discount' => canAccessPlsDiscount($user_id),
            'needed_for_free' => max(0, MIN_REFERRALS_FOR_FREE_COURSE - $total),
            'needed_for_discount' => max(0, MIN_REFERRALS_FOR_ADVANCED_DISCOUNT - $total)
        ];
    } catch (Exception $e) {
        error_log("Error getting referral stats for user $user_id: " . $e->getMessage());
        return [
            'total' => 0,
            'today' => 0,
            'this_week' => 0,
            'this_month' => 0,
            'referrals' => [],
            'can_access_free' => false,
            'can_access_discount' => false,
            'needed_for_free' => MIN_REFERRALS_FOR_FREE_COURSE,
            'needed_for_discount' => MIN_REFERRALS_FOR_ADVANCED_DISCOUNT
        ];
    }
}

/**
 * بنر تبلیغاتی و متن جذاب برای ارسال به دوستان
 */
function getInviteBanner($user_id) {
    $link = getReferralLink($user_id);
    $stats = getReferralStats($user_id);
    
    $text = "🎁 <b>دوره فرمول ۵ مرحله‌ای کاپیتان، هدیه‌ای ارزشمند به مدت محدود رایگان!</b>\n\n"
        . "🧠 توی این دوره:\n"
        . "✅ استراتژی شخصی کاپیتان و صفر تا صد فارکس رو یاد می‌گیری\n"
        . "✅ آموزش بکتست‌گیری و ژورنال‌نویسی حرفه‌ای به سبک کاپیتان\n"
        . "✅ ستاپ‌های سودده و فرمول امپراطوری پراپ‌فرم‌ها رو یاد می‌گیری\n"
        . "✅ نوشتن پلن معاملاتی مکتوب و...\n\n"
        . "💎 بهت قول میدم این دوره، ارزشمندترین تجربه آموزشی زندگیت میشه!\n\n"
        . "🔗 <b>برای ثبت‌نام رایگان، همین الان روی لینک زیر کلیک کن 👇</b>\n"
        . "$link\n\n"
        . "📊 تعداد دعوت‌های فعلی شما: <b>{$stats['total']}</b>";
    
    $photo_url = "http://capitantrader.ir/wp-content/uploads/2025/09/1758540013422.jpg";
    
    return [
        'text' => $text, 
        'photo' => $photo_url,
        'stats' => $stats
    ];
}

/**
 * بررسی اینکه کاربر کل جلسات را دیده یا نه
 */
function hasSeenAllSessions($user_id) {
    try {
        $user = getUserById($user_id);
        $sessions = loadSessions();
        
        if (!$user || !isset($user['seen_sessions'])) {
            return false;
        }
        
        $seen = is_string($user['seen_sessions']) ? 
                json_decode($user['seen_sessions'], true) : 
                $user['seen_sessions'];
        
        return is_array($seen) && count($seen) >= count($sessions);
    } catch (Exception $e) {
        error_log("Error checking if user $user_id has seen all sessions: " . $e->getMessage());
        return false;
    }
}

/**
 * ثبت مشاهده یک جلسه توسط کاربر
 */
function markSessionSeen($user_id, $session_title) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            error_log("User $user_id not found when marking session seen");
            return false;
        }
        
        // اصلاح: بررسی نوع داده قبل از json_decode
        $seen = [];
        if (isset($user['seen_sessions'])) {
            if (is_string($user['seen_sessions'])) {
                $seen = json_decode($user['seen_sessions'], true) ?: [];
            } elseif (is_array($user['seen_sessions'])) {
                $seen = $user['seen_sessions'];
            }
        }
        
        if (!in_array($session_title, $seen)) {
            $seen[] = $session_title;
            $user['seen_sessions'] = $seen; // ذخیره به صورت آرایه، saveUser خودش json_encode می‌کند
            $user['last_activity'] = date('Y-m-d H:i:s');
            $user['inactivity_remind'] = 0; // ریست یادآوری غیرفعالی
            
            $result = saveUser($user);
            
            if ($result) {
                error_log("Session '$session_title' marked as seen for user $user_id");
            }
            
            return $result;
        }
        
        return true; // قبلاً دیده شده
    } catch (Exception $e) {
        error_log("Error marking session seen for user $user_id: " . $e->getMessage());
        return false;
    }
}

/**
 * لیست جلسات دیده نشده برای کاربر
 */
function getUnseenSessions($user_id) {
    try {
        $user = getUserById($user_id);
        $sessions = loadSessions();
        
        $seen = [];
        if (isset($user['seen_sessions'])) {
            if (is_string($user['seen_sessions'])) {
                $seen = json_decode($user['seen_sessions'], true) ?: [];
            } elseif (is_array($user['seen_sessions'])) {
                $seen = $user['seen_sessions'];
            }
        }
        
        $unseen = [];
        foreach ($sessions as $sess) {
            if (!in_array($sess['title'], $seen)) {
                $unseen[] = $sess['title'];
            }
        }
        
        return $unseen;
    } catch (Exception $e) {
        error_log("Error getting unseen sessions for user $user_id: " . $e->getMessage());
        return [];
    }
}

/**
 * دریافت رتبه کاربر بر اساس تعداد دعوت‌ها - اصلاح شده
 */
function getUserReferralRank($user_id) {
    try {
        global $pdo;
        
        // گرفتن رتبه کاربر - اصلاح خطای SQL
        $stmt = $pdo->prepare("
            SELECT COUNT(*) + 1 as rank 
            FROM (
                SELECT ref, COUNT(*) as ref_count 
                FROM users 
                WHERE ref IS NOT NULL 
                GROUP BY ref
            ) as ref_stats 
            WHERE ref_count > (
                SELECT COUNT(*) 
                FROM users 
                WHERE ref = ?
            )
        ");
        $stmt->execute([$user_id]);
        $rank = $stmt->fetchColumn();
        
        // تعداد کل کاربرانی که دعوت کرده‌اند
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT ref) as total_referrers 
            FROM users 
            WHERE ref IS NOT NULL
        ");
        $total_referrers = $stmt->fetchColumn();
        
        return [
            'rank' => $rank ?: 1,
            'total_referrers' => $total_referrers ?: 1,
            'user_referrals' => getReferralCount($user_id)
        ];
    } catch (Exception $e) {
        error_log("Error getting referral rank for user $user_id: " . $e->getMessage());
        return [
            'rank' => 1,
            'total_referrers' => 1,
            'user_referrals' => 0
        ];
    }
}

/**
 * دریافت لیست برترین معرف‌ها (برای ادمین)
 */
function getTopReferrers($limit = 10) {
    try {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, COUNT(referred.id) as referral_count
            FROM users u
            LEFT JOIN users referred ON referred.ref = u.id
            GROUP BY u.id, u.first_name
            HAVING referral_count > 0
            ORDER BY referral_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        
        $top_referrers = [];
        while ($row = $stmt->fetch()) {
            $top_referrers[] = [
                'user_id' => $row['id'],
                'name' => $row['first_name'],
                'referral_count' => $row['referral_count']
            ];
        }
        
        return $top_referrers;
    } catch (Exception $e) {
        error_log("Error getting top referrers: " . $e->getMessage());
        return [];
    }
}

/**
 * دریافت آمار کلی سیستم رفرال
 */
function getReferralSystemStats() {
    try {
        global $pdo;
        
        $stats = [
            'total_users' => 0,
            'users_with_referrals' => 0,
            'total_referrals' => 0,
            'active_referrers' => 0,
            'users_qualified_for_free' => 0,
            'users_qualified_for_discount' => 0
        ];
        
        // کل کاربران
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = (int)$stmt->fetchColumn();
        
        // کاربران با رفرال
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE ref IS NOT NULL");
        $stats['users_with_referrals'] = (int)$stmt->fetchColumn();
        
        // کل رفرال‌ها
        $stats['total_referrals'] = $stats['users_with_referrals'];
        
        // معرف‌های فعال
        $stmt = $pdo->query("SELECT COUNT(DISTINCT ref) FROM users WHERE ref IS NOT NULL");
        $stats['active_referrers'] = (int)$stmt->fetchColumn();
        
        // واجدین شرایط دوره رایگان
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT ref FROM users 
                WHERE ref IS NOT NULL 
                GROUP BY ref 
                HAVING COUNT(*) >= ?
            ) as qualified
        ");
        $stmt->execute([MIN_REFERRALS_FOR_FREE_COURSE]);
        $stats['users_qualified_for_free'] = (int)$stmt->fetchColumn();
        
        // واجدین شرایط تخفیف
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT ref FROM users 
                WHERE ref IS NOT NULL 
                GROUP BY ref 
                HAVING COUNT(*) >= ?
            ) as qualified
        ");
        $stmt->execute([MIN_REFERRALS_FOR_ADVANCED_DISCOUNT]);
        $stats['users_qualified_for_discount'] = (int)$stmt->fetchColumn();
        
        return $stats;
    } catch (Exception $e) {
        error_log("Error getting referral system stats: " . $e->getMessage());
        return false;
    }
}

/**
 * لیست کاربرانی که واجد شرایط دوره رایگان هستند
 */
function getQualifiedUsersForFreeCourse() {
    try {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, COUNT(r.id) as referral_count
            FROM users u
            JOIN users r ON r.ref = u.id
            GROUP BY u.id, u.first_name
            HAVING referral_count >= ?
            ORDER BY referral_count DESC
        ");
        $stmt->execute([MIN_REFERRALS_FOR_FREE_COURSE]);
        
        $qualified = [];
        while ($row = $stmt->fetch()) {
            $qualified[] = [
                'user_id' => $row['id'],
                'name' => $row['first_name'],
                'referral_count' => $row['referral_count'],
                'qualified_for_discount' => $row['referral_count'] >= MIN_REFERRALS_FOR_ADVANCED_DISCOUNT
            ];
        }
        
        return $qualified;
    } catch (Exception $e) {
        error_log("Error getting qualified users for free course: " . $e->getMessage());
        return [];
    }
}
?>