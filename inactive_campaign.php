<?php
/**
 * کمپین جشنواره ماهانه برای کاربران 30 روز غیرفعال
 * استفاده از campaign_messages.json اصلی
 * نسخه نهایی - 11 اکتبر 2025
 */

require_once 'functions.php';
require_once 'config.php';
require_once 'db.php';
require_once 'campaign.php';

/**
 * بررسی واجد شرایط بودن
 */
function isEligibleForInactiveCampaign($user_id) {
    try {
        $user = getUserById($user_id);
        if (!$user) {
            error_log("❌ User $user_id not found");
            return false;
        }
        
        // 1. قبلاً کمپین غیرفعالی شروع شده؟
        if (isset($user['inactive_campaign_started']) && $user['inactive_campaign_started']) {
            error_log("⏭️ User $user_id: Already has inactive campaign");
            return false;
        }
        
        // 2. کمپین اصلی فعال؟
        if (isset($user['campaign_started']) && $user['campaign_started']) {
            error_log("⏭️ User $user_id: Already has main campaign");
            return false;
        }
        
        // 3. دسترسی به دوره رایگان
        if (!canAccessFreeCourse($user_id)) {
            error_log("❌ User $user_id: No access to free course");
            return false;
        }
        
        // 4. دوره تکمیل شده؟
        $sessions = loadSessions();
        $seen_sessions = isset($user['seen_sessions']) ? 
            (is_string($user['seen_sessions']) ? json_decode($user['seen_sessions'], true) : $user['seen_sessions']) : [];
        
        if (is_array($seen_sessions) && count($seen_sessions) >= count($sessions)) {
            error_log("⏭️ User $user_id: Course completed");
            return false;
        }
        
        // 5. حداقل 30 روز غیرفعال
        $last_activity = getLastActivity($user_id);
        $days_inactive = floor((time() - $last_activity) / 86400);
        
        if ($days_inactive < 30) {
            error_log("⏳ User $user_id: Only $days_inactive days inactive");
            return false;
        }
        
        error_log("✅ User $user_id: ELIGIBLE ($days_inactive days inactive)");
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error checking eligibility: " . $e->getMessage());
        return false;
    }
}

/**
 * شروع کمپین جشنواره
 */
function startInactiveCampaign($user_id) {
    try {
        global $pdo;
        
        $user = getUserById($user_id);
        $name = $user['first_name'] ?? 'کاربر';
        
        $seen_sessions = isset($user['seen_sessions']) ? 
            (is_string($user['seen_sessions']) ? json_decode($user['seen_sessions'], true) : $user['seen_sessions']) : [];
        $seen_count = is_array($seen_sessions) ? count($seen_sessions) : 0;
        $total_sessions = count(loadSessions());
        $remaining = $total_sessions - $seen_count;
        
        error_log("🚀 Starting inactive campaign for user $user_id ($name) - $seen_count/$total_sessions sessions");
        
        // پیام معرفی جشنواره
        $intro = "🎊 <b>خبر فوق‌العاده برای {$name} عزیز!</b>\n\n"
            . "🎯 جشنواره ماهانه ما:\n"
            . "هر ماه از بین <b>هزاران نفری</b> که وارد ربات می‌شن،\n"
            . "سیستم ما به صورت <b>قرعه‌کشی</b> فقط <b>100 نفر</b> رو انتخاب می‌کنه!\n\n"
            . "این افراد می‌تونن با تخفیف ویژه <b>بالای 80%</b>\n"
            . "وارد دوره پیشرفته PLS بشن! 💎\n\n"
            . "━━━━━━━━━━━━━━━━\n"
            . "🍀 <b>تبریک می‌گم!</b>\n"
            . "تو جزو این 100 نفر شانس‌آورد شدی! 🎉\n"
            . "━━━━━━━━━━━━━━━━\n\n";
        
        // آمار شخصی
        if ($seen_count > 0) {
            $intro .= "📊 <b>آمار پیشرفت تو:</b>\n"
                . "✅ دیده شده: <b>$seen_count</b> از <b>$total_sessions</b> جلسه\n"
                . "⏳ باقی‌مانده: <b>$remaining</b> جلسه\n\n";
        } else {
            $intro .= "💡 <b>هنوز فرصت نکردی دوره رو ببینی؟</b>\n"
                . "مشکلی نیست! این فرصت مستقیماً برای توئه! 🎁\n\n";
        }
        
        $intro .= "💎 <b>الان می‌تونی:</b>\n"
            . "✅ با تخفیف <b>82%</b> (14 میلیون → 2.48 میلیون)\n"
            . "✅ وارد دوره پیشرفته سیستم PLS بشی\n"
            . "✅ از این فرصت طلایی استفاده کنی!\n\n"
            . "⚠️ <b>توجه:</b> این جشنواره فقط برای این ماه فعاله\n"
            . "و ظرفیت محدوده! عجله کن! 🔥\n\n"
            . "📩 چند لحظه دیگه جزئیات کامل برات می‌فرستم...";
        
        // ارسال پیام معرفی
        if (!sendMessage($user_id, $intro)) {
            error_log("❌ Failed to send intro to user $user_id");
            return false;
        }
        
        error_log("✅ Intro sent to user $user_id");
        
        // 10 ثانیه صبر
        sleep(10);
        
        // شروع کمپین اصلی
        $campaign_result = startCampaign($user_id);
        
        if (!$campaign_result) {
            error_log("❌ Failed to start main campaign for user $user_id");
            return false;
        }
        
        // علامت‌گذاری
        $stmt = $pdo->prepare("
            UPDATE users SET 
                inactive_campaign_started = 1,
                inactive_campaign_start_time = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        
        error_log("✅ Inactive campaign started for user $user_id");
        
        // اطلاع به ادمین
        $days_inactive = floor((time() - getLastActivity($user_id)) / 86400);
        $admin_msg = "🎉 <b>کمپین جشنواره فعال شد</b>\n\n"
            . "👤 نام: $name\n"
            . "🆔 ID: <code>$user_id</code>\n"
            . "📊 پیشرفت: $seen_count/$total_sessions جلسه\n"
            . "⏰ غیرفعال: $days_inactive روز\n"
            . "🎯 کمپین اصلی استارت خورد";
        
        sendMessage(ADMIN_ID, $admin_msg);
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Exception in startInactiveCampaign: " . $e->getMessage());
        return false;
    }
}

/**
 * پردازش کمپین‌ها
 */
function processInactiveCampaigns() {
    try {
        error_log("🔄 Processing inactive campaigns at " . date('Y-m-d H:i:s'));
        
        global $pdo;
        
        $stmt = $pdo->query("
            SELECT id, first_name, last_activity, type
            FROM users 
            WHERE 
                (type = 'free' OR type = 'user')
                AND (inactive_campaign_started IS NULL OR inactive_campaign_started = 0)
                AND (campaign_started IS NULL OR campaign_started = 0)
                AND last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY last_activity ASC
            LIMIT 50
        ");
        
        $processed = 0;
        $started = 0;
        $skipped = 0;
        
        while ($row = $stmt->fetch()) {
            $user_id = $row['id'];
            $processed++;
            
            error_log("🔍 Checking user $user_id ({$row['first_name']})...");
            
            if (isEligibleForInactiveCampaign($user_id)) {
                if (startInactiveCampaign($user_id)) {
                    $started++;
                    error_log("✅ Campaign started for user $user_id");
                    sleep(2); // تاخیر 2 ثانیه
                } else {
                    error_log("❌ Failed to start campaign for user $user_id");
                }
            } else {
                $skipped++;
            }
        }
        
        error_log("📊 Processing completed - Checked: $processed, Started: $started, Skipped: $skipped");
        
        // گزارش به ادمین
        if ($started > 0 && defined('ADMIN_ID')) {
            $summary = "📊 <b>گزارش کمپین جشنواره</b>\n\n"
                . "🔍 بررسی شده: $processed کاربر\n"
                . "✅ فعال شده: $started کمپین\n"
                . "⏭️ رد شده: $skipped کاربر\n\n"
                . "⏰ " . date('Y-m-d H:i:s');
            
            sendMessage(ADMIN_ID, $summary);
        }
        
        return $started;
        
    } catch (Exception $e) {
        error_log("❌ CRITICAL ERROR: " . $e->getMessage());
        
        if (defined('ADMIN_ID')) {
            sendMessage(ADMIN_ID, "🚨 خطا در کمپین جشنواره:\n" . $e->getMessage());
        }
        
        return false;
    }
}

// کرون جاب
if (php_sapi_name() === 'cli' || (isset($argv) && in_array('inactive_campaign_cron', $argv))) {
    error_log("🚀 Inactive campaign cron started");
    processInactiveCampaigns();
}
?>