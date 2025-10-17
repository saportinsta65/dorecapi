<?php
/**
 * فایل تست کامل برای تشخیص مشکل
 */

define('BOT_ACCESS', true);

// رفع مشکل SCRIPT_NAME
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/test.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "🔍 <b>Bot Debug Test Started</b>\n\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Memory: " . round(memory_get_usage() / 1024, 2) . " KB\n\n";

$tests = [];
$errors = [];

// تست 1: فایل‌های اصلی
echo "📂 Testing core files...\n";
$core_files = ['config.php', 'db.php', 'functions.php'];

foreach ($core_files as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists\n";
        try {
            require_once $file;
            echo "✅ $file loaded successfully\n";
            $tests[$file] = true;
        } catch (Exception $e) {
            echo "❌ $file error: " . $e->getMessage() . "\n";
            $errors[] = "$file: " . $e->getMessage();
            $tests[$file] = false;
        }
    } else {
        echo "❌ $file not found\n";
        $errors[] = "$file not found";
        $tests[$file] = false;
    }
}

echo "\n";

// تست 2: ثوابت مهم
echo "🔧 Testing constants...\n";
$constants = ['BOT_TOKEN', 'API_URL', 'ADMIN_ID', 'CHANNEL1', 'CHANNEL2'];

foreach ($constants as $const) {
    if (defined($const)) {
        echo "✅ $const defined\n";
        $tests[$const] = true;
    } else {
        echo "❌ $const not defined\n";
        $errors[] = "$const not defined";
        $tests[$const] = false;
    }
}

echo "\n";

// تست 3: دیتابیس
echo "🗄️ Testing database...\n";
try {
    if (isset($pdo)) {
        echo "✅ PDO object exists\n";
        
        $stmt = $pdo->query("SELECT 1");
        if ($stmt) {
            echo "✅ Database connection working\n";
            $tests['database'] = true;
        } else {
            echo "❌ Database query failed\n";
            $errors[] = "Database query failed";
            $tests['database'] = false;
        }
        
        // تست جداول
        $tables = ['users', 'sessions'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                echo "✅ Table $table exists ($count rows)\n";
                $tests["table_$table"] = true;
            } catch (Exception $e) {
                echo "❌ Table $table error: " . $e->getMessage() . "\n";
                $errors[] = "Table $table: " . $e->getMessage();
                $tests["table_$table"] = false;
            }
        }
        
    } else {
        echo "❌ PDO object not found\n";
        $errors[] = "PDO object not found";
        $tests['database'] = false;
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    $errors[] = "Database: " . $e->getMessage();
    $tests['database'] = false;
}

echo "\n";

// تست 4: توابع مهم
echo "⚙️ Testing functions...\n";
$functions = ['sendMessage', 'loadUsers', 'getUserById', 'saveUser', 'loadSessions'];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✅ Function $func exists\n";
        $tests["func_$func"] = true;
    } else {
        echo "❌ Function $func not found\n";
        $errors[] = "Function $func not found";
        $tests["func_$func"] = false;
    }
}

echo "\n";

// تست 5: فایل‌های اختیاری
echo "📁 Testing optional files...\n";
$optional_files = ['admin.php', 'user.php', 'exercises.php', 'campaign.php', 'referral.php', 'inactive_campaign.php'];

foreach ($optional_files as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists\n";
        try {
            require_once $file;
            echo "✅ $file loaded successfully\n";
            $tests[$file] = true;
        } catch (Exception $e) {
            echo "❌ $file error: " . $e->getMessage() . "\n";
            $errors[] = "$file: " . $e->getMessage();
            $tests[$file] = false;
        }
    } else {
        echo "⚠️ $file not found (optional)\n";
        $tests[$file] = 'optional';
    }
}

echo "\n";

// تست 6: تست API ساده
echo "🌐 Testing Telegram API...\n";
if (function_exists('sendMessage') && defined('ADMIN_ID')) {
    try {
        // تست ساده بدون ارسال واقعی
        $test_url = API_URL . "getMe";
        $response = @file_get_contents($test_url);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['ok']) {
                echo "✅ Telegram API connection working\n";
                echo "✅ Bot username: " . ($data['result']['username'] ?? 'unknown') . "\n";
                $tests['telegram_api'] = true;
            } else {
                echo "❌ Telegram API returned error\n";
                $errors[] = "Telegram API error";
                $tests['telegram_api'] = false;
            }
        } else {
            echo "❌ Cannot connect to Telegram API\n";
            $errors[] = "Cannot connect to Telegram API";
            $tests['telegram_api'] = false;
        }
    } catch (Exception $e) {
        echo "❌ API test error: " . $e->getMessage() . "\n";
        $errors[] = "API test: " . $e->getMessage();
        $tests['telegram_api'] = false;
    }
} else {
    echo "⚠️ Cannot test API (missing functions)\n";
    $tests['telegram_api'] = 'skipped';
}

echo "\n";

// خلاصه نتایج
echo "📊 <b>Test Summary</b>\n";
echo "======================\n";

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($tests as $test => $result) {
    if ($result === true) {
        $passed++;
    } elseif ($result === false) {
        $failed++;
    } else {
        $skipped++;
    }
}

echo "✅ Passed: $passed\n";
echo "❌ Failed: $failed\n";
echo "⚠️ Skipped: $skipped\n\n";

if (!empty($errors)) {
    echo "🚨 <b>Errors Found:</b>\n";
    foreach ($errors as $i => $error) {
        echo ($i + 1) . ". $error\n";
    }
    echo "\n";
}

// توصیه‌های رفع مشکل
if ($failed > 0) {
    echo "🔧 <b>Recommendations:</b>\n";
    
    if (!$tests['config.php']) {
        echo "1. Check config.php file and fix SCRIPT_NAME issue\n";
    }
    
    if (!$tests['database']) {
        echo "2. Check database connection settings in db.php\n";
    }
    
    if (!$tests['functions.php']) {
        echo "3. Check functions.php for syntax errors\n";
    }
    
    echo "\n";
}

echo "🏁 Test completed at " . date('H:i:s') . "\n";
echo "Memory used: " . round(memory_get_usage() / 1024, 2) . " KB\n";

if ($failed == 0) {
    echo "\n🎉 All critical tests passed! Bot should work fine.\n";
} else {
    echo "\n⚠️ Some tests failed. Please fix the errors above.\n";
}
?>