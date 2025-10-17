<?php
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

echo "<h2>📊 مانیتورینگ سیستم کمپین</h2>";

global $pdo;

// آمار کلی
$stats = [
    'total_users' => 0,
    'active_campaigns' => 0,
    'completed_campaigns' => 0,
    'pending_messages' => 0
];

$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$stats['total_users'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE campaign_started = 1");
$stats['active_campaigns'] = $stmt->fetchColumn();

// کمپین‌های فعال با جزئیات
$stmt = $pdo->query("
    SELECT id, first_name, campaign_start_time, campaign_sent_steps, campaign_discount_code 
    FROM users 
    WHERE campaign_started = 1 
    ORDER BY campaign_start_time DESC
");

echo "<h3>📈 آمار کلی:</h3>";
echo "<ul>";
echo "<li>کل کاربران: <b>{$stats['total_users']}</b></li>";
echo "<li>کمپین‌های فعال: <b>{$stats['active_campaigns']}</b></li>";
echo "</ul>";

if ($stats['active_campaigns'] > 0) {
    echo "<h3>🎯 کمپین‌های فعال:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>کاربر</th><th>شروع کمپین</th><th>مراحل ارسال شده</th><th>کد تخفیف</th></tr>";
    
    while ($row = $stmt->fetch()) {
        $sent_steps = json_decode($row['campaign_sent_steps'], true) ?: [];
        $steps_count = count($sent_steps);
        $start_time = date('m/d H:i', strtotime($row['campaign_start_time']));
        
        echo "<tr>";
        echo "<td>{$row['first_name']} ({$row['id']})</td>";
        echo "<td>$start_time</td>";
        echo "<td>$steps_count مرحله</td>";
        echo "<td>{$row['campaign_discount_code']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// نمایش آخرین لاگ‌ها
echo "<h3>📝 آخرین فعالیت‌ها:</h3>";
$log_content = file_get_contents('error.log');
$log_lines = explode("\n", $log_content);
$campaign_logs = array_filter($log_lines, function($line) {
    return strpos($line, 'Campaign') !== false;
});

$recent_logs = array_slice(array_reverse($campaign_logs), 0, 10);

echo "<div style='background: #f9f9f9; padding: 10px; max-height: 300px; overflow-y: scroll; font-family: monospace; font-size: 12px;'>";
foreach ($recent_logs as $log) {
    echo htmlspecialchars($log) . "<br>";
}
echo "</div>";

echo "<p><small>⏰ آخرین بروزرسانی: " . date('Y-m-d H:i:s') . "</small></p>";
?>