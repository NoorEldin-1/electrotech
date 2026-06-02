# خطة تنفيذ متطلبات "الإدارة المالية" (Financial Department Work Flow)

> وثيقة تحليل وتخطيط فقط — **لا يوجد تنفيذ كود في هذه المرحلة**.
> المرجع: ملف المتطلبات `PDFs/Financial_Department.md` (4 سلايدات: 2،3،4،5) + تحليل المشروع الفعلي (Laravel 12 + Filament 3.3).
> التتمّة الطبيعية لـ `Inventory_Department_Plan.md` (الذي نُفّذ بالفعل: كشوف حساب الموردين/العملاء = دفتر مساعد Subsidiary Ledger). هذه الخطة تبني **دفتر الأستاذ العام General Ledger** فوقه.

---

## 0. منهجية التحليل

1. **فك ترميز ملف المتطلبات**: الملف على القرص UTF-8 عربي سليم (الـ mojibake ظهر فقط في طريقة تمرير النص للمحادثة). قُرئ سلايد بسلايد.
2. **قراءة الكود الفعلي** في الطبقات:
   - **Domain المالي الحالي**: `AccountEntry` (model + migration)، `AccountDirection` (enum)، `AccountStatementService`، `AccountEntryResource` + `AccountEntriesRelationManager` + `AccountEntryPolicy`.
   - **مصادر القيود الحالية**: `AdditionVoucherService::post` (يكتب قيد دائن للمورد)، `DeliveryVoucherService::activateIfFullyApproved` (يكتب قيد مدين للعميل).
   - **RBAC**: `RoleAndPermissionSeeder` (دور `Finance` موجود)، نمط `*Policy`.
   - **i18n/Theme**: `lang/{en,ar}/{resources,navigation,errors}.php` + `lang/ar.json`، `AdminPanelProvider` (مجموعة `finance` موجودة + خط Cairo + dark/light + RTL تلقائي).
   - **Tests**: `tests/Feature/Inventory/AccountStatementTest.php` (نمط الكشف + الرصيد المتحرك)، `tests/Feature/Sales/*` (نمط RBAC/i18n).
3. **مقارنة دقيقة** بين ما يطلبه الملف وما هو موجود فعلاً.

### الخلاصة المفاهيمية المبكّرة (مهمة جداً)
الموجود اليوم (`AccountEntry`) هو **دفتر أستاذ مساعد للأطراف فقط** (مورد/عميل)، يُكتب تلقائياً من الأذون، للقراءة فقط. أما الملف فيطلب **دفتر أستاذ عام كامل بالقيد المزدوج**:

> `قيود اليومية (يدوية) → ترحيل تلقائي → دفتر أستاذ لكل حساب (رصيد افتتاحي + رصيد متحرك) → ميزان مراجعة` فوق **شجرة حسابات (Chart of Accounts)** تشمل حسابات ليست أطرافاً (خزينة، بنوك بعملات، مصروفات، إيرادات، أصول ثابتة…).

هذان نظامان مترابطان لكن مختلفان: المطلوب بناء GL **بجوار** الدفتر المساعد الحالي مع **ربطهما** (حسابات مراقبة Control Accounts).

---

## 1. تفكيك المتطلبات (ماذا يطلب الملف بالضبط)

| السلايد | العنوان | المتطلب |
|---|---|---|
| **2** | قيود اليومية (Journal Entries) | إدخال **قيد مزدوج يدوي**: طرف **مدين** (اسم حساب + قيمة) + طرف **دائن** (اسم حساب + قيمة) + **البيان** + **رقم المستند** + **التاريخ** + **م (تسلسل)**. واختيار اسم الحساب من قائمة (سهم = dropdown). |
| **2** | أنواع المستند الثلاثة | **رقم المستند له 3 ألوان/أنواع**: ⚫ أسود = **أمر صرف** (نقد خارج)، 🔴 أحمر = **إيصال توريد** (نقد داخل)، 🟢 أخضر = **قيد تسوية** (تسوية/تصحيح). |
| **3** | دفتر الأستاذ (ح/الخزينة) | لكل حساب دفتر أستاذ: أعمدة **الرصيد \| دائن \| مدين \| البيان \| رقم المستند \| التاريخ \| م**، يبدأ بصف **رصيد أول المدة** وينتهي بصف **الإجمالي**. و**الترحيل تلقائي**: كل قيد يُرحَّل لحسابه حسب **طبيعته الدائنة/المدينة**. |
| **4** | ميزان المراجعة (Trial Balance) | تجميع **كل الحسابات** في جدول: **الرصيد \| دائن \| مدين \| اسم الحساب \| م** — **الإجماليات فقط** (إجمالي مدين، إجمالي دائن، الرصيد لكل حساب). مثال: الخزينة (مدين 2000، دائن 1000، رصيد 1000)؛ بنك التجاري دولار (مدين 5000، دائن 4500، رصيد 500). |
| **5** | أسماء الحسابات (Chart of Accounts) | قائمة حسابات النظام (شجرة الحسابات). أمثلة من الملف مصنّفة أدناه. |

### 1.1 شجرة الحسابات المستخرجة من السلايد 5 (مع التصنيف المقترح)
> العملة: ج = جنيه (EGP)، دولار = USD، يورو = EUR.

| الحساب | النوع المقترح (AccountType) | الطبيعة (AccountDirection) | العملة |
|---|---|---|---|
| الخزينة | أصل (asset) | مدين | EGP |
| خزينة أجنبي | أصل | مدين | USD/EUR |
| شيكات بالخزينة / شيكات تحت التحصيل / شيكات ضمان | أصل | مدين | EGP |
| بنك التجاري (ج/دولار/يورو)، بنك وفا (ج/دولار/يورو)، بنك البركة (ج/دولار)، بنك أبو ظبي الأول (ج/دولار/يورو)، بنك عودة، بنك الأهلي(؟ «الأعلى») | أصل | مدين | حسب العملة |
| مدينون / العملاء | أصل (مراقبة AR) | مدين | EGP |
| مخزون | أصل | مدين | EGP |
| أصول ثابتة | أصل | مدين | EGP |
| اعتمادات / اعتمادات تم إقفالها | أصل أو التزام (حسب السياسة) | — | — |
| ا.ت.ص من المنبع / ا.ت.ص للغير | أصل/التزام ضريبي | — | EGP |
| مورد محلي / مورد خارجي / دائنون | التزام (مراقبة AP) | دائن | EGP |
| أوراق دفع | التزام | دائن | EGP |
| مصلحة الضرائب / صندوق الجزاءات | التزام | دائن | EGP |
| غطاء خطابات ضمان / غطاء شيكات ضمان | التزام | دائن | EGP |
| تسهيلات أبو ظبي | التزام | دائن | EGP |
| المبيعات | إيراد (revenue) | دائن | EGP |
| إيرادات متنوعة / فوائد دائنة / فروق عملة (دائنة) | إيراد | دائن | EGP |
| م. تشغيل / م. تركيب / م. عمومية / م. تمويلية / م. تصدير | مصروف (expense) | مدين | EGP |
| ق.م (قيمة مضافة؟) | التزام/أصل ضريبي | — | EGP |
| صافي الربح | حقوق ملكية (equity) | دائن | EGP |

> **ملاحظة**: التصنيف أعلاه **مقترح أولي** يحتاج مراجعة محاسب الشركة قبل الـ seeding (انظر قرار التصميم رقم 4). بعض البنود (اعتمادات، ا.ت.ص، ق.م) تحتاج تأكيد طبيعتها.

---

## 2. الوضع الحالي في المشروع (الحقائق الفعلية من الكود)

### 2.1 الموجود في الطبقة المالية
- **`app/Models/AccountEntry.php`** — قيد مفرد في دفتر **طرف** (Supplier/Customer) عبر `morphs('party')`. حقول: `party, entry_date, direction, amount (موقّع), reference (morph للسند), operation_name, notes, created_by`. **Append-only** (لا تُحرّر).
- **`app/Enums/AccountDirection.php`** — `Debit/Credit` + `HasLabel` + `HasColor` (debit=danger، credit=success). **قابل لإعادة الاستخدام مباشرة في الـ GL.**
- **`app/Services/AccountStatementService.php`** — `for($party)` يرجع القيود مرتبة + **`running_balance`** محسوب في PHP (محمول MySQL/SQLite)؛ `balanceFor($party)` = `SUM(amount)`. **نمط الرصيد المتحرك جاهز لإعادة الاستخدام.**
- **`app/Filament/Resources/AccountEntryResource.php`** — جدول **للقراءة فقط** (`canCreate=false`) في مجموعة `finance`، فلاتر (نوع الطرف/الاتجاه/التاريخ). **نمط شاشة الدفتر جاهز للمحاكاة.**
- **`app/Filament/RelationManagers/AccountEntriesRelationManager.php`** — كشف الحساب داخل المورد/العميل، يحسب `running_balance` عبر `SUM(amount) OVER (ORDER BY ...)` (نافذة SQL).
- **`app/Policies/AccountEntryPolicy.php`** — `viewAny = can('supplier_statements.view') || can('customer_statements.view')`؛ create/update/delete = false.
- **مصادر القيود الحالية (تلقائية فقط)**:
  - `AdditionVoucherService::post()` → `AccountEntry` **دائن** للمورد بقيمة الفاتورة.
  - `DeliveryVoucherService::activateIfFullyApproved()` → `AccountEntry` **مدين** للعميل بقيمة التسليم (بعد الاعتماد المزدوج).

### 2.2 RBAC (المرجع للنمط)
- الصلاحيات بصيغة منقّطة `resource.action`، **المصدر الوحيد** `RoleAndPermissionSeeder::getPermissions()` (آمن لكل deploy: يضيف الجديد، يحذف اليتيم، يمنح Admin الكل).
- **دور `Finance` موجود بالفعل** لكنه حالياً محدود: `delivery_vouchers.approve_financial`, `addition_vouchers.post`, `supplier_statements.view`, `customer_statements.view`, `inventory.view_pricing`, عرض… — **لا يملك أي صلاحية GL** (لا قيود يومية ولا شجرة حسابات ولا ميزان مراجعة).
- Policies تُكتشف تلقائياً (`App\Models\X` → `App\Policies\XPolicy`).

### 2.3 i18n / RTL / Theme
- لغتان `en/ar` عبر `filament-language-switch`. مفاتيح منظمة في `lang/{en,ar}/resources.php` (كتل: `label, plural_label, navigation_label, sections, fields, columns, filters` + قسم `enums.<name>`) و`navigation.php` (المجموعات) و`ar.json` (جمل عامة).
- **مجموعة `finance` موجودة** في `navigation.php` (en=Finance / ar=الإدارة المالية) ومسجّلة في `AdminPanelProvider::navigationGroups()`. **جاهزة لاستقبال موارد GL الجديدة.**
- **RTL تلقائي** (ar=rtl)، خط Cairo. **Dark/Light** مفعّلان افتراضياً → أي مورد جديد يرثهما طالما يستخدم `__()` وألوان Filament السيمانتية فقط.

### 2.4 الاختبارات
- `phpunit.xml`: sqlite `:memory:`، `CACHE_STORE=array`، `QUEUE_CONNECTION=sync`.
- النمط: `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` + Factories + `assignRole` + تأكيدات.
- موجود `tests/Feature/Inventory/AccountStatementTest.php` (كشف + رصيد متحرك + RBAC للـ Finance).

### 2.5 ما هو غير موجود إطلاقاً (الفجوة الكبرى)
- ❌ لا يوجد موديل **`Account` (شجرة حسابات)** — الحسابات الحالية ضمنية في الأطراف فقط.
- ❌ لا يوجد **`JournalEntry` / `JournalEntryLine`** (قيد يومية مزدوج يدوي).
- ❌ لا يوجد **نوع مستند (أمر صرف/إيصال توريد/قيد تسوية)** ولا ترقيم/تلوين له.
- ❌ لا يوجد **دفتر أستاذ عام لكل حساب** (مع رصيد افتتاحي + إجمالي).
- ❌ لا يوجد **ميزان مراجعة**.
- ❌ لا يوجد **`app/Filament/Pages/`** أصلاً (الميزان سيكون أول Filament Page مخصّصة — لكن `AdminPanelProvider` يستدعي `discoverPages` بالفعل فالبنية جاهزة).
- ❌ لا يوجد **رصيد افتتاحي/قيد افتتاحي** لأي حساب.
- ❌ لا يوجد **تعدد عملات** فعلي (الأذون كلها EGP ثابتة في `->money('EGP')`).

---

## 3. جدول المقارنة النهائي (متطلب → الحالة → الإجراء)

الرموز: ✅ موجود ومطابق — 🟡 موجود لكن يحتاج تعديل/توسيع — ❌ غير موجود (بناء جديد).

| # | المتطلب في الملف | الحالة | التفصيل والإجراء المطلوب |
|---|---|---|---|
| 1 | مفهوم الاتجاه مدين/دائن | ✅ | `AccountDirection` enum جاهز (label+color). يُعاد استخدامه كما هو في الـ GL. |
| 2 | منطق الرصيد المتحرك | ✅ | `AccountStatementService` + نافذة SQL في الـ RelationManager. يُعمّم لدفتر الأستاذ العام. |
| 3 | شاشة دفتر للقراءة فقط + فلاتر | ✅ | نمط `AccountEntryResource` يُحاكى لدفتر الأستاذ العام. |
| 4 | دور مالي + مجموعة تنقل مالية | ✅ | دور `Finance` + مجموعة `finance` موجودان (يُضاف لهما صلاحيات/موارد GL). |
| 5 | **شجرة الحسابات (أسماء الحسابات)** سلايد 5 | ❌ | لا يوجد موديل `Account`. يُنشأ `accounts` + enum `AccountType` + `ChartOfAccountsSeeder` بالحسابات المذكورة. |
| 6 | **قيود اليومية المزدوجة اليدوية** سلايد 2 | ❌ | لا يوجد. يُنشأ `JournalEntry` + `JournalEntryLine` + شاشة إدخال (Repeater سطور) + قاعدة توازن مدين=دائن. |
| 7 | **أنواع المستند الثلاثة الملوّنة** سلايد 2 | ❌ | يُنشأ enum `DocumentType` (payment_order/أسود، supply_receipt/أحمر، settlement/أخضر) + `HasColor` + ترقيم مستقل لكل نوع. |
| 8 | **الترحيل التلقائي حسب طبيعة الحساب** سلايد 3 | ❌ | يُنشأ `JournalPostingService`: عند ترحيل القيد تُصبح سطوره postings في دفاتر حساباتها (نهج: السطور نفسها هي الترحيل — انظر القرار 2). |
| 9 | **دفتر أستاذ لكل حساب** (رصيد افتتاحي + إجمالي) سلايد 3 | 🟡 | منطق الرصيد المتحرك موجود لكن للأطراف فقط. يُعمّم عبر `GeneralLedgerService::for(Account)` + شاشة كشف الحساب مع **رصيد أول المدة** و**الإجمالي**. |
| 10 | **ميزان المراجعة** سلايد 4 | ❌ | يُنشأ `TrialBalanceService` + **Filament Page** `TrialBalance` (تجميع: إجمالي مدين/دائن/الرصيد لكل حساب). |
| 11 | **الرصيد الافتتاحي (رصيد أول المدة)** | ❌ | يُضاف `opening_balance` + `opening_balance_date` على `accounts` (أو قيد افتتاحي). |
| 12 | **تعدد العملات** (دولار/يورو/جنيه) | 🟡 | الأذون EGP ثابتة. يُضاف `currency` على `Account`؛ الميزان يُجمَّع **لكل عملة** (تحويل FX خارج النطاق الأولي — انظر القرار 5). |
| 13 | **ربط الدفتر المساعد بالـ GL** (العملاء/الموردون/المبيعات/المخزون كحسابات مراقبة) | 🟡 | `AccountEntry` (مورد/عميل) موجود. يُضاف جسر اختياري: ترحيل الأذون يولّد **قيد GL** على حسابات المراقبة (انظر المرحلة 5 + القرار 3). |
| 14 | حماية القيود المرحّلة من التعديل | 🟡 | نمط append-only مطبّق في `AccountEntry`. يُطبّق نفسه: القيد **المرحّل** غير قابل للتعديل/الحذف (مسودة فقط قابلة للتحرير). |

---

## 4. القرارات التصميمية الرئيسية (تحتاج اعتمادك قبل البدء)

> الافتراض الموصى به مذكور لكل بند.

1. **بناء GL مستقل بجوار الدفتر المساعد** (لا دمج/هدم لـ `AccountEntry`).
   - *موصى به*: نعم. `AccountEntry` = دفتر مساعد للأطراف (مدفوع بالأذون)؛ الـ GL = دفتر عام (مدفوع بقيود يومية يدوية). الربط عبر حسابات مراقبة (القرار 3).
2. **السطور هي الترحيل (لا جدول postings منفصل)**.
   - *موصى به*: نعم — `journal_entry_lines` هي نفسها الترحيلات؛ دفتر الأستاذ والميزان **يُحسبان كـ Views** (في PHP/SQL، محمول) مثل `AccountStatementService`. أبسط ومصدر حقيقة واحد.
   - *البديل*: جدول `ledger_entries` مفصول (نمط `AccountEntry`) — أكثر تكراراً، مرفوض حالياً إلا لو لزم الأداء على ملايين السطور.
3. **نطاق ربط الأذون بالـ GL**:
   - *موصى به (مرحلة لاحقة 5)*: عند ترحيل إذن الإضافة/تفعيل إذن التسليم، يُولَّد **قيد GL** مزدوج تلقائياً على حسابات مراقبة قابلة للتهيئة (مثل: Dr مخزون / Cr موردون؛ Dr عملاء / Cr مبيعات). **قابل للإيقاف** عبر config لتفادي الازدواج لو أُدخلت القيود يدوياً.
   - *البديل المبدئي*: الإبقاء على فصل تام (GL يدوي بالكامل) في المراحل 0–4، وتأجيل الجسر.
4. **مصدر شجرة الحسابات**: `ChartOfAccountsSeeder` يُبذَر بالحسابات في السلايد 5 **بعد مراجعة المحاسب** للتصنيف/العملة/الطبيعة. (الكود seeder + إمكانية إضافة/تعطيل حسابات من الـ UI.)
5. **تعدد العملات**: `Account.currency` فقط في البداية؛ الميزان يُعرض **مجمّعاً لكل عملة** بدون تحويل. تحويل FX وفروق العملة = خارج النطاق الأولي (يوثّق كمرحلة لاحقة، مثل تأجيل FIFO في خطة المخزون).
6. **الترقيم وأسماء الحسابات ثنائية اللغة**: 
   - `Account.name` (عربي أساسي كبيانات نطاق) + `Account.name_en` اختياري للعرض الإنجليزي.
   - `Account.code` (كود حساب اختياري فريد) لدعم الترتيب والترحيل المستقبلي.
7. **التحرير مقابل الترحيل**: القيد له حالتان `draft` (قابل للتحرير/الحذف، لا يظهر في الدفتر/الميزان) و`posted` (مرحّل، مقفول، يظهر). زر "ترحيل" يطبّق قاعدة التوازن.

---

## 5. خطة التنفيذ المفصّلة بالمراحل

كل مرحلة **قابلة للشحن والاختبار منفصلة**، وتُراعي التسلسل: DB → Model → Enum → Service → Filament → RBAC → i18n → Theme/RTL → Tests → **الربط مع الأجزاء الأخرى**.

### المرحلة 0 — شجرة الحسابات (Chart of Accounts) — سلايد 5
**DB / Migration** `create_accounts_table`:
- `id, code (string nullable, unique), name (string), name_en (string nullable), type (string → AccountType), nature (string → AccountDirection), currency (string, default 'EGP'), parent_id (nullable self-FK للتجميع), opening_balance (decimal 14,2 default 0), opening_balance_date (date nullable), is_active (bool default true), notes (text nullable), timestamps, softDeletes`.
- فهارس: `unique(code)`, `index(type)`, `index(parent_id)`.

**Enum** `app/Enums/AccountType.php` (`asset, liability, equity, revenue, expense`) — `implements HasLabel, HasColor` بنمط `TransactionType`:
- ألوان سيمانتية: asset=info، liability=warning، equity=primary، revenue=success، expense=danger.

**Model** `app/Models/Account.php`:
- `casts`: `type→AccountType`, `nature→AccountDirection`, `opening_balance→decimal:2`.
- علاقات: `parent()/children()` (self)، `journalLines()` (hasMany `JournalEntryLine`)، اختياري `party()` (morphTo لو رُبط بمورد/عميل لاحقاً)، `LogsActivity` (نمط الموديلات الحالية).
- توابع مساعدة: `naturalSign()` (مدين=+1، دائن=−1) لاحتساب الرصيد حسب الطبيعة.

**Seeder** `database/seeders/ChartOfAccountsSeeder.php`:
- يبذر الحسابات من جدول 1.1 (idempotent: `updateOrCreate` بالـ code/name). يُسجّل في `DatabaseSeeder` ويُذكر في تعليمات النشر.

**Filament** `AccountResource` (مجموعة `finance`، `navigationSort` ~61 بعد `AccountEntryResource=60`):
- جدول: code, name (+name_en toggle), type (badge), nature (badge), currency, opening_balance (مخفي عمّن لا يملك عرض المالية)، is_active (toggle).
- نموذج CRUD: الحقول أعلاه + اختيار `parent_id` + `type`/`nature`/`currency` كـ Select من الـ enums.
- فلاتر: type, nature, currency, is_active.

**RBAC**: `accounts.view, accounts.create, accounts.edit, accounts.delete`.
**i18n**: كتلة `resources.accounts.*` (en+ar) + `enums.account_type.*` (en+ar).
**Theme/RTL**: badges بألوان سيمانتية فقط.
**Tests** `tests/Feature/Finance/ChartOfAccountsTest.php`: الـ seeder يبذر الحسابات؛ CRUD + RBAC؛ تفرّد الـ code.

**الربط**: لا شيء يُكسر (بناء جديد بالكامل). تجهيز حسابات المراقبة (العملاء/الموردون/المبيعات/المخزون) للمرحلة 5.

---

### المرحلة 1 — قيود اليومية المزدوجة + أنواع المستند — سلايد 2
**DB / Migrations**:
- `create_journal_entries_table`: `id, entry_number (string unique — التسلسل م), document_number (string), document_type (string → DocumentType), entry_date (date), description (string — البيان), status (string → JournalStatus: draft/posted), total_debit (decimal 14,2 default 0), total_credit (decimal 14,2 default 0), currency (string default EGP), posted_by (nullable FK users), posted_at (nullable), created_by (nullable FK users), notes (text nullable), timestamps`.
  - فهارس: `unique(entry_number)`, `index(entry_date)`, `index(document_type)`, `index(status)`.
- `create_journal_entry_lines_table`: `id, journal_entry_id (FK cascade), account_id (FK restrict), direction (string → AccountDirection), amount (decimal 14,2 موجبة), line_notes (string nullable), timestamps`.
  - فهارس: `index(journal_entry_id)`, `index(account_id)`, `index(['account_id','journal_entry_id'])`.

**Enums**:
- `app/Enums/DocumentType.php` (`payment_order` أمر صرف، `supply_receipt` إيصال توريد، `settlement` قيد تسوية) — `HasLabel, HasColor`:
  - payment_order ⚫ → `'gray'`، supply_receipt 🔴 → `'danger'`، settlement 🟢 → `'success'` (مطابقة ألوان السلايد، سيمانتية لتعمل في dark/light).
  - تابع `prefix()` للترقيم: مثلاً PV / RV / JV.
- `app/Enums/JournalStatus.php` (`draft, posted`) — `HasLabel, HasColor` (draft=gray، posted=success).

**Models**: `JournalEntry` (+ `lines()` hasMany، `account` عبر السطور، `createdBy/postedBy`، `LogsActivity`)، `JournalEntryLine` (+ `journalEntry()`, `account()`).

**Service** `app/Services/JournalEntryService.php`:
- `generateEntryNumber(DocumentType $type)`: نمط `{PREFIX}-YYYYMM-####` بقفل (نفس نمط `generatePoNumber`/`generateWoNumber` — Redis lock + `MAX(CAST)`)، تسلسل **مستقل لكل نوع مستند** (الألوان الثلاثة).
- `post(JournalEntry $entry)`: 
  1. تحقّق `status==draft` (idempotent، رمي خطأ لو مرحّل — رسالة في `errors.php`).
  2. تحقّق وجود ≥ سطرين و**التوازن**: `SUM(debit.amount) == SUM(credit.amount)` (وإلا خطأ `errors.journal.unbalanced`).
  3. تحقّق توافق العملة (كل السطور بعملة القيد، أو حسابات بنفس العملة — حسب القرار 5).
  4. تحديث `total_debit/total_credit/status=posted/posted_by/posted_at` داخل `DB::transaction`.
- (السطور **هي** الترحيل — لا كتابة في جدول آخر، القرار 2.)

**Filament** `JournalEntryResource` (مجموعة `finance`):
- **جدول**: entry_date, entry_number, document_number, document_type (badge ملوّن), description, total_debit, total_credit, status (badge). فلاتر: document_type, status, نطاق تاريخ (نمط `AccountEntryResource`).
- **نموذج الإنشاء/التحرير** (مسموح فقط لـ draft):
  - حقول الرأس: document_type (Select)، document_number، entry_date، description (البيان)، currency.
  - **Repeater سطور**: `account_id` (Select بحث من `accounts` النشطة، يعرض code+name)، `direction` (مدين/دائن)، `amount`. مع **عرض حيّ لإجمالي المدين/الدائن والفرق** (placeholder/`afterStateUpdated`) لتأكيد التوازن قبل الترحيل.
  - واجهة السلايد 2 المبسّطة (سطر مدين + سطر دائن) = الحالة الافتراضية للـ Repeater (سطران)، مع دعم N سطور.
- **Action "ترحيل" (Post)** (صلاحية `journal_entries.post`): يستدعي `JournalEntryService::post`، يظهر فقط للمسوّدات، يطبّق قاعدة التوازن.
- القيد **المرحّل**: `disabled`/`canEdit=false` (نمط append-only).

**RBAC**: `journal_entries.view, journal_entries.create, journal_entries.edit, journal_entries.post, journal_entries.delete`.
**i18n**: `resources.journal_entries.*` + `enums.document_type.*` + `enums.journal_status.*` (en+ar) + رسائل `errors.journal.*` (unbalanced, already_posted, no_lines).
**Theme/RTL**: ألوان badges سيمانتية؛ التحقق من محاذاة الـ Repeater وأعمدة الأرقام في RTL.
**Tests** `tests/Feature/Finance/JournalEntryTest.php`: رفض القيد غير المتوازن؛ نجاح المتوازن؛ منع تعديل/ترحيل المرحّل مرتين؛ ترقيم مستقل لكل نوع مستند؛ ألوان/labels الأنواع.

**الربط**: يستهلك `Account` (المرحلة 0). يغذّي دفتر الأستاذ (المرحلة 2) والميزان (المرحلة 3).

---

### المرحلة 2 — دفتر الأستاذ العام لكل حساب (الترحيل التلقائي) — سلايد 3
**Service** `app/Services/GeneralLedgerService.php` (تعميم `AccountStatementService`):
- `for(Account $account, ?Carbon $from, ?Carbon $to): Collection`:
  - يبدأ بـ **رصيد أول المدة** = `opening_balance` (+ أي حركة قبل `from`).
  - يجلب `journal_entry_lines` للحساب من قيود **posted** فقط، مرتبة بـ `entry_date,id`، مع بيانات القيد (document_number, description, document_type).
  - يحسب **الرصيد المتحرك** حسب طبيعة الحساب: `running += sign(nature) * (debit - credit)` (مدين موجب لحساب مدين، والعكس) — في PHP (محمول).
  - يرجّع صفوفاً تحمل: التاريخ، رقم المستند، البيان، مدين، دائن، الرصيد المتحرك.
- `closingBalance(Account)` و`totals(Account)` (إجمالي مدين/دائن = صف **الإجمالي**).

**Filament**:
- **خيار A (موصى به)**: `RelationManager` `LedgerEntriesRelationManager` داخل `AccountResource` يعرض كشف الحساب (نمط `AccountEntriesRelationManager` مع نافذة SQL أو حساب PHP)، مع صف **رصيد أول المدة** (header) و**الإجمالي** (footer / `summarize`).
- **خيار B**: مورد قراءة مستقل `GeneralLedgerResource` بفلتر اختيار الحساب.
- أعمدة مطابقة للسلايد: الرصيد \| دائن \| مدين \| البيان \| رقم المستند \| التاريخ. مع تصدير PDF لاحقاً (يوجد مجلد `PDFs/`).

**RBAC**: `general_ledger.view` (أو إعادة استخدام `accounts.view`).
**i18n**: `resources.general_ledger.*` (en+ar) — opening_balance, total, debit, credit, balance.
**Theme/RTL**: أرقام مالية بمحاذاة صحيحة في RTL؛ صف الإجمالي بخط `bold` (نمط `running_balance` الحالي).
**Tests** `tests/Feature/Finance/GeneralLedgerTest.php`: الرصيد الافتتاحي يُحتسب؛ الترحيل التلقائي يظهر القيود المرحّلة فقط (لا المسودات)؛ الرصيد المتحرك حسب الطبيعة (حساب مدين مقابل دائن)؛ صحة الإجمالي.

**الربط**: يقرأ `Account.opening_balance` + `journal_entry_lines`. لا يكتب شيئاً (View).

---

### المرحلة 3 — ميزان المراجعة (Trial Balance) — سلايد 4
**Service** `app/Services/TrialBalanceService.php`:
- `build(?Carbon $asOf, ?string $currency): Collection`: لكل حساب نشط → `إجمالي مدين = SUM(lines.debit)`, `إجمالي دائن = SUM(lines.credit)` (من قيود posted)، `الرصيد = opening_balance + sign(nature)*(مدين−دائن)`.
- إجماليات عامة (يجب أن يتوازن إجمالي المدين = إجمالي الدائن على مستوى نفس العملة) + تحقّق صحة.
- تجميع **لكل عملة** (القرار 5).

**Filament Page** `app/Filament/Pages/TrialBalance.php` (**أول Filament Page** في المشروع — `discoverPages` مفعّل):
- مجموعة `finance`، أيقونة `heroicon-o-scale`.
- جدول/Infolist: م \| اسم الحساب \| مدين \| دائن \| الرصيد (مطابق للسلايد 4)، مع فلتر تاريخ "حتى" + فلتر عملة، وصف إجمالي أسفل الجدول.
- صلاحية الوصول: `public static function canAccess(): bool => auth()->user()?->can('trial_balance.view')`.

**RBAC**: `trial_balance.view`.
**i18n**: `resources.trial_balance.*` + إضافة `navigation` إن لزم (المجموعة موجودة).
**Theme/RTL**: ألوان سيمانتية؛ محاذاة أرقام.
**Tests** `tests/Feature/Finance/TrialBalanceTest.php`: تطابق المثال في السلايد (الخزينة 2000/1000/1000؛ بنك 5000/4500/500)؛ توازن الإجماليات؛ احترام تاريخ "حتى"؛ التجميع بالعملة؛ RBAC.

**الربط**: يقرأ `Account` + `journal_entry_lines`. ممكن لاحقاً Widget في `StatsOverview` (رصيد الخزينة/البنوك).

---

### المرحلة 4 — التشطيب: التنقل + لوحة التحكم + الترجمة الشاملة
- مراجعة `navigationSort` لموارد المالية لتظهر بترتيب منطقي داخل مجموعة `finance`: شجرة الحسابات → قيود اليومية → دفتر الأستاذ (AccountEntry الحالي يبقى ككشف الأطراف) → ميزان المراجعة.
- (اختياري) Widget لوحة تحكم: رصيد الخزينة، أكبر الأرصدة، عدد القيود غير المرحّلة.
- مراجعة شاملة لـ `ar.json`/`resources.php` لمنع أي نص إنجليزي ثابت.

---

### المرحلة 5 — (اختيارية، قرار 3) الجسر: ربط الأذون بالـ GL تلقائياً
> تُنفّذ فقط بعد اعتماد القرار 3. تجعل الدفتر المساعد (`AccountEntry`) والـ GL متّسقين.

- **Config** `config/accounting.php`: خريطة "حدث → حسابات GL": مثلاً
  - ترحيل إذن إضافة → `Dr مخزون / Cr مورد(محلي/خارجي)` بقيمة الفاتورة.
  - تفعيل إذن تسليم → `Dr عملاء / Cr مبيعات` بقيمة التسليم.
  - علم `accounting.auto_journal` لتشغيل/إيقاف الجسر (تفادي الازدواج).
- **توسيع** `AdditionVoucherService::post` و`DeliveryVoucherService::activateIfFullyApproved`: بعد كتابة `AccountEntry` الحالي، **و** لو `auto_journal=on`، إنشاء `JournalEntry` مرحّل عبر `JournalEntryService` على حسابات المراقبة (نوع المستند: إيصال توريد للإضافة، أمر صرف/تسوية للتسليم حسب السياسة).
- **حسابات المراقبة**: ربط `Account` المعنيّة (العملاء/الموردون) بكيانات الأطراف اختيارياً (`Account.party` morph) لتسهيل المطابقة.
- **Tests** `tests/Feature/Finance/VoucherToGlBridgeTest.php`: ترحيل إذن يولّد قيد GL متوازن على الحسابات الصحيحة؛ الإيقاف عبر config يمنع التوليد؛ عدم الازدواج.

**الربط (الأخطر)**: يلمس خدمات الأذون الموجودة → اختبارات الانحدار لـ `AdditionVoucherTest`/`DeliveryVoucherApprovalTest` يجب أن تبقى خضراء.

---

## 6. RBAC — التفصيل الكامل

### صلاحيات تُضاف إلى `RoleAndPermissionSeeder::getPermissions()`
```
// General Ledger — Chart of Accounts
'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
// General Ledger — Journal Entries
'journal_entries.view', 'journal_entries.create', 'journal_entries.edit',
'journal_entries.post', 'journal_entries.delete',
// General Ledger — Reports
'general_ledger.view',
'trial_balance.view',
```

### توزيع الأدوار (تعديل `getDefaultRoleDefinitions`)
| الدور | الإضافات |
|---|---|
| **Finance** | `accounts.view/create/edit`, `journal_entries.view/create/edit/post`, `general_ledger.view`, `trial_balance.view` (الدور المالك للـ GL). |
| **Admin** | الكل تلقائياً عبر `grantAdminAllPermissions()` (لا تعديل). |
| (اختياري) **Finance_Manager جديد** | كل صلاحيات Finance + `accounts.delete` + `journal_entries.delete` (حذف المسودات) — لو طُلب فصل المدير. |

> **ملاحظة مهمة**: الدور `Finance` موجود مسبقاً؛ لكن `ensureInitialRolesExist()` **لا تلمس الأدوار الموجودة**. لذا الصلاحيات الجديدة لن تُضاف تلقائياً لدور Finance القائم في بيئة فيها الدور موجود — تُضاف يدوياً من شاشة الأدوار، أو عبر سطر seeder صريح/أمر `php artisan` يمنح صلاحيات GL لدور Finance (يُوثّق في تعليمات النشر). Admin يحصل عليها دائماً تلقائياً.

### Policies (نمط `ItemPolicy`)
- `AccountPolicy`: viewAny/view→`accounts.view`، create→`accounts.create`، update→`accounts.edit`، delete→`accounts.delete`.
- `JournalEntryPolicy`: viewAny/view→`journal_entries.view`، create→`journal_entries.create`، update→`journal_entries.edit` **و** `$entry->status===draft` (منع تعديل المرحّل)، delete→`journal_entries.delete` **و** draft.
- الميزان/الدفتر: حماية على مستوى `canAccess()`/`viewAny` بالصلاحيات أعلاه.
- أكشن "ترحيل" عبر `->visible(fn () => auth()->user()?->can('journal_entries.post'))`.

---

## 7. i18n / RTL / Theme — قائمة الالتزام

لكل مورد/enum/صفحة جديدة:
- **`lang/en/resources.php` + `lang/ar/resources.php`**: كتل `accounts`, `journal_entries`, `general_ledger`, `trial_balance` (label, plural_label, navigation_label, sections, fields, columns, filters, actions) + أقسام `enums.account_type`, `enums.document_type`, `enums.journal_status`. (`account_direction` موجود — يُعاد استخدامه.)
- **`lang/en/errors.php` + `lang/ar/errors.php`**: `journal.unbalanced`, `journal.already_posted`, `journal.no_lines`.
- **`lang/ar.json`**: أي جمل عامة/تأكيدات جديدة (مثلاً "ترحيل القيد؟").
- **المجموعة**: `finance` موجودة — لا حاجة لإضافتها، فقط ربط الموارد بها عبر `getNavigationGroup()`.
- **`__()` إلزامي** في كل `label/getNavigationLabel/getLabel/getPluralLabel` — **ممنوع نص ثابت**.
- **enums** تطبّق `HasLabel` بـ `__('resources.enums.<x>.'.$this->value)` و`HasColor` بألوان سيمانتية (نمط `AccountDirection`/`TransactionType`).
- **RTL**: تلقائي؛ التحقّق فقط من محاذاة الأرقام في جداول الدفتر/الميزان و الـ Repeater في العربية.
- **Dark/Light**: لا ألوان ثابتة في Blade؛ الاعتماد على `primary/success/danger/warning/info/gray` فقط (أنواع المستند الثلاثة: gray/danger/success ← تعمل في الوضعين).
- **العملة**: عدم تثبيت `->money('EGP')` للحسابات الأجنبية؛ استخدام `$account->currency` ديناميكياً.

---

## 8. خطة الاختبارات (تتبع نمط `tests/Feature`)

مجلد جديد `tests/Feature/Finance/`:

| الملف | يغطّي |
|---|---|
| `ChartOfAccountsTest.php` | بذر الشجرة؛ CRUD؛ تفرّد code؛ RBAC؛ enum AccountType. |
| `JournalEntryTest.php` | رفض غير المتوازن؛ نجاح المتوازن؛ منع تعديل/ترحيل مزدوج؛ ترقيم مستقل لكل نوع مستند؛ append-only. |
| `GeneralLedgerTest.php` | رصيد أول المدة؛ المرحّل فقط (لا مسودات)؛ الرصيد المتحرك حسب الطبيعة؛ الإجمالي. |
| `TrialBalanceTest.php` | مطابقة مثال السلايد 4؛ توازن الإجماليات؛ فلتر "حتى تاريخ"؛ التجميع بالعملة؛ RBAC. |
| `FinanceRbacTest.php` | مصفوفة صلاحيات GL لكل دور (نمط `Sales/RbacTest`): Finance يرى/يُرحّل، الأدوار الأخرى لا. |
| `FinanceI18nTest.php` | وجود مفاتيح ar/en لكل مورد/enum/صفحة جديدة (نمط `Sales/I18nTest`). |
| `VoucherToGlBridgeTest.php` (المرحلة 5 فقط) | ترحيل إذن يولّد قيد GL متوازن صحيح؛ الإيقاف عبر config؛ لا ازدواج؛ انحدار أذون. |

**Factories جديدة** (نمط الموجود): `AccountFactory`, `JournalEntryFactory`, `JournalEntryLineFactory`.

**ملاحظات تقنية**:
- `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` (+ `ChartOfAccountsSeeder` عند اللزوم) في `setUp`.
- الترقيم يعتمد `Cache::lock`؛ على `CACHE_STORE=array` تأكّد من سلوك القفل (نفس ملاحظة خطة المخزون) — وإلا حقن قفل no-op في الاختبار.
- اختبار التوازن يجب أن يشمل قيوداً بعملات مختلفة (رفض/فصل حسب القرار 5).

---

## 9. ⭐ ضبط الكاش المحلي لرؤية التعديلات (محلي — مختلف عن البرودكشن)

> **مهم جداً (طلب صريح)**: بعد كل مرحلة تنفيذ، شغّل التسلسل التالي محلياً لرؤية التعديلات. **لا تستخدم** `config:cache`/`route:cache`/`view:cache` محلياً (للبرودكشن — تُخفي التعديلات).

```powershell
# 1) الميجريشن + بذر الصلاحيات والشجرة
php artisan migrate
php artisan db:seed --class=RoleAndPermissionSeeder
php artisan db:seed --class=ChartOfAccountsSeeder      # عند توفّره (المرحلة 0)

# 2) مسح كل الكاشات (الأهم محلياً)
php artisan optimize:clear            # config + route + view + event + compiled
php artisan filament:clear-cached-components
php artisan icons:clear
php artisan permission:cache-reset    # كاش صلاحيات Spatie

# 3) إعادة بناء أصول الواجهة (Tailwind/Filament theme)
npm run build                         # أو: npm run dev (watch أثناء التطوير)

# 4) لو في Queue worker شغّال
php artisan queue:restart

# 5) كاش المتصفح / Service Worker:
#    محلياً APP_ENV=local → طبقة resilience معطّلة وتلغّي تسجيل أي SW قديم تلقائياً.
#    لو ظهرت واجهة قديمة: Hard reload (Ctrl+F5) أو امسح كاش الموقع من DevTools.
```

أمر مختصر (copy/paste):
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan db:seed --class=ChartOfAccountsSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build; php artisan queue:restart
```

> بما أن دور **Finance موجود مسبقاً**، بعد إضافة صلاحيات GL: امنحها لدور Finance من شاشة الأدوار، أو أعد إنشاء الدور، أو أضف أمراً يمنحها (Admin يأخذها تلقائياً). ثم `php artisan permission:cache-reset`.

---

## 10. المخاطر والاعتبارات (الربط مع الأجزاء الأخرى)

1. **ازدواج القيود (الأخطر مفاهيمياً)**: لو فُعّل جسر الأذون (المرحلة 5) **و** أُدخلت قيود يدوية لنفس العملية → ازدواج. الحل: علم config + سياسة واضحة (الأذون تلقائي، اليدوي للتسويات فقط)، وحسابات مراقبة محدّدة.
2. **التوازن المزدوج**: قاعدة `مدين==دائن` يجب فرضها في الخدمة **وفي** الـ UI (عرض حيّ) معاً؛ منع ترحيل غير متوازن قطعياً.
3. **تعدد العملات**: خلط عملات في قيد واحد بدون FX يكسر التوازن. القرار 5: قيد بعملة واحدة، والميزان يُجمَّع لكل عملة. FX = لاحقاً.
4. **دور Finance القائم**: `ensureInitialRolesExist` لا يحدّث الأدوار الموجودة → صلاحيات GL الجديدة تحتاج منحاً يدوياً/أمراً صريحاً (موثّق في القسم 9).
5. **append-only**: القيد المرحّل غير قابل للتعديل/الحذف (نمط `AccountEntry`)؛ أي تصحيح = **قيد تسوية** (نوع المستند الأخضر) — وهذا بالضبط ما يقصده الملف بـ "قيد تسوية".
6. **Activity Log**: `Account` و`JournalEntry` يطبّقان `LogsActivity` بنمط الموجود.
7. **Sync/Offline-first**: موديلات GL الجديدة **server-authored** (بدون `Syncable`) — القيود تُدخل من لوحة الأدمن لا من الـ Operator Console. (لو طُلبت مزامنة لاحقاً: scopes في `SyncServiceProvider` + `config/sync.php`.)
8. **الأداء**: الدفتر/الميزان محسوبان كـ Views؛ مع نمو السطور تُضاف فهارس (`account_id, journal_entry_id`) — مذكورة في المرحلة 1 — وإمكانية تجميع SQL بدل PHP للميزان لو لزم.
9. **الفاتورة الإلكترونية (ETA) / تقارير محاسبية متقدمة** (قائمة دخل، ميزانية عمومية): خارج نطاق الملف الحالي — تُوثّق كمرحلة لاحقة.

---

## 11. ملخص التصنيف النهائي

- **موجود ومطابق (✅) — يُعاد استخدامه**: enum `AccountDirection`؛ منطق الرصيد المتحرك (`AccountStatementService` + نافذة SQL)؛ نمط شاشة الدفتر للقراءة فقط؛ دور `Finance`؛ مجموعة تنقل `finance`؛ بنية i18n/Theme/Tests؛ مبدأ append-only.
- **موجود لكن للأطراف فقط ويحتاج تعميم (🟡)**: كشف الحساب/الرصيد المتحرك (طرف → أي حساب GL)؛ تثبيت العملة EGP → `currency` ديناميكي؛ ربط الدفتر المساعد بالـ GL عبر حسابات مراقبة.
- **غير موجود (❌ — بناء جديد)**: شجرة الحسابات (`Account` + `AccountType` + Seeder)؛ قيود اليومية المزدوجة (`JournalEntry`/`JournalEntryLine` + شاشة إدخال + قاعدة التوازن)؛ أنواع المستند الثلاثة الملوّنة (`DocumentType`)؛ الترحيل التلقائي/دفتر الأستاذ العام (`GeneralLedgerService`)؛ ميزان المراجعة (`TrialBalanceService` + Filament Page)؛ الرصيد الافتتاحي؛ صلاحيات GL.

> **الترتيب الموصى به للتنفيذ**: 0 (الشجرة) → 1 (القيود) → 2 (الدفتر) → 3 (الميزان) → 4 (التشطيب) → 5 (جسر الأذون، اختياري بعد اعتماد القرار 3). كل مرحلة مع **اختباراتها + ترجمتها + صلاحياتها + مسح الكاش** قبل الانتقال للتالية.
