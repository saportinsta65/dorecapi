<?php
/**
 * اتصال به پایگاه داده MySQL
 * نسخه بهبود یافته - 29 سپتامبر 2025
 */

// تنظیمات پایگاه داده
$host = 'localhost';
$db   = 'capitan1_dtbsdcap';
$user = 'capitan1_usrdorcapi';
$pass = 'w!cG~~hBRL1FT950';
$charset = 'utf8mb4';

// DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// تنظیمات PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false, // اتصال غیر دائمی
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

try {
    // ایجاد اتصال PDO
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // تنظیم timezone دیتابیس
    $pdo->exec("SET time_zone = '+03:30'"); // Tehran timezone
    
    // لاگ موفقیت‌آمیز بودن اتصال (فقط در حالت دیباگ)
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("✅ Database connection established successfully");
    }
    
} catch (PDOException $e) {
    // لاگ خطای اتصال
    error_log("❌ Database connection failed: " . $e->getMessage());
    
    // در محیط تولید، پیام خطای عمومی نمایش دهید
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("خطا در اتصال به پایگاه داده. لطفاً بعداً تلاش کنید.");
    }
}

/**
 * تابع کمکی برای اجرای query های امن
 */
function executeQuery($query, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("❌ Query execution failed: " . $e->getMessage() . " | Query: $query");
        throw $e;
    }
}

/**
 * تابع کمکی برای دریافت یک رکورد
 */
function fetchOne($query, $params = []) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetch();
}

/**
 * تابع کمکی برای دریافت چندین رکورد
 */
function fetchAll($query, $params = []) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetchAll();
}

/**
 * تابع کمکی برای دریافت تعداد رکوردها
 */
function fetchCount($query, $params = []) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetchColumn();
}

/**
 * تابع کمکی برای شروع تراکنش
 */
function beginTransaction() {
    global $pdo;
    return $pdo->beginTransaction();
}

/**
 * تابع کمکی برای تایید تراکنش
 */
function commit() {
    global $pdo;
    return $pdo->commit();
}

/**
 * تابع کمکی برای لغو تراکنش
 */
function rollback() {
    global $pdo;
    return $pdo->rollback();
}

/**
 * بررسی وضعیت اتصال
 */
function checkDatabaseConnection() {
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT 1');
        return $stmt !== false;
    } catch (PDOException $e) {
        error_log("❌ Database connection check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * دریافت آمار پایگاه داده
 */
function getDatabaseStats() {
    global $pdo;
    try {
        $stats = [];
        
        // تعداد کاربران
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = $stmt->fetchColumn();
        
        // تعداد جلسات
        $stmt = $pdo->query("SELECT COUNT(*) FROM sessions");
        $stats['total_sessions'] = $stmt->fetchColumn();
        
        // تعداد کاربران فعال (آخرین 24 ساعت)
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stats['active_users_24h'] = $stmt->fetchColumn();
        
        // تعداد کمپین‌های فعال
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE campaign_started = 1");
        $stats['active_campaigns'] = $stmt->fetchColumn();
        
        return $stats;
    } catch (PDOException $e) {
        error_log("❌ Failed to get database stats: " . $e->getMessage());
        return false;
    }
}

/**
 * بهینه‌سازی جداول
 */
function optimizeTables() {
    global $pdo;
    try {
        $tables = ['users', 'sessions'];
        $results = [];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("OPTIMIZE TABLE $table");
            $result = $stmt->fetch();
            $results[$table] = $result;
            
            if ($result['Msg_type'] === 'status' && $result['Msg_text'] === 'OK') {
                error_log("✅ Table $table optimized successfully");
            } else {
                error_log("⚠️ Table $table optimization: " . $result['Msg_text']);
            }
        }
        
        return $results;
    } catch (PDOException $e) {
        error_log("❌ Table optimization failed: " . $e->getMessage());
        return false;
    }
}

/**
 * پاکسازی داده‌های قدیمی
 */
function cleanupOldData() {
    global $pdo;
    try {
        $cleaned = 0;
        
        // پاکسازی کاربران غیرفعال (بیش از 6 ماه بدون فعالیت و بدون دعوت)
        $stmt = $pdo->prepare("
            DELETE FROM users 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND id NOT IN (SELECT DISTINCT ref FROM users WHERE ref IS NOT NULL)
            AND (campaign_started = 0 OR campaign_started IS NULL)
        ");
        $stmt->execute();
        $cleaned += $stmt->rowCount();
        
        if ($cleaned > 0) {
            error_log("🧹 Cleaned up $cleaned inactive users");
        }
        
        return $cleaned;
    } catch (PDOException $e) {
        error_log("❌ Data cleanup failed: " . $e->getMessage());
        return false;
    }
}

// تست اتصال در زمان لود کردن فایل
if (!checkDatabaseConnection()) {
    error_log("⚠️ Database connection test failed on load");
}
?>