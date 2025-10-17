# Telegram Bot — کاپیتان تریدر

بات تلگرام فارسی برای مدیریت دوره رایگان، تمرین‌ها، پشتیبانی، سیستم معرفی (Referral) و کمپین فروش حرفه‌ای پس از اتمام دوره. این بات برای ایجاد تجربه کاربری شفاف، حرفه‌ای و پایدار طراحی شده و با PHP 7.4 و MySQL اجرا می‌شود.

## فهرست
- ویژگی‌ها
- پیش‌نیازها
- راه‌اندازی سریع
- ساختار پوشه‌ها
- فایل‌های کلیدی
- دیتابیس (Schema پیشنهادی)
- جریان‌های اصلی
- مدیریت کمپین‌ها
- وبهوک تلگرام
- کرون‌جاب‌ها
- لاگ‌ها و خطایابی
- نکات کیفیت و سازگاری
- عیب‌یابی تمرین‌ها (Session/Exercise)
- امنیت و مجوزها
- پرسش‌های متداول (FAQ)

---

## ویژگی‌ها
- ثبت‌نام کاربر با /start (با/بدون referral)
- احراز هویت با ارسال شماره تماس
- الزام عضویت در 2 کانال تلگرام و تأیید
- دوره رایگان مرحله‌ای با جلسات، فایل‌ها و تمرین‌ها
- محدودسازی دسترسی به جلسه بعد پس از تأیید تمرین جلسه قبل
- سیستم تمرین‌ها: ارسال، دریافت پاسخ، بررسی و تأیید/رد توسط ادمین
- سیستم معرفی: لینک اختصاصی، آمار، رتبه، بنر دعوت
- کمپین فروش خودکار بعد از اتمام دوره (campaign_messages.json)
- کمپین فعال‌سازی کاربران غیرفعال (30+ روز)
- پشتیبانی آنلاین با دکمه پاسخ سریع برای ادمین
- لاگ‌گیری و گزارش خطا به ادمین، نرخ‌دهی درخواست‌ها

## پیش‌نیازها
- PHP 7.4.x
  - ext-json, ext-mbstring, ext-curl (یا allow_url_fopen), PDO MySQL
- MySQL 5.7+/MariaDB
- تایم‌زون سیستم: Asia/Tehran
- یک دامنه/هاست با SSL (ترجیحاً) برای Webhook

## راه‌اندازی سریع
1) کلون/آپلود سورس روی هاست
2) تنظیم config.php:
   - BOT_TOKEN, BOT_USERNAME, ADMIN_ID, CHANNEL1, CHANNEL2
   - فعال‌سازی لاگ‌ها و timezone
3) ایجاد جداول دیتابیس (Schema پیشنهادی بخش پایین)
4) تست سلامت:
   - باز کردن test.php در مرورگر
   - انتظار خروجی Pass برای فایل‌ها، دیتابیس، توابع
5) تنظیم Webhook:
   - اجرای webhook_set.php یا دستی (بخش وبهوک)
6) ارسال /start به ربات و تکمیل مسیر: شماره تماس → عضویت کانال‌ها → منوی اصلی

## ساختار پوشه‌ها
```
.
├─ .htaccess
├─ admin.php
├─ admin_state.json
├─ backup.php
├─ btn_advanced.json
├─ btn_captain.json
├─ campaign.php
├─ campaign_messages.json
├─ campaign_monitor.php
├─ config.php
├─ db.php
├─ error.log
├─ error_log
├─ exercises.php
├─ functions.php
├─ inactive_campaign.php
├─ log_cleaner.php
├─ main.php
├─ referral.php
├─ security.php
├─ support_state.json
├─ test.php
└─ user.php
```

## فایل‌های کلیدی
- main.php
  - ورودی اصلی Webhook و هندل جریان پیام‌ها/کال‌بک‌ها
  - پشتیبانی از کرون‌جاب‌ها با پارامترهای GET: `?campaign_cron`, `?inactivity_cron`, `?inactive_campaign_cron`
- config.php
  - ثوابت (توکن، آدرس API، کانال‌ها، ADMIN_ID، تنظیمات کمپین و امنیت)
- db.php
  - اتصال PDO به MySQL (UTF8MB4)
- functions.php
  - توابع عمومی: sendMessage, sendFile, loadSessions, getUserById, saveUser, checkChannelMember, …
- user.php
  - منطق کاربر: منوها، دوره رایگان، تمرین‌ها، Referral، پشتیبانی
- admin.php
  - ابزار ادمین و کال‌بک‌ها (قبول/رد تمرین‌ها)
- exercises.php
  - منطق ارسال تمرین‌ها، دریافت پاسخ‌ها، canSeeNextSession
- campaign.php
  - کمپین فروش پس از اتمام دوره (eligibility, startCampaign, processCampaignNotifications)
- inactive_campaign.php
  - کمپین فعال‌سازی کاربران غیرفعال 30+ روز
- campaign_messages.json
  - پیکربندی پیام‌های کمپین (delay به ثانیه + contents آرایه پیام‌ها)
- support_state.json و admin_state.json
  - استیت‌های موقت برای پشتیبانی/ادمین
- test.php
  - تست سلامت: فایل‌ها، ثوابت، دیتابیس، توابع، API تلگرام

## دیتابیس (Schema پیشنهادی)
```sql
-- جدول کاربران
CREATE TABLE users (
  id BIGINT PRIMARY KEY,
  first_name VARCHAR(255),
  username VARCHAR(255),
  mobile VARCHAR(32),
  type ENUM('user','free','pls','pls_discount') DEFAULT 'user',
  ref BIGINT NULL,
  registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
  seen_sessions JSON NULL,
  exercises JSON NULL, -- { session_id(int): {status, answer, ts} }
  campaign_started TINYINT(1) DEFAULT 0,
  campaign_start_time DATETIME NULL,
  campaign_sent_steps JSON NULL,
  campaign_discount_code VARCHAR(64) NULL,
  inactive_campaign_started TINYINT(1) DEFAULT 0,
  inactive_campaign_start_time DATETIME NULL,
  course_completed TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- جدول جلسات
CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_number INT NOT NULL UNIQUE, -- 1..N
  title VARCHAR(255) NOT NULL UNIQUE,
  text MEDIUMTEXT NULL,
  exercise MEDIUMTEXT NULL,
  files JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ایندکس‌های کمکی
CREATE INDEX idx_users_ref ON users(ref);
CREATE INDEX idx_users_campaign_started ON users(campaign_started);
CREATE INDEX idx_users_last_activity ON users(last_activity);
```

نکته‌ها:
- ترتیب جلسات همیشه با `session_number` مشخص شود (نه ایندکس آرایه یا عنوان).
- کلیدهای `users.exercises` باید `session_id` عددی باشد و هنگام پردازش به `int` نرمال‌سازی شود.

## جریان‌های اصلی

### 1) شروع و ثبت‌نام
- /start [refId] → ثبت/به‌روزرسانی کاربر + handleReferralStart (در صورت وجود refId)
- اگر mobile ندارد → درخواست Contact
- پس از Contact → الزام عضویت در دو کانال و دکمه «✅ عضو شدم»
- پس از تأیید عضویت → نمایش منوی اصلی

### 2) دوره رایگان و جلسات
- انتخاب «🎓 ثبت‌نام دوره رایگان» → type='free' و نمایش لیست جلسات بر اساس session_number
- انتخاب جلسه:
  - canSeeNextSession(user_id, title) → فقط اگر تمرین جلسه قبلی accepted باشد
  - ثبت مشاهده با markSessionSeen
  - ارسال محتوای جلسه (متن + فایل‌ها)
  - اگر تمرین دارد → sendExercise(user, session_title)

### 3) تمرین‌ها
- handleExerciseAnswer(user_id, session_title, text):
  - نگاشت title → session_id (ایمن و یکپارچه)
  - ذخیره در users.exercises با کلید session_id (int)
  - status='pending' تا بررسی ادمین
- admin.php (Callbacks):
  - accept/reject/view با الگوی: `exercise_(accept|reject|view)_{sessionId}_{userId}`
  - اطلاع‌رسانی به کاربر پس از بررسی

### 4) Referral
- getReferralLink(user_id)
- getReferralStats, getUserReferralRank
- ارسال بنر دعوت (عکس + متن) و راهنمای انتشار

## مدیریت کمپین‌ها

### کمپین فروش پس از اتمام دوره
- Eligibility (نمونه‌ها):
  - `course_completed=1` یا
  - آخرین جلسه (getLastSessionNumber) در users.exercises با status='accepted' یا
  - تکمیل ≥90% جلسات (اختیاری)
- startCampaign(user_id):
  - تولید discount_code
  - ذخیره campaign_started=1, campaign_start_time, campaign_sent_steps=[]
  - ارسال مرحله 0 از campaign_messages.json
- processCampaignNotifications:
  - برای هر کاربر campaign_started=1:
    - اگر elapsed >= delay و step ارسال نشده → sendCampaignStep
    - جایگزینی {discount_code} در متن/کپشن
    - ثبت step در campaign_sent_steps

### کمپین فعال‌سازی کاربران غیرفعال
- isEligibleForInactiveCampaign(user_id):
  - نه inactive_campaign_started و نه campaign_started
  - دسترسی دوره رایگان بلامانع، دوره کامل نشده
  - last_activity ≥ 30 روز
- startInactiveCampaign:
  - پیام مقدمه شخصی‌سازی‌شده + شروع startCampaign
  - ثبت inactive_campaign_started=1 و گزارش به ADMIN_ID

## وبهوک تلگرام

### بررسی/تنظیم سریع
- اجرای اسکریپت‌های کمکی (در صورت وجود):  
  - webhook_check.php (اطلاعات Webhook فعلی)  
  - webhook_set.php (ست‌کردن آدرس)  
  - webhook_delete.php (حذف Webhook)  
  - get_updates.php (Polling تستی)

### تنظیم دستی با curl
```bash
# جایگزین کنید: <TOKEN> و <WEBHOOK_URL>
curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=<WEBHOOK_URL>" \
  -d "max_connections=100" \
  -d "drop_pending_updates=true"

# بررسی
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
```

نکته‌ها:
- <WEBHOOK_URL> باید به main.php اشاره کند.
- SSL معتبر توصیه می‌شود.

## کرون‌جاب‌ها (اختیاری/توصیه‌شده)
- اجرای پردازش کمپین‌ها:
```bash
# هر 5-15 دقیقه
*/10 * * * * curl -fsS "https://yourdomain/path/main.php?campaign_cron" -m 20 -o /dev/null
```
- یادآوری غیرفعالی (اگر پیاده‌سازی شده):
```bash
0 * * * * curl -fsS "https://yourdomain/path/main.php?inactivity_cron" -m 20 -o /dev/null
```
- کمپین غیرفعال‌ها:
```bash
30 2 * * * curl -fsS "https://yourdomain/path/main.php?inactive_campaign_cron" -m 60 -o /dev/null
```

## لاگ‌ها و خطایابی
- فایل‌ها:
  - error.log و error_log در روت پروژه
- تگ‌های پیشنهادی لاگ:
  - [MAIN], [USER], [EXERCISE], [CAMPAIGN], [BOT_DEBUG], [MAIN_FINAL]
- تست سلامت:
  - test.php → بررسی فایل‌ها/ثوابت/دیتابیس/توابع/API
- عیب‌یابی نگاشت جلسه/تمرین:
  - ابزار کمکی ex_diag.php (اختیاری): نمایش mapping جلسات، بررسی duplicate titles و گپ‌های session_number، کلیدهای exercises کاربر

## نکات کیفیت و سازگاری
- همه نمایش «شماره جلسه» باید از session_number خوانده شود (نه ایندکس آرایه).
- کلیدهای users.exercises حتماً `session_id` عددی باشد (int). هنگام خواندن/نوشتن نرمال‌سازی کنید.
- loadSessions باید بر اساس session_number مرتب کند.
- طول پیام‌ها < 4000 کاراکتر؛ کپشن‌ها مطابق محدودیت API.
- لحن پیام‌ها حرفه‌ای، غیرتهاجمی و شفاف (HTML سبک: b, i, code).

## عیب‌یابی تمرین‌ها (Session/Exercise)
مشکلات رایج:
- قاطی شدن جلسات 2/3:
  - mismatch بین `id` و `session_number`
  - استفاده از ایندکس آرایه به‌جای session_number
  - عنوان‌های تکراری/فاصله/ایموجی در title (normalize لازم)
  - کلیدهای exercises به‌صورت string ذخیره اما int خوانده می‌شود (یا برعکس)

رفع سریع:
- اطمینان از:
  - کلید exercises = session_id (int)
  - loadSessions → ORDER BY session_number ASC
  - تطبیق title → id با نرمال‌سازی فاصله‌ها
- ابزار ex_diag.php را اجرا کنید و خروجی را بررسی کنید.

## امنیت و مجوزها
- محافظت از اجرای مستقیم: در config.php → چک `BOT_ACCESS`
- عدم انتشار BOT_TOKEN در ریپازیتوری عمومی
- مجوز فایل‌ها:
  - JSONهای state با 0600
  - اسکریپت‌ها 0644
- محدودسازی نرخ درخواست‌ها (Rate Limit) برای کاربر و Callback
- ولیدیشن ورودی‌ها (طول متن‌ها، نوع داده‌ها)

## پرسش‌های متداول (FAQ)
- 500 Internal Server Error؟
  - لاگ error.log را بررسی کنید
  - SCRIPT_NAME را در ورودی‌های تست تنظیم کنید (test.php انجام می‌دهد)
  - نسخه PHP و اکستنشن‌ها را چک کنید
- پیام‌ها از تلگرام نمی‌رسد؟
  - getWebhookInfo را بررسی کنید
  - دسترسی main.php از اینترنت
  - SSL معتبر
- ترتیب جلسات به‌هم ریخته؟
  - جدول sessions: session_number یکتا و پیوسته
  - loadSessions با ORDER BY session_number
- کمپین ارسال نمی‌شود؟
  - campaign_started=1 و campaign_start_time معتبر
  - campaign_messages.json فرمت JSON معتبر
  - processCampaignNotifications (کرون/فراخوانی دستی) در حال اجراست

---

تهیه‌شده برای استقرار سریع و پایدار ربات آموزشی کاپیتان تریدر. در صورت نیاز به پشتیبانی یا توسعه بیشتر، ساختار کد ماژولار بوده و توسعه قابلیت‌ها به‌صورت ایمن امکان‌پذیر است.
