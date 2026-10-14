# خطة استيراد "مخزن المصنع - بالصور" إلى النظام

> ✅ **الحالة (2026-10-12): تم التنفيذ بالكامل local** — المراحل 0–6. غير مرفوع (uncommitted) على `main`.
> الاستيراد المحلي تم فعلًا: 400 صنف، 21 فئة، 288 رصيد افتتاحي (216,746.9 وحدة)، 339 صنف بصورة (307 ملف، 23MB بدل 364MB)، و3 أصناف وهمية اتخفت.
> الإنتاج: جاهز للـdeploy — شوف **القسم 6 (دليل التشغيل على الإنتاج)** في آخر الملف.
> **القيود:** كل العمل local على `main` بدون commit / push / deploy. الإنتاج فقط بعد تأكيد صريح.
> **المصدر:** `مخزن المصنع - بالصور - نسخة نهائية مرتبة.xlsx` (شيت واحد «خامات»، 414 صف × 20 عمود، 314 صورة داخل الخلايا).
> **المرجع في الكود:** Laravel 12 + Filament 3.3 — `Item`, `Inventory`, `InventoryService`, `ItemResource`, API `MasterData/Item*`.

---

## 1. تحليل الملف

### 1.1 الشكل العام
- شيت واحد اسمه **«خامات»**، والصف الأول عناوين. لا توجد صفوف عناوين للأقسام؛ الأقسام تُفهم من **رجوع عمود «م» للرقم 1** ومن تشابه «البيان».
- رأس الجدول **مكرر مرتين** داخل الشيت (الصف 354 و365)، والصف 353 عنوان مدمج: **«عينات داخل المكتب»**.
- إجمالي **409 صف أصناف**: 400 صنف مخزن + 9 عينات مكتب.
- **الصور:** صور Excel الحديثة «داخل الخلية» (Place in cell / richData) — مش صور عائمة؛ لذلك أي مكتبة قراءة عادية (openspout / openpyxl / PhpSpreadsheet) تقرأها كـ `#VALUE!`.
  تم فك الربط يدويًا (`vm` → metadata → rdrichvalue → richValueRel → media): **348 خلية بصورة، 314 صورة مختلفة** (بعض الأصناف تتشارك نفس الصورة)، PNG و JPEG، **متوسط 1.5MB وأقصى 3.5MB، الإجمالي 364MB** ⇐ لازم ضغط/تصغير عند الاستيراد. 61 صنف بدون صورة.

### 1.2 الأعمدة ومعناها

| عمود | العنوان | ماذا يحتوي فعليًا | ملاحظات جودة |
|---|---|---|---|
| A | م | ترقيم داخل كل قسم | يتكرر — **لا يصلح مُعرّفًا** |
| B | البيان | اسم عام: «شريط» (81 مرة)، «ريكم سميك» (26)، «جسم جانبي»… | 142 اسم فقط لـ 409 صنف ⇐ الاسم وحده لا يميّز الصنف |
| C | (بدون عنوان) | **التصنيف**: «خامات» / «بضاعة جاهزة» | ممتلئ في 144 صف فقط |
| D | الصورة | صورة داخل الخلية | 348 |
| E | الطراز | **خط المنتج**: PT (363) / DT (42) / LT (4) | |
| F | السمك (مم) | رقم | 114 صف |
| G | العرض (مم) | **مختلط**: رقم بالمم (100، 160…) **أو** كود مقاس بارة (B60، B100، 2B200، All) | لازم يتفصل لحقلين |
| H | Mat. | Cu / Al / Fe | «AL» = «Al» (توحيد) |
| I | الطول (م) | غالبًا 3م؛ أحيانًا `<2`، 0، أرقام بالمم (1250)، و**معادلات** `=S315` / `=1.2*S347` | في الريكم والفلكسيبل الطول = العدد ⇐ الكمية بالمتر |
| J | Edge | R / F (حافة مستديرة / مستقيمة) | |
| K | المعالجة | بدون / مدهون / منحس / مقصدر | 7 صفوف فيها «مش مستورد» (108-114) — **قيمة في العمود الغلط** |
| L | الإستخدام | DKC-E، B100، مونوبلوكات، لحام نحاس… | نص حر، 32 صف |
| M | Origin | مستورد / محلي | |
| N | Source | المورّد/الماركة: DKC، Zucchini، اليو مصر، هايدرو، دلمار، اليومات، كانكس، China | نص حر |
| O | الكود | كود الصنف | **50 بدون كود**، **17 كود مكرر على 46 صف**، 39 كود رقمي (11257، 60…) |
| P | الامبير | 1600، 2000، 2500، 160… و`ALL`، `63/160`، `315/630` | نص وليس رقم |
| Q | الأقطاب | 4 / 5 | 12 صف |
| R | مقاس | `100*5.75`، `4p`، `22.3`، `95mm2`… | نص حر |
| S | العدد | **الرصيد الحالي** | 17 صف فاضي، صف سالب (184: ‎-15.33)، كسر (285: 3.9) |
| T | ملاحظات | لون (اسود/احمر/اصفر/ازرق)، «تالف»، «يتم التصنيع»، تكرار للمقاس «b 100»… | |

### 1.3 الأقسام المستنتجة (ستصبح **فئات أصناف**)

| # | الفئة | الصفوف | النوع |
|---|---|---|---|
| 1 | شرائط نحاس | 2–50 | خامات |
| 2 | شرائط ألومنيوم | 51–82 | خامات |
| 3 | قطاعات وأجسام ألومنيوم | 83–107 | خامات |
| 4 | أكواع وزوايا وأغطية أكواع | 108–128 | خامات |
| 5 | مونوبلوك مستورد | 129–136 | بضاعة جاهزة |
| 6 | أغطية وصلات ووحدات نهاية | 137–141 | خامات |
| 7 | تكسيحات أرضي ومواسك نحاس | 142–162 | خامات |
| 8 | بوابات وبلاكات وريش المخارج | 163–187 | خامات |
| 9 | علب مخرج ولوحات عدادات | 188–199 | خامات |
| 10 | عوازل بارة | 200–204 | خامات |
| 11 | جوانات | 205–220 | خامات |
| 12 | برشام ومسامير | 221–224 | خامات |
| 13 | مستلزمات مونوبلوك | 225–264 | خامات |
| 14 | عوازل وخامات مستوردة (Zucchini) | 265–290 | خامات |
| 15 | شرائح فلكسيبل وألواح نحاس | 291–304 | خامات |
| 16 | كابلات وشرائح CCA | 305–312 | خامات |
| 17 | سلك لحام | 313–314 | خامات |
| 18 | ريكم | 315–349 | خامات |
| 19 | ألواح استانلس | 350–352 | خامات |
| 20 | عينات داخل المكتب | 355–363 | ⚠ قرار (5.2) |
| 21 | مكونات DT | 366–405 | خامات |
| 22 | بضاعة جاهزة DKC (LT/PT/DT) | 406–414 | بضاعة جاهزة |

### 1.4 مشاكل البيانات (كلها ستظهر في تقرير المراجعة قبل الاستيراد)
1. **أكواد مكررة (17):** `PBE-12` (طول 6م و3م)، `rsp00063y` و`STPEDEX002` و`STPMONX023/024` (عادي/أسود)، `60`/`70`/`80`/`90`/`100`/`120` (ريكم بأربع ألوان لكل مقاس)، `100*0.5` (نحاس/ألومنيوم)، `60*0.5`، `BAS-COV`، `SS-SH-1.5` (صف 352 سُمكه 1 — غالبًا خطأ كتابة)، و`LTC25DSP43AA000`/`DTN90ETCE2AA` (مكررين بين العينات والبضاعة الجاهزة).
2. **50 صنف بدون كود** (مونوبلوك 130-136، جوانات IP، مجموعات نحاس البلاكة، عوازل Zucchini، كاوتش…).
3. **رصيد سالب** صف 184 (‎-15.33) ⇐ لا يصلح رصيد افتتاحي.
4. **17 صف بلا عدد** ⇐ يُستورد الصنف برصيد 0.
5. **«مش مستورد» في عمود المعالجة** (108-114) بينما Origin = مستورد — تناقض.
6. **الوحدة غير موجودة في الملف إطلاقًا** ⇐ لازم تُستنتج (القسم 3.3).
7. **لا توجد أسعار/تكلفة** ⇐ قيمة المخزون الافتتاحي = 0 (قرار 5.1).
8. أصناف «تالف» بكود منفصل (`…-IMP02`) — تبقى أصناف مستقلة كما هي.

---

## 2. الوضع الحالي في النظام (حقائق من الكود)

| البند | الحالة | الملف |
|---|---|---|
| جدول الأصناف | `name, sku(unique), type, unit, unit_cost, description, minimum_stock, is_scrap, scrap_source_item_id` — **لا مواصفات، لا فئة، لا صورة** | `database/migrations/2026_05_17_010002_*`, `app/Models/Item.php` |
| النوع | Enum ثابت: raw_material / finished_good / semi_finished / consumable | `app/Enums/ItemType.php` |
| الوحدة | Enum ثابت 10 وحدات (pcs, kg, m, m2, liter, set, roll, sheet, box, ton) | `app/Enums/UnitOfMeasure.php` |
| المخازن | 3 مخازن منطقية؛ مخزن الصنف «الأساسي» يُحدد من نوعه | `app/Enums/WarehouseType.php` |
| إدخال رصيد | فقط عبر `InventoryService::addStock()` (قفل + حركة) — **لا توجد شاشة رصيد افتتاحي** | `app/Services/InventoryService.php:29` |
| اختيار الصنف في المستندات | **9 قوائم** تعرض `name` فقط: أمر الشراء، إذن الإضافة، قائمة المواد، إذن الصرف، إذن التسليم، الارتداد، الإهلاك، حجز المخزون (×2) | `*Resource.php` (`relationship('item','name')`) |
| عرض اسم الصنف | ~35 موضع: جداول، PDF أمر الشراء، تقرير الانحراف، رسائل `InventoryService`، الإشعارات، 15 API Resource | grep `item->name` / `item.name` |
| API الأصناف | `StoreItemRequest`/`UpdateItemRequest` + `MasterData/ItemResource` + بحث `SearchController` على `sku,name` | `app/Http/...` |
| البيئة المحلية | MySQL شغال، `electrotech` DB، كل الـmigrations متنفذة، فيها 3 أصناف تجريبية فقط؛ GD + zip متاحين في PHP | `.env` (`APP_URL=http://localhost:8001`) |

**الخلاصة:** بعد الاستيراد سيكون عندنا 81 صنف اسمه «شريط» و26 اسمه «ريكم سميك». كل قائمة اختيار صنف في النظام ستعرض أسماء متطابقة لا يمكن التمييز بينها ⇐ **التغيير لازم يشمل النظام كله**، مش شاشة الأصناف بس.

---

## 3. التصميم المقترح

### 3.1 أعمدة جديدة على `items` (migration واحدة، كلها nullable ⇐ لا تكسر أي بيانات قائمة)

| العمود | النوع | المصدر |
|---|---|---|
| `item_category_id` | FK → `item_categories` | القسم (1.3) |
| `product_line` | Enum `ProductLine`: pt / dt / lt | الطراز |
| `part_number` | string(100), index, **غير unique** | الكود الأصلي كما في الملف (حتى المكرر) |
| `material` | Enum `ItemMaterial`: cu / al / fe | Mat. |
| `thickness_mm` | decimal(10,3) | السمك |
| `width_mm` | decimal(10,2) | العرض لو رقم |
| `size_code` | string(20) | العرض لو B60 / 2B200 / All |
| `length_m` | decimal(10,3) | الطول (بعد حساب المعادلات؛ القيم الشاذة → ملاحظات) |
| `edge` | Enum `ItemEdge`: rounded / flat | Edge |
| `treatment` | Enum `ItemTreatment`: none / painted / copper_coated(منحس) / tinned(مقصدر) | المعالجة |
| `origin` | Enum `ItemOrigin`: imported / local | Origin |
| `brand` | string(100) | Source |
| `usage` | string(100) | الإستخدام |
| `ampere_rating` | string(30) | الامبير (نص بسبب ALL و63/160) |
| `poles` | unsignedTinyInteger | الأقطاب |
| `size` | string(50) | مقاس |
| `color` | string(30) | مستخرج من الملاحظات (اسود/احمر/اصفر/ازرق/ابيض/رصاصي) |
| `image_path` | string | الصورة بعد الضغط على قرص `public` |

+ جدول جديد **`item_categories`** (`id, name, sort, timestamps`) + `ItemCategory` model + شاشة Filament بسيطة لإدارته.
`description` الحالي يستقبل الملاحظات.

### 3.2 هوية الصنف المعروضة (الجزء الأهم للنظام كله)
- Accessor جديد على `Item`: **`spec_summary`** يبني وصفًا قصيرًا من المواصفات الموجودة فقط، مثل: `Cu · 100×5.75 مم · 3م · مقصدر` أو `B100 · أسود`.
- Accessor **`display_label`** = `"{sku} — {name} ({spec_summary})"` ويُستخدم في **كل** القوائم والجداول والـPDF.
- Scope **`searchable($term)`** يبحث في `sku, part_number, name, size, size_code, brand`.
- Trait/Helper واحد `ItemSelect::configure(Select $select)` يطبّق: `getOptionLabelFromRecordUsing(display_label)` + `getSearchResultsUsing(searchable)` + `searchable()` + `preload(false)` — ويُستبدل به `relationship('item','name')` في القوائم التسعة (DRY: تعديل مستقبلي في مكان واحد).

### 3.3 قواعد الاستنتاج في المستورد
- **النوع:** «بضاعة جاهزة» ⇐ `finished_good`؛ غير ذلك ⇐ نوع الفئة (1.3).
- **الوحدة** (لا توجد في الملف — تحتاج تأكيد 5.4):
  - البيان يبدأ بـ «متر» أو «متر طولى» ⇐ `m`
  - «ريكم سميك/خفيف» (الطول = العدد بالمعادلة) ⇐ `m`
  - «كابل» ⇐ `m`
  - «لفة» ⇐ `roll`، «لوح» ⇐ `sheet`
  - «سلك لحام» ⇐ `kg`
  - غير ذلك (شرائط بالعود، قطع، مسامير…) ⇐ `pcs`
- **SKU (فريد إجباري):** الكود الأصلي لو موجود وفريد؛ المكرر ⇐ الكود + لاحقة مميِّزة (`-BLK` للأسود، `-6M` للطول، أو `-2`)؛ بدون كود ⇐ يُولَّد `FS-0001…`. **الكود الأصلي يُحفظ دائمًا في `part_number`** للبحث.
- **الرصيد:** سالب أو فاضي ⇐ 0 مع تنبيه في التقرير. الكسور مقبولة (المخزون decimal(14,4)).
- **الصورة:** تصغير بـ GD لأقصى 1000px + JPEG جودة 80 ⇐ ~100-200KB؛ اسم الملف = hash المحتوى (الصور المشتركة تُخزَّن مرة واحدة). المتوقع ~40MB بدل 364MB.

### 3.4 المستورد (قابل للتكرار وآمن)
أمر artisan: `php artisan items:import-factory-stock {file} --user={id} [--dry-run] [--report=path]`
- **قارئ مخصص** (`ZipArchive` + XML) لأن الصور richData لا تقرأها أي مكتبة جاهزة — نفس المنطق المُجرَّب أثناء التحليل.
- `--dry-run` لا يكتب شيئًا ويُخرج **ملف مراجعة xlsx** (عبر openspout الموجود): كل صف ⇐ SKU النهائي، الاسم، الفئة، النوع، الوحدة، الرصيد، والتنبيهات — لتراجعه قبل الاستيراد الحقيقي.
- الاستيراد الحقيقي داخل transaction: upsert بالـSKU؛ الرصيد الافتتاحي عبر `InventoryService::addStock()` على المخزن الأساسي للصنف بملاحظة «رصيد افتتاحي — استيراد مخزن المصنع»، **ويُتخطّى إذا كان للصنف أي حركة سابقة** ⇐ تشغيل الأمر مرتين لا يضاعف الرصيد.
- نفس الأمر بالضبط سيُستخدم على الإنتاج لاحقًا.

---

## 4. مراحل التنفيذ

### المرحلة 0 — تجهيز البيئة المحلية
- تشغيل التطبيق: `php artisan serve --port=8001` + `npm run build` (أو dev)، التأكد من `storage:link` (موجود) وفتح لوحة التحكم.
- **نسخة احتياطية** من القاعدة المحلية قبل أي migration: `mysqldump electrotech > scratchpad/electrotech_before_import.sql`.
- تشغيل الاختبارات الحالية كخط أساس (`php artisan test`) وتسجيل النتيجة.

### المرحلة 1 — نموذج البيانات
- **Create:** `database/migrations/2026_10_12_000001_create_item_categories_table.php`، `…_000002_add_specifications_to_items_table.php`، `app/Models/ItemCategory.php`، `app/Enums/{ProductLine,ItemMaterial,ItemEdge,ItemTreatment,ItemOrigin}.php` (كلها `HasLabel` + ترجمة مثل `ItemType`).
- **Modify:** `app/Models/Item.php` (fillable, casts, `category()`, `spec_summary`, `display_label`, `scopeSearchable`, activity log)، `database/factories/ItemFactory.php`، `lang/{ar,en}/resources.php`.
- **Tests:** `tests/Feature/Inventory/ItemSpecificationsTest.php` — `spec_summary` يتجاهل الحقول الفارغة، `display_label` فريد لصنفين بنفس الاسم، `searchable` يجد بالـpart_number و size_code، والأصناف القديمة بدون مواصفات تعمل كما هي.

### المرحلة 2 — شاشة الأصناف
- **Modify:** `app/Filament/Resources/ItemResource.php`
  - الفورم: قسم «المواصفات الفنية» (collapsible) بكل الحقول + `FileUpload` للصورة (image, public disk, `items/`, imageEditor اختياري) + اختيار الفئة.
  - الجدول: صورة مصغرة دائرية، الفئة، الطراز، الخامة، المقاس، الكود الأصلي (toggleable)؛ فلاتر: الفئة، الطراز، الخامة، المنشأ، المعالجة، «بدون صورة»، «رصيد صفر»؛ البحث عبر `searchable`.
- **Create:** `app/Filament/Resources/ItemCategoryResource.php` (+ Pages) — صلاحيات بنفس نمط `items.*`، وإضافتها في `RoleAndPermissionSeeder`.
- **Modify:** `resources/views/filament/items/quick-view.blade.php` — الصورة + جدول المواصفات (بتوكنات الثيم `dark:bg-[var(--surface-2)]`).
- **Tests:** إنشاء/تعديل صنف بمواصفات وصورة (`Storage::fake('public')`)، الفلاتر، صلاحيات الفئات.

### المرحلة 3 — تعميم الهوية الجديدة على النظام كله
- **Create:** `app/Filament/Support/ItemSelect.php`.
- **Modify (القوائم التسعة):** `PurchaseOrderResource:146`، `AdditionVoucherResource:130`، `BomResource:111`، `IssueVoucherResource:301`، `DeliveryVoucherResource:128`، `ReturnVoucherResource:100`، `DepreciationVoucherResource:106`، `StockReservationResource:112,119` + أي `Select` لأصناف الـWork Order / Standard BOM.
- **Modify (العرض):** أعمدة `item.name` في الجداول ⇐ `display_label` أو `name` + `description(spec_summary)`؛ `resources/views/pdf/purchase-order.blade.php`، `pdf/work-order-material-variance.blade.php`، كارت الصنف/كارت المخزن، رسائل `InventoryService` و`IssueVoucherService` و`WorkOrderMaterialService`، `NotifyDepartmentsOfManufacturingFinish`.
- **Modify (API):** `StoreItemRequest`/`UpdateItemRequest` تقبل الحقول الجديدة + الصورة؛ `MasterData/ItemResource` يُرجع المواصفات + `image_url` + `display_label`؛ الـ14 Resource الأخرى تضيف `item_label`؛ `SearchController` يبحث في الحقول الجديدة؛ endpoint للفئات؛ إعادة توليد مرجع الـAPI.
- **Modify:** صفحة `/documentation` (قسم الأصناف).
- **Tests:** اختبار لكل قائمة أنها تبحث بالـpart_number وتعرض الـlabel المميز؛ تحديث اختبارات API الموجودة (`tests/Feature/Api/...Item*`).

### المرحلة 4 — المستورد
- **Create:**
  - `app/Imports/FactoryStock/WorkbookReader.php` — قراءة الصفوف + فك صور richData (row ⇒ media path).
  - `app/Imports/FactoryStock/RowNormalizer.php` — صف خام ⇒ DTO `NormalizedItem` + قائمة تنبيهات (القواعد 3.3، خريطة الفئات 1.3 كـconfig ثابت داخل الكلاس).
  - `app/Imports/FactoryStock/ImageStore.php` — تصغير GD + تخزين بالـhash.
  - `app/Imports/FactoryStock/FactoryStockImporter.php` — upsert + رصيد افتتاحي idempotent + تقرير.
  - `app/Console/Commands/ImportFactoryStock.php`.
- **Tests:** `tests/Feature/Inventory/FactoryStockImportTest.php` بملف fixture صغير (يُبنى داخل الاختبار): تطبيع العرض B60/رقم، المعادلات، الأكواد المكررة/الفاضية، الرصيد السالب، الوحدات، الصورة المشتركة تُخزَّن مرة، **التشغيل مرتين لا يضاعف الرصيد**، `--dry-run` لا يكتب شيئًا.

### المرحلة 5 — التشغيل المحلي والمراجعة
1. `php artisan migrate`
2. `php artisan items:import-factory-stock "<file>" --dry-run --report=…` ⇐ **أرسل لك ملف المراجعة وأتوقف** لتأكيد الـSKU/الوحدات/الفئات.
3. بعد تأكيدك: الاستيراد الحقيقي.
4. تحقق: عدد الأصناف = المتوقع، مجموع الأرصدة = مجموع عمود العدد (بعد استبعاد السالب/العينات)، عيّنة أصناف بالصور، فتح كل شاشة فيها اختيار صنف (أمر شراء، إذن صرف، BOM…) والتأكد من التمييز، الوضع الفاتح والداكن.
5. `php artisan test` كامل أخضر.

### المرحلة 6 — توثيق داخلي
- تحديث هذا الملف بالحالة + الذاكرة.

### المرحلة 7 — الإنتاج (**فقط بعد تأكيدك الصريح**)
- فحص read-only لعدد الأصناف والأكواد الموجودة على الإنتاج (احتمال تعارض SKU).
- commit + push ⇐ GitHub Actions ⇐ deploy.sh (migrations).
- رفع ملف الإكسل للسيرفر (`scp` عبر `ssh electrotech`)، نسخة احتياطية للقاعدة، `--dry-run` ثم الاستيراد الحقيقي، والتحقق.

---

## 5. القرارات (اتخذت بناءً على تفويض المستخدم «اعمل القرار المنطقي»)

> كل القرارات دي اتنفذت بالتوصية المكتوبة، وكل تصحيح بيظهر في **تقرير المراجعة** (عمود «ملاحظات التصحيح»).
> إضافات بعد الخطة الأولى: (أ) **حذف الأصناف الوهمية** (`--purge-legacy`): غير المستخدم يُحذف نهائيًا، والمستخدم في مستندات يُخفى (soft delete) ويتصفّر رصيده وتتحرر حجوزاته؛ (ب) الفئات بقواعد اسم/ماركة/نوع بدل أرقام صفوف عشان الشيت المعدّل ما يكسرهاش؛ (ج) عمود «الطول» لو معادلة = إجمالي مش طول قطعة (الريكم بالمتر، و«عود ريكم» بالقطعة وطوله من الاسم)؛ (د) طول > 50م أو = العدد ⇐ لا يُسجّل؛ (هـ) ملاحظات بتكرر مقاس البارة («b 100») لا تدخل الوصف.

| # | القرار | التوصية |
|---|---|---|
| 5.1 | **لا توجد أسعار في الملف** — الرصيد الافتتاحي بقيمة 0 | استيراد الكميات بتكلفة 0 **بدون قيد محاسبي**، وتُضاف التكاليف لاحقًا (من شاشة الصنف أو ملف أسعار) — أو ترسل لي ملف أسعار قبل الاستيراد |
| 5.2 | **عينات داخل المكتب** (9 صفوف) | **لا تُستورد كمخزون** (ليست بضاعة قابلة للصرف)؛ اختياريًا كأصناف في فئة «عينات» برصيد 0 |
| 5.3 | **الأكواد المكررة والفاضية** | سياسة 3.3: SKU فريد مولَّد/بلاحقة + الكود الأصلي في `part_number` |
| 5.4 | **الوحدات** | قواعد 3.3 (متر للريكم/المتر الطولي/الكابل، كجم لسلك اللحام، قطعة للباقي) — تؤكد أو تعدّل |
| 5.5 | **الصف 184** (رصيد ‎-15.33) و**الصفوف 108-114** («مش مستورد» في عمود المعالجة) | الرصيد ⇐ 0 مع تنبيه؛ المعالجة ⇐ فارغة والقيمة تُنقل للملاحظات |
| 5.6 | **الصورة** | صورة واحدة لكل صنف مضغوطة (لا حاجة لمعرض صور متعدد الآن) |
| 5.7 | **الأصناف التجريبية المحلية** (3 أصناف) | تبقى كما هي (لا حذف) |

---

## 6. دليل التشغيل على الإنتاج (بعد التأكيد فقط)

1. **commit + push** على `main` ⇐ GitHub Actions ⇐ `deploy.sh` بيعمل: migrations (جدول الفئات + أعمدة المواصفات)، build للواجهة. **مفيش أي بيانات بتتغير لسه.**
2. **ارفع الشيت للسيرفر** (مش في git — 364MB):
   `scp "مخزن المصنع - بالصور - نسخة نهائية مرتبة.xlsx" electrotech:<DEPLOY_PATH>/storage/app/imports/factory-stock.xlsx`
   (اعمل `mkdir -p storage/app/imports` الأول).
3. **معاينة قبل التنفيذ** (لا يغيّر شيء):
   `php artisan items:import-factory-stock storage/app/imports/factory-stock.xlsx --dry-run --purge-legacy --report=storage/app/imports/review.xlsx`
   ⇐ نزّل `review.xlsx` وراجع شيت «الأصناف القديمة» (هي اللي هتتمسح/تتخفي على الإنتاج).
4. **نسخة احتياطية للقاعدة** ثم التنفيذ **يدويًا فقط** (اتشالت من `deploy.sh` عن قصد: `--purge-legacy` بيمسح أي صنف مش في الشيت، فممنوع أي deploy يقدر يشغّلها):
   `php artisan items:import-factory-stock storage/app/imports/factory-stock.xlsx --purge-legacy --force --report=storage/app/imports/factory-stock-review.xlsx`
   بعدها امسح الشيت من السيرفر. **لو الشيت اتعدّل واتبعت تاني:** شغّل الأمر **من غير** `--purge-legacy` (عشان الأصناف اللي اتضافت يدويًا بعد الاستيراد ما تتمسحش).
5. تحقق: عدد الأصناف 400، الصور ظاهرة (`storage:link` موجود)، و`php -m | grep -i gd` (لو GD مش موجود الأصناف بتدخل من غير صور، وإعادة التشغيل بعد تثبيته بتضيف الصور من غير ما تكرر الرصيد).

**إعادة التشغيل آمنة:** المطابقة بالكود، والرصيد الافتتاحي بيتسجل بس لصنف مالوش أي حركة مخزون.

## 7. الملفات

- **جديد:** `database/migrations/2026_10_12_000001_create_item_categories_table.php`، `…000002_add_specifications_to_items_table.php`، `app/Models/ItemCategory.php`، `app/Policies/ItemCategoryPolicy.php`، 5 enums (`ProductLine`, `ItemMaterial`, `ItemEdge`, `ItemTreatment`, `ItemOrigin`)، `app/Filament/Resources/ItemCategoryResource.php` (+Page)، `app/Filament/Support/ItemSelect.php`، `app/Imports/FactoryStock/*` (Reader, Normalizer, NormalizedItem, ImageStore, Importer, ImportResult, ReviewReport)، `app/Console/Commands/ImportFactoryStock.php`، `app/Http/Controllers/Api/V1/MasterData/ItemCategoryController.php`، `app/Http/Requests/Api/V1/MasterData/Concerns/ItemSpecificationRules.php`، الاختبارات (4 ملفات).
- **معدّل:** `Item` (مواصفات، `spec_summary`، `display_label`، `image_url`، `scopeSearch`)، `ItemResource` + `ViewItem` + quick-view، **10 قوائم اختيار صنف** (أمر شراء، إذن إضافة، BOM ×2، إذن صرف، تسليم، ارتداد، إهلاك، أمر تصنيع ×2) + حجز المخزون، جداول الحركات/الحجوزات/الإنتاج، رسائل الخدمات، PDF أمر الشراء وتقرير الانحراف، API (الأصناف: مواصفات/صورة/فلاتر/بحث متعدد الكلمات + `GET /item-categories` + `label` في كل مرجع صنف)، مرجع الـAPI (Scribe)، صفحة `/documentation`، الترجمة ar/en، `deploy.sh`، `.gitignore`.
