# معماری فنی Beta AI Search

## مرز مسئولیت‌ها
هسته افزونه مستقل از Elementor و ارائه‌دهنده AI است. WooCommerce منبع حقیقت محصول، قیمت و موجودی است. جدول‌های اختصاصی فقط سند عمومی قابل جستجو، بردار، صف کار و گزارش را نگه می‌دارند. هیچ SQL یا کدی از خروجی AI اجرا نمی‌شود. تحلیل تصویر، جستجوی کلمه‌ای جایگزین و نتیجه ساختگی در هسته وجود ندارد.

## مسیر یک جستجو
`Elementor widget / shortcode → REST → rate limit → normalized query + settings/index revision cache key → query-understanding provider → strict JSON validation → query embedding → cosine scan of active index in the WordPress database → explicit attribute/price gates → variation-to-parent grouping → live stock check → stock-first ranking → current-grid renderer`.

کل کاتالوگ منتشرشده بررسی می‌شود؛ دسته، فیلتر قبلی، صفحه جاری و کوئری اصلی وردپرس محدودیت اضافه نمی‌کنند. خطا پیش از commit مرورگر رخ می‌دهد و گرید قبلی دست‌نخورده می‌ماند. پاسخ نامعتبر AI موفق تلقی نمی‌شود.

## نسخه‌ها و وابستگی‌ها
حداقل طراحی: PHP 8.1، WordPress 6.6، WooCommerce 8.2؛ Elementor Free 3.5+ برای ویجت. Elementor Pro لازم نیست. Shortcode بدون Elementor نیز کار می‌کند. نسخه‌های واقعاً آزمون‌شده فقط در `TEST-REPORT.fa.md` درج می‌شوند؛ حداقل طراحی به معنی تضمین همه ترکیب‌ها نیست. API ویجت از `elementor/widgets/register` و `elementor/elements/categories_registered` استفاده می‌کند و کنترل‌های responsive از mechanism خود Elementor استفاده می‌کنند.

## اتصال واقعی به گرید
- **WooCommerce loop و Elementor Products / Archive Products:** المان `ul.products` موجود پیدا می‌شود. فقط محتوای آن با خروجی `wc_get_template_part('content','product')` و hookهای loop فعلی عوض می‌شود؛ wrapper، کلاس‌ها و قالب پوسته باقی می‌مانند. کارت تازه‌ای طراحی نمی‌شود.
- **Elementor Loop Grid (Pro):** یک descriptor امضاشده هنگام render ویجت تولید می‌شود و شناسه قالب Loop Item موجود را نگه می‌دارد. سمت سرور امضا و قالب بررسی می‌شود و همان قالب با `frontend->get_builder_content_for_display` و context محصول رندر می‌شود. container فعلی حفظ می‌شود. مرورگر نمی‌تواند شناسه قالب خصوصی دلخواه را به رندرر بسپارد. pagination هوشمند جدا از pagination اصلی مدیریت و هنگام reset حالت اولیه بازگردانده می‌شود. این مسیر به دلیل نبود لایسنس Pro در محیط توسعه، تا آزمون واقعی Pro «آزمون‌نشده» است؛ به‌ویژه CSS پویای per-item قالب‌های Loop باید روی نصب واقعی Pro بررسی شود.
- **Query ID:** برای ادغام‌های سفارشی hook رسمی `elementor/query/{$query_id}` پشتیبانی می‌شود، اما اتصال اصلی به آن وابسته نیست. در Pro 3.29+ انتخاب Product template ممکن است Query ID را مخفی کند؛ workaround رسمی گزارش‌شده Post layout + Products source است.
- **سایر گریدها:** رویداد DOM `beta-ai-search:updated`، رویداد jQuery `bas_grid_updated` و فیلتر `bas_current_grid_ids` برای اتصال اختصاصی فراهم است. سازگاری عمومی بدون تست ادعا نمی‌شود. گریدهای React/Store API که DOM را دوباره می‌سازند به adapter اختصاصی نیاز دارند.

## ایندکس و دو وظیفه مستقل AI
Providerها capability صریح `chat` و `embedding` دارند. انتخاب خودکار فقط از provider دارای کلید و مدل declared-capable استفاده می‌کند. پاسخ درک درخواست با confidence کمتر از `0.60` (فیلتر `bas_minimum_query_confidence`) به‌عنوان نبود نتیجه بسته می‌شود، نه جستجوی جایگزین. Claude/DeepSeek/Groq به‌صرف داشتن chat، embedding-capable فرض نمی‌شوند. OpenAI-compatible endpoint سفارشی فقط با capability اعلام‌شده مدیر وارد انتخاب خودکار می‌شود.

هر محصول ساده یک entity و هر variation یک entity مستقل دارد. سند شامل اطلاعات عمومی CRUD، taxonomyهای عمومی، ویژگی‌ها و فیلدهای سفارشی allowlistشده است. متادیتای protected/internal به‌صورت پیش‌فرض وارد AI نمی‌شود. ACF و افزونه جانبی از همان allowlist و hookهای عمومی عبور می‌کنند. بردارها با model/dimension/space نسخه‌بندی و به صورت float32 نرمال‌شده ذخیره می‌شوند. مقایسه بین مدل‌های ناسازگار ممنوع است.

صف محصول با رویدادهای CRUD، قیمت، موجودی، متای عمومی و variation تغییر می‌کند؛ متای protected با `_` برای جلوگیری از churn داخلی ووکامرس وارد صف نمی‌شود. پس از پایان ایندکس اولیه، تغییرات incremental بدون full scan پردازش می‌شوند. worker دارای lease_database، cursor، batch محدود، زمان بودجه‌شده، توقف/ادامه و retry است. WP-Cron رویداد کوتاه را اجرا می‌کند؛ پردازش به عمر تب مرورگر وابسته نیست. برای فروشگاه production باید system cron وردپرس را به scheduler واقعی متصل کرد. rebuild نسل جدید بردار را می‌سازد و تا پایان موفق، pointer ایندکس فعال عوض نمی‌شود.

## فرمول پذیرش
`g = global_flex / 100`؛ حداقل شباهت برداری `0.76 - 0.26*g` و حداقل امتیاز کل `0.70 - 0.15*g` است. امتیاز کل `0.65*semantic + 0.35*mean(explicit attribute scores)`؛ بدون ویژگی صریح، امتیاز معنایی ملاک است. هر ویژگی صریح باید آستانه `1 - 0.5*flex_i` را رد کند. مقدار ناشناخته مطابق فرض نمی‌شود. قیمت در محدوده امتیاز ۱ دارد؛ منطقه نرم `0.5*flex_price*bound` و acceptance آن حداکثر `0.25*flex_price*bound` فراتر از bound است. flex=100 به معنی پذیرش محصول نامرتبط نیست. این ضرایب اولیه و قابل آزمون‌اند، نه ادعای کیفیت کالیبره‌شده روی کاتالوگ مشتری.

واحد تومان/ریال صریحاً به واحد فروشگاه تبدیل می‌شود؛ نرخ ساختگی برای ارزهای دیگر استفاده نمی‌شود. موجودی هنگام جستجو از CRUD خوانده می‌شود: ابتدا موجودها به ترتیب score، سپس ناموجودها به ترتیب score. چند variation منطبق فقط یک کارت والد تولید می‌کنند.

## Cache، امنیت و حریم خصوصی
کلید cache شامل query نرمال‌شده، تنظیمات مؤثر، مدل‌های واقعی، endpoint مدل درک درخواست، نسخه ایندکس و واحد فروشگاه است. تغییر محصول/تنظیمات و همچنین آزاد شدن صف ایندکس نسخه را جلو می‌برد تا نتیجه‌ای که هنگام pending بودن محصول گرفته شده معتبر نماند. کلید API فقط سمت سرور و encrypted at rest نگهداری می‌شود؛ برای DB-only hardening ثابت `BAS_ENCRYPTION_KEY` توصیه می‌شود. مدیریت با `manage_woocommerce` (یا `manage_options`) + nonce؛ ورودی عمومی با schema، طول محدود، prepared statements و rate limit-hash پذیرفته می‌شود. IP خام، کلید، پاسخ خام provider و اطلاعات مشتری در گزارش ذخیره نمی‌شود. ثبت متن جستجو اختیاری و retention محدود است. هزینه فقط از usage واقعی و نرخ صریح مدیر محاسبه می‌شود؛ در نبود هرکدام `نامشخص` است.

## توسعه
Provider جدید: پیاده‌سازی `ProviderInterface`، معرفی capability/modelها و ثبت در registry. Adapter جدید: تشخیص container موجود، رندر قالب موجود و قرارداد restore/reinitialize. مستندات API و hookها در `docs/DEVELOPMENT.md` هستند.

## حذف و نگهداری داده‌ها
Deactivation هیچ داده‌ای را حذف نمی‌کند. `uninstall.php` فقط وقتی جدول‌ها/optionهای افزونه را حذف می‌کند که مدیر گزینه «حذف داده‌ها هنگام uninstall» را فعال کرده باشد. cronهای افزونه در هر دو حالت پاک می‌شوند. گزارش‌ها با retention روزانه پاک می‌شوند و مدل‌های قدیمی ایندکس پس از rebuild موفق در دسته‌های محدود حذف می‌شوند.
