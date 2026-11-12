# گزارش تست واقعی Beta AI Search — 2026-11-12

## خلاصه صادقانه

این گزارش فقط آزمون‌هایی را «اجراشده» می‌نامد که در همین محیط واقعاً اجرا شده‌اند. هسته افزونه به وردپرس/ووکامرس واقعی متصل شد، اما محیط توسعه یک sandbox است:

- WordPress: **7.1.4**
- WooCommerce: **11.3.0** (سورس آزمون؛ readme stable آن 11.2.2 بود)
- Elementor Free: **4.4.0**
- Theme: Hello Elementor **3.5.2**
- PHP: **8.5.12** از طریق php-wasm
- Database: **SQLite** با drop-in رسمی وردپرس، نه MySQL
- Browser: Chromium headless از طریق Playwright
- AI: **test double کنترل‌شده** برای endpoint آزمون؛ هیچ کلید واقعی provider ارائه نشده بود.

بنابراین این تست‌ها صحت pipeline، دیتابیس، ایندکس، کش، امنیت، رندر گرید و رفتار مرورگر را تأیید می‌کنند، اما **کیفیت معنایی/درک زبان مدل واقعی، صورت‌حساب واقعی provider، MySQL production و Elementor Pro دارای لایسنس را تأیید نمی‌کنند**. این موارد در بخش «نیازمند آزمون واقعی» جدا شده‌اند.

نتایج خودکار:

- `tests/integration/run.php`: **59 بررسی، 0 خطا**
- `tests/integration/browser.mjs`: **30 بررسی مرورگر، 0 خطا**
- مجموع: **89 بررسی خودکار موفق**
- PHP lint: **33 فایل** (29 فایل افزونه + 4 فایل harness) بدون syntax error
- JS check: `frontend.js`، `admin.js` و `browser.mjs` موفق

خام نتایج در `tests/results/` نگه داشته شده است. harness آزمون عمداً داخل ZIP production افزونه بسته‌بندی نمی‌شود.

## چک‌لیست ۲۵ ماده‌ای کاربر

| # | مورد | وضعیت | شواهد/توضیح |
|---|------|-------|--------------|
| 1 | نصب، فعال‌سازی و ساخت جدول‌های افزونه | اجراشده | فعال‌سازی در WP/WC واقعی؛ 8 جدول `wp_bas_*` تأیید شد. DB از نوع SQLite بود، نه MySQL. |
| 2 | ویجت Elementor Free و Pro | نسبتاً اجراشده | ویجت در Elementor Free ثبت و در صفحه واقعی رندر/جستجو شد؛ editor probe پنل و preview را بارگذاری کرد. drag & drop تعاملی کامل و Pro دارای لایسنس آزمون نشد. |
| 3 | درخواست فارسی، انگلیسی و محاوره | اجراشده با test double | «کفش راحت برای پیاده روی»، «یه کفش راحت میخوام واسه پیاده روی»، «comfortable walking shoes» در pipeline یکپارچه پاسخ مورد انتظار گرفتند. کیفیت مدل واقعی نیازمند کلید است. |
| 4 | تطبیق معنایی، نه فیلتر کلمه‌ای | اجراشده با بردار کنترل‌شده | بردارهای معنایی ساختگی آزمون، مسیر embedding→cosine→gate را تأیید کردند؛ هیچ fallback کلمه‌ای در خطا رخ نداد. کالیبراسیون مدل واقعی pending است. |
| 5 | انعطاف عمومی و هر ویژگی | اجراشده | فرمول آستانه‌ها، رد ویژگی صریح ناموجود حتی در flex=100، و سرریز قیمت ۵٪ در flex=20 تست شد. |
| 6 | محصول متغیر و variation مستقل | اجراشده | parent + دو variation ایندکس شد؛ رنگ/سایز/موجودی مستقل، ارث‌بری جنس والد، حذف variation و نمایش یک کارت والد تأیید شد. |
| 7 | ترتیب موجود سپس ناموجود | اجراشده | برای درخواست کفش مشکی، محصول موجود Adidas قبل از parent با variation مشکی ناموجود قرار گرفت. |
| 8 | همان گرید موجود، بدون refresh و بدون گرید موازی | اجراشده | در مرورگر، element گرید و URL ثابت ماند؛ تعداد `ul.products` تغییر نکرد؛ قالب کارت ووکامرس حفظ شد. |
| 9 | سازگاری چند گرید | اجراشده برای Woo؛ Pro/ثالث pending | صفحه دو گرید Woo با `scope=all` هم‌زمان به‌روزرسانی شد. Loop Grid/Products/Archive Products Pro و افزونه‌های ثالث تست واقعی نشدند. |
| 10 | به‌روزرسانی خودکار ایندکس | اجراشده برای CRUD/variation | تغییر قیمت پس از اتمام job اولیه، صف incremental و hash جدید ساخت. ایجاد/حذف variation صف والد و پاک‌سازی entity را تأیید کرد. ACF/افزونه ثالث واقعی pending است. |
| 11 | Pause / Resume / Full rebuild | اجراشده | pause پردازش را متوقف کرد؛ resume rebuild را کامل کرد؛ تغییر مدل embedding تنها پس از rebuild موفق فعال شد. |
| 12 | Cache، TTL و invalidation | اجراشده | cache hit بدون درخواست provider؛ تغییر تنظیمات cache را نامعتبر کرد؛ آزاد شدن صف ایندکس نیز revision را جلو می‌برد. |
| 13 | خطاهای API، کلید، quota، timeout، transport، پردازش | اجراشده با fault کنترل‌شده | HTTP 500/401/402/429، timeout، transport، پاسخ malformed و schema نامعتبر همگی خطای صریح دادند؛ نتیجه partial به‌عنوان success ارائه نشد. |
| 14 | Retry و حفظ گرید هنگام خطا | اجراشده | outage پیام خطا + retry نشان داد و محصولات تغییر نکردند؛ پس از رفع fault، retry موفق شد. |
| 15 | حذف فیلتر و بازگشت گرید | اجراشده | «حذف فیلتر» HTML/کلاس‌های اولیه و pagination اصلی را بازگرداند؛ × فقط متن را پاک کرد. |
| 16 | آمار، تاریخچه، مصرف و هزینه | اجراشده با usage آزمون | گزارش‌ها از token usage کنترل‌شده و نرخ ذخیره‌شده هزینه محاسبه کردند؛ نبود نرخ/usage «نامشخص» ماند. هزینه واقعی provider تأیید نشده است. |
| 17 | امنیت کلید، AJAX و دسترسی | اجراشده | کلید encrypted at rest و غایب از HTML/JS؛ admin REST ناشناس رد شد؛ AJAX بدون nonce رد شد؛ nonce+capability موفق بود؛ subscriber رد و shop_manager مجاز شد. |
| 18 | Rate limiting | اجراشده | سیزدهمین درخواست عمومی در پنجره آزمون 429 گرفت. |
| 19 | Responsive موبایل/تبلت/دسکتاپ | اجراشده | viewportهای 390، 768 و 1280 بدون overflow افقی، کنترل‌های قابل مشاهده و RTL بررسی شدند؛ UI انگلیسی LTR بود. |
| 20 | عدم نمایش محصول نامرتبط | اجراشده با gate کنترل‌شده | درخواست ویژگی فنی ناموجود و درخواست با confidence پایین نتیجه خالی داد. عدم نامرتبط در کاتالوگ واقعی نیازمند ارزیابی با کلید واقعی است. |
| 21 | عدم جایگزین/پیشنهاد خودکار در empty | اجراشده | پیام دقیق «محصولی مطابق درخواست شما پیدا نشد.» و نبود کارت جایگزین تأیید شد. |
| 22 | Providerهای متعدد و دو نقش مستقل | اجراشده در سطح registry | انتخاب خودکار embedding هرگز Anthropic chat-only را انتخاب نکرد؛ embedding دستی Anthropic رد شد؛ مدل query توانست مستقل از provider embedding فعال resolve شود. اتصال واقعی هر provider pending است. |
| 23 | امنیت SQL/XSS و عدم اجرای SQL هوش مصنوعی | نسبتاً اجراشده | schema نامعتبر شامل payload SQL رد شد؛ context امضاشده دستکاری‌شده رد شد؛ محصول hidden حتی با موجودی ناموجود رندر نشد؛prepared statements و escape در کد استفاده شده. penetration test مستقل انجام نشد. |
| 24 | کارایی background، lease و مقیاس‌پذیری | نسبتاً اجراشده | batch/lease/cursor/pause/resume و پردازش incremental تأیید شد؛ load test کاتالوگ بزرگ‌تر از 500 محصول و MySQL production انجام نشد. |
| 25 | مدیریت اتصال Woo/Elementor، مستندات و بسته‌بندی | اجراشده | نسخه‌ها در admin diagnostics نمایش داده می‌شوند؛ 12 تب تنظیمات در مرورگر تأیید شد؛ مستندات و ZIP تولید شدند. |

## آزمون‌های مرورگر کلیدی

صفحه‌های آزمون واقعی:

- `/search-test/` — shortcode بالای `[products]`
- `/elementor-test/` — ویجت ثبت‌شده Elementor Free بالای shortcode محصولات
- `/?product_cat=apparel` — آرشیو دسته با search box بالای loop
- `/pagination-test/` — گرید موجود با page size یک
- `/multi-grid-test/` — دو گرید موجود با `scope=all`

رفتارهای تأییدشده شامل عدم ارسال API هنگام تایپ، کلیک/Enter، حفظ همان DOM grid، حفظ کارت ووکامرس، reset، empty message دقیق، error/retry، pagination، چند گرید، RTL/LTR، responsive، cache REST، deny ناشناس، nonce admin و rate limit است.

## مواردی که هنوز نیازمند محیط/کلید واقعی هستند

1. **MySQL production**: جدول‌ها و کوئری‌ها در SQLite آزمون شدند. اگرچه SQL برای MySQL نوشته شده، رفتار نهایی dbDelta/charset/collation/lock باید روی MySQL واقعی تأیید شود.
2. **کلیدهای واقعی provider**: کیفیت درک فارسی محاوره/انگلیسی، کیفیت embedding، latency، quota، usage و هزینه واقعی باید با کلید manager فروشگاه سنجیده شود.
3. **Elementor Pro دارای لایسنس**: Loop Grid، Products و Archive Products، به‌ویژه CSS پویای per-item و pagination داخلی Pro.
4. **افزونه‌های ثالث/ACF واقعی**: allowlist و hookها وجود دارند، اما سازگاری با نصب واقعی ACF/brand/filter plugins بدون تست ادعا نشده است.
5. **Load test بزرگ‌تر از 500 محصول**: معماری صف/کلید/برداری برای رشد طراحی شده، اما benchmark کاتالوگ بزرگ در این sandbox انجام نشد.
6. **Drag & drop تعاملی در editor**: ویجت استاندارد Elementor ثبت شد و editor پنل/preview را بارگذاری کرد؛ آزمون کامل mouse drag & drop در editor به‌صورت خودکار تکمیل نشد.

## بازتولید harness آزمون

harness فقط برای سایت disposable است و نباید در production نصب شود:

```sh
# پیش‌نیاز: WP/Woo/Elementor فعال، سایت آزمون در .tools/wordpress-7
cp tests/integration/mock-ai.php .tools/wordpress-7/wp-content/mu-plugins/bas-test-ai.php
.tools/bin/php tests/integration/setup.php
.tools/bin/php tests/integration/run.php
node tests/integration/browser.mjs
```

`mock-ai.php` فقط یک endpoint آزمون مشخص را intercept می‌کند و پاسخ کنترل‌شده می‌دهد. این فایل برای تأیید صحت اتصال هسته به AI/DB/AJAX است، نه مدرک کیفیت مدل واقعی.
