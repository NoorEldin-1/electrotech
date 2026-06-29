# خطة قسم التصنيع — Manufacturing Department Plan

> المصدر: `التصنيع.pptx` (6 سلايدات). التحليل ربط كل متطلب بالكود الفعلي، ثم قسّم العمل على **4 مراحل**.
> هذا الملف يغطّي المراحل الأربع كلها (لسياق المقارنة)، لكن **المرحلة 1 مكتوبة بتفصيل التنفيذ الكامل** لأنها المطلوبة الآن.
> يتبع نفس عُرف `Inventory_Department_Plan.md` / `Sales_Department_Modifications_Plan.md`.

---

## 1) ملخص محتوى الـ pptx (6 سلايدات)

| السلايد | الموضوع |
|---|---|
| 1 | أنواع الأذون الخمسة: **إضافة / صرف / ارتداد / إهلاك / تسليم** |
| 2 | وصف عملية التصنيع + زرار **«انتهاء التصنيع»** + حساب وقت التصنيع + تنبيه كل الأقسام + طباعة ورقة الجودة |
| 3 | صورة **ورقة الجودة** (إدخال البيانات وطباعتها من البرنامج + ملؤها من قسم الجودة + اعتماد نهائي من مدير المصنع + تنبيه الأقسام) |
| 4 | **حساب الهالك (مقدمة)** + النوع الأول: **الفضلات** → ترجع بإذن ارتداد لمخزن الخامات بكود مختلف، وتظهر في كرت الصنف برصيد وقيمة، **ولا تُحمَّل على العملية** |
| 5 | النوع الثاني: **الهالك الطبيعي** (برادة من التقطيع) → لا وجود له في السيستم ويُحمَّل على العملية |
| 6 | النوع الثالث: **الهالك الغير طبيعي** (شريط كامل معيب) → بإذن إهلاك، يخفّض قيمة المخزون من كرت الصنف ويُرحَّل لحساب الهالك |

---

## 2) مقارنة المتطلبات بالموجود فعلاً

| المتطلب | الحالة | المرجع في الكود |
|---|---|---|
| إذن إضافة (اضافة) | ✅ موجود | `AdditionVoucher` + `AdditionVoucherService` |
| إذن صرف (صرف) | ✅ موجود | `IssueVoucher` + `IssueVoucherService::post()` |
| إذن تسليم (تسليم) | ✅ موجود | `DeliveryVoucher` + `DeliveryVoucherService` |
| محرّك المخزون + كرت الصنف (الحركات) | ✅ موجود | `InventoryService` (`transferStock/addStock/deductStock`)، `InventoryTransaction` (append-only)، `InventoryTransactionResource`، `filament/items/quick-view.blade.php`، `ViewItem` |
| تحميل/عكس التكلفة على العملية | ✅ موجود (تحميل فقط) | `IssueVoucherService::post()` يزود `project.actual_cost` + `workOrder.actual_material_cost` |
| **إذن ارتداد (ارتداد)** | ❌ غير موجود | — *(المرحلة 1)* |
| **مفهوم الفضلات بكود مختلف على كرت الصنف** | ❌ غير موجود | — *(المرحلة 1)* |
| **كرت الصنف يعرض الرصيد + القيمة (الأصل + الفضلات)** | 🟡 جزئي (يعرض الكميات بدون قيمة/فضلات) | `quick-view.blade.php`, `ViewItem` *(المرحلة 1)* |
| زرار «انتهاء التصنيع» + حساب المدة + تنبيه الأقسام | 🟡 جزئي (فيه `Complete` بسيط + `actual_start/end_date`) | `WorkOrderResource` action، `WorkOrder` *(المرحلة 2)* |
| ورقة الجودة (Model/Form/PDF/اعتماد المدير) | 🟡 جزئي (مجرد `qa_notes` + `qa_approved_by/at`) | `WorkOrder` *(المرحلة 3)* |
| إذن إهلاك (اهلاك) + الأنواع الثلاثة للهالك + القيود | ❌ غير موجود (يوجد فقط تتبّع كمية الهالك) | `ProductionEntry.scrap_quantity`, `WorkOrder.waste_quantity`, مجموعة صلاحيات `scrap`/`production_entries` *(المرحلة 4)* |

> **تنبيه مهم لتفادي اللبس:** المنظومة فيها بالفعل مجموعة صلاحيات `scrap` («Scrap / Loss») و`scrap.view` ممنوحة لـ `Factory_Manager`، وهي مرتبطة بتتبّع **كمية الهالك** في `ProductionEntry` — ده مفهوم **الهالك (المرحلة 4)** مش **الفضلات (المرحلة 1)**. الفضلات = خامة سليمة اتسحبت ولم تُستهلك وترجع للمخزن؛ الهالك = فاقد فعلي. المرحلة 1 تبني الفضلات كـ«مخزون قابل للإرجاع»، والمرحلة 4 توحّد معالجة الهالك محاسبياً.

---

## 3) خريطة المراحل الأربع (مرتّبة حسب الاعتمادية)

- **🟦 المرحلة 1 — إذن الارتداد + الفضلات + كرت الصنف** *(صور: 1 جزء الارتداد + 4)* ← المطلوبة الآن.
- **🟩 المرحلة 2 — زرار «انتهاء التصنيع» + حساب المدة + تنبيه الأقسام** *(صورة: 2 علوي)*.
- **🟨 المرحلة 3 — ورقة الجودة (إدخال + طباعة + اعتماد المدير + تنبيه)** *(صور: 2 سفلي + 3)*.
- **🟥 المرحلة 4 — حساب الهالك (إذن الإهلاك + الأنواع الثلاثة + القيود)** *(صور: 1 جزء الإهلاك + 5 + 6)*.

---

## 🟦 المرحلة 1 — إذن الارتداد + الفضلات + كرت الصنف (تفصيل التنفيذ)

### 4.1 النطاق (ماذا تُنجِز هذه المرحلة)
1. **إذن ارتداد (Return Voucher)** بنفس نمط `IssueVoucher`: يرجّع كمية الفضلات من **تحت التشغيل (WIP)** إلى **مخزن الخامات (RawMaterials)**، لكن تحت **كود مختلف (صنف الفضلات)**، ويعكس قيمتها عن العملية وأمر الشغل.
2. **الفضلات كصنف بكود مختلف** مربوط بالخامة الأصلية، يظهر على **كرت الصنف**.
3. **كرت الصنف** (الموجود) يتوسّع ليعرض **الرصيد + القيمة** للأصل ولفضلاته معاً (سلايد 4: «كام شريط وكام فضلات كرصيد وقيمة»).

### 4.2 قرار التصميم: تمثيل «الفضلات بكود مختلف»

> حسب عُرف الخطط ([[department-workflow]]): نختار الخيار المنطقي/الأقرب للمتطلبات بدون سؤال.

- **✅ المُعتمد — صنف فضلات منفصل مربوط بالأصل (Linked Scrap Variant):**
  لكل خامة، صنف فضلات مستقل بـ SKU مشتق (مثلاً `<sku>-SCRAP`)، `is_scrap = true`، و`scrap_source_item_id` يشير للأصل. إذن الارتداد يُرجِع الكمية إلى **مخزون الخامات لصنف الفضلات**. كرت صنف الأصل يُجمِّع رصيد + قيمة فضلاته.
  **لماذا:** يحقّق حرفياً «بكود مختلف» + «يظهر في كرت الصنف برصيد وقيمة»، ويعيد استخدام `Item/Inventory/InventoryTransaction` كما هي **بدون** مخزن رابع ولا تعديل على `WarehouseType`/`homeFor` ولا أثر على لوحات/حجوزات قائمة. الفضلات تبقى مخزوناً حقيقياً يمكن إعادة صرفه أو بيعه لاحقاً.

- **🔁 البديل المرفوض — مخزن فضلات منفصل (4th `WarehouseType::Scrap`):**
  نفس الصنف يتحرّك WIP → Scrap. أبسط ميكانيكياً (تحويل صنف واحد ذرّياً) لكنه **يخالف «بكود مختلف»**، ويكسر نموذج الـ 3 مخازن و`homeFor`، ويتطلب تعديلات متناثرة في الـ enums واللوحات ومنطق الحجز. لذلك مرفوض.

### 4.3 التغييرات بالملفات

**(أ) قاعدة البيانات — هجرتان جديدتان**
- `..._create_return_vouchers_tables.php` — يطابق `2026_06_01_000005_create_issue_vouchers_tables.php`:
  - `return_vouchers`: `id`, `voucher_number unique`, `work_order_id → work_orders cascadeOnDelete`, `voucher_date`, `status default('draft') index`, `total_value decimal(14,2) default 0`, `notes nullable`, `issued_by → users nullOnDelete`, `signed_by → users nullOnDelete`, `signed_at nullable`, `timestamps`, `softDeletes`.
  - `return_voucher_lines`: `id`, `return_voucher_id → return_vouchers cascadeOnDelete`, `item_id → items` (الصنف **الأصلي**), `quantity decimal(14,4)`, `unit_cost decimal(12,2) default 0`, `timestamps`.
- `..._add_scrap_link_to_items.php`:
  - `items.is_scrap boolean default false index`
  - `items.scrap_source_item_id` nullable FK → `items.id` `nullOnDelete`.

**(ب) Models**
- `app/Models/ReturnVoucher.php` — نسخة من `IssueVoucher` (نفس `fillable`/`casts`/`LogsActivity`/`SoftDeletes`)، `status ⇒ VoucherStatus` (نعيد استخدام enum draft/posted)، علاقات `workOrder/lines/issuedBy/signedBy`، `isPosted()`، و`generateVoucherNumber()` ببادئة `RTV-` (نفس قفل الـ Cache).
- `app/Models/ReturnVoucherLine.php` — نسخة من `IssueVoucherLine` + علاقة `item()`.
- `app/Models/Item.php` — إضافة `is_scrap`/`scrap_source_item_id` للـ `fillable`+`casts`، وعلاقتين: `scrapVariant(): HasOne` (الأبناء `where scrap_source_item_id = id`) و`scrapSource(): BelongsTo`. + helper `valueIn(WarehouseType)` = `quantityIn × unit_cost` لكرت الصنف.

**(ج) Service — `app/Services/ReturnVoucherService.php`** (يطابق فلسفة `IssueVoucherService`)
- `ensureScrapVariant(Item $original): Item` — find-or-create لصنف الفضلات (`<sku>-SCRAP`, `is_scrap=true`, `scrap_source_item_id`, نفس `unit`/`type=raw_material`, `unit_cost` منسوخة). إنشاء داخل `DB::transaction` مع قفل بسيط لتفادي التكرار.
- `createFromWorkOrder(WorkOrder $wo): ReturnVoucher` *(اختياري/مساعد)* — يفتح إذن **Draft** ببنود من الأصناف التي صُرفت لأمر الشغل (من `issueVouchers.lines`) بكميات صفر ليملأها أمين المخزن (الفضلات تُكتشف أثناء التصنيع، فلا تُملأ تلقائياً من الـ BOM).
- `post(ReturnVoucher $voucher): void` — منطق العكس (التفصيل في 4.4). Idempotent بالحالة.

**(د) Filament — `ReturnVoucherResource` + Pages**
- نسخة من `IssueVoucherResource`: نفس مجموعة التنقّل `navigation.groups.warehouse`، `navigationSort` بعد الصرف (مثلاً 43)، حقل `work_order_id`، Repeater للبنود (item الأصلي + quantity + unit_cost محجوب خلف `inventory.view_pricing`) + `ItemResource::quickViewAction()`، فلاتر الحالة + Trashed، و`postAction()` يستدعي `ReturnVoucherService::post()`.
- صفحات `List/Create/Edit` نسخة من نظائر الصرف.
- زرار في `WorkOrderResource` («ارتداد فضلات / Return Scrap») بجوار `issue_materials` الحالي، يفتح/يُنشئ إذن ارتداد للأمر، مرئي بشرط `return_vouchers.create` وحالة الأمر `InProgress`.

**(هـ) Policy — `app/Policies/ReturnVoucherPolicy.php`** نسخة من `IssueVoucherPolicy` على صلاحيات `return_vouchers.*`.

### 4.4 تدفّق `post()` خطوة بخطوة (جوهر المرحلة)
داخل `DB::transaction` (نفس نمط `IssueVoucherService::post`):
1. ارمِ خطأ لو `isPosted()` (`errors.voucher.already_posted`) أو لا توجد بنود (`errors.voucher.no_lines`).
2. لكل بند (الصنف الأصلي، كمية الفضلات، unit_cost):
   - `scrap = ensureScrapVariant($line->item)`.
   - `inventoryService->deductStock($line->item, qty, from: WorkInProgress, reference: $voucher, unitCost)` — يخرج الفضلات من رصيد الأصل تحت التشغيل (يتحقّق من الكفاية ويرمي `errors.inventory.insufficient_stock`).
   - `inventoryService->addStock($scrap, qty, warehouse: RawMaterials, reference: $voucher, unitCost)` — يدخلها مخزون صنف الفضلات (الكود المختلف).
   - `total += qty × unit_cost`.
3. **عكس التكلفة عن العملية:** `project->decrement('actual_cost', $total)` و`workOrder->decrement('actual_material_cost', $total)` — تحقيقاً لـ«الفضلات لا تُحمَّل على العملية».
4. حدّث الإذن: `status=Posted, total_value=$total, signed_by, signed_at`.

> ملاحظة ذرّية: الساقان عبر صنفين مختلفين فقفلان مختلفان (`InventoryService` يقفل لكل صنف). نلفّهما في `DB::transaction` خارجي كما يفعل `IssueVoucherService::post` تماماً (lock متداخل → savepoints، نمط قائم بالفعل).

### 4.5 RBAC
- صلاحيات جديدة في `RoleAndPermissionSeeder` catalog: `return_vouchers.view` / `.create` / `.post` (بعد كتلة `issue_vouchers.*`).
- المنح: `Admin` تلقائياً. منطقياً: `Warehouse_Manager` (يملك صرف create+post) و`Factory_Manager` (يملك صرف view+create) يأخذان الارتداد بنفس المستوى.
- **Gotcha مُسجّل ([[department-workflow]]):** السيدر لا يعدّل أدواراً موجودة → بعد التشغيل تُمنح صلاحيات الارتداد لـ `Warehouse_Manager`/`Factory_Manager` يدوياً عبر tinker/UI؛ Admin يأخذها آلياً.

### 4.6 i18n (EN/AR بتطابق تام)
- `lang/{en,ar}/resources.php`: كتلة `return_vouchers` (نسخة من `issue_vouchers`: label/sections/fields/columns/actions/notifications) + سطر `navigation`/الرابط ضمن مجموعة `warehouse`.
- كتل `roles.groups.return_vouchers` و`roles.permissions.return_vouchers.{view,create,post}` لواجهة إدارة الأدوار.
- مفاتيح كرت الصنف الجديدة (الفضلات/القيمة) تحت `resources.items.modal.*`.
- نعيد استخدام `errors.voucher.already_posted/no_lines` و`errors.inventory.insufficient_stock` (موجودة). لو لزم: `errors.return.*`.
- تأكيد تكافؤ عدد المفاتيح EN == AR.

### 4.7 كرت الصنف (الرصيد + القيمة)
- `resources/views/filament/items/quick-view.blade.php`: قسم «الفضلات» يظهر عند وجود `scrapVariant` — كمية الفضلات + قيمتها (`qty × unit_cost`، القيمة خلف `inventory.view_pricing`)، بجانب رصيد الأصل. ألوان Filament الدلالية فقط (success/warning/danger) لدعم light/dark + RTL تلقائياً.
- ترقية `ViewItem` لكرت صنف حقيقي: infolist (هوية + أرصدة لكل مخزن مع القيمة) + جدول حركات (`InventoryTransaction`) مفلتر على الصنف **وصنف فضلاته** — يحقّق «كرت الصنف يظهر فيه كام شريط وكام فضلات».

### 4.8 الثيم / RTL
- لا ألوان hex مباشرة؛ ألوان Filament الدلالية فقط، و`__()` في كل النصوص (يضمن RTL + light/dark تلقائياً) — مطابق لباقي الموارد.

### 4.9 الاختبارات — `tests/Feature/Manufacturing/ReturnVoucherTest.php`
- post يرحّل كمية الفضلات لمخزون **صنف الفضلات** (RawMaterials) وينقص رصيد الأصل في WIP.
- post **يعكس** `project.actual_cost` و`workOrder.actual_material_cost` بقيمة الفضلات.
- إذن مُرحَّل بالفعل يرمي خطأ (idempotent)؛ بنود فارغة ترمي خطأ؛ WIP غير كافٍ يرمي خطأ.
- `ensureScrapVariant` تُنشئ صنفاً بكود مختلف مرة واحدة (تكرار النداء لا يكرّر الصنف).
- كرت الصنف يُظهر رصيد + قيمة الفضلات.
- `ReturnVoucherFactory` + (اختياري) `ReturnVoucherPolicyTest`.
- تشغيل مجموعات Manufacturing + Inventory للتأكد من عدم الكسر.

### 4.10 الكاش (بعد التنفيذ)
`php artisan optimize:clear; filament:clear-cached-components; icons:clear; permission:cache-reset; npm run build; queue:restart` (وصفة [[department-workflow]] المعتادة).

### 4.11 معايير القبول (Definition of Done)
- [ ] أقدر أنشئ إذن ارتداد لأمر شغل، أملأ بنود الفضلات، وأرحّله.
- [ ] بعد الترحيل: رصيد الأصل في WIP نقص، ورصيد صنف الفضلات في الخامات زاد بنفس الكمية، بكود مختلف.
- [ ] `actual_cost` للعملية و`actual_material_cost` لأمر الشغل نقصا بقيمة الفضلات (لا تُحمَّل على العملية).
- [ ] كرت الصنف يعرض رصيد + قيمة الأصل والفضلات معاً.
- [ ] RBAC + i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## المراحل 2–4 (مخطط مختصر — تُفصَّل عند الوصول إليها)

### 🟩 المرحلة 2 — «انتهاء التصنيع» + الوقت + التنبيه
زرار مستقل على أمر الشغل يسجّل `actual_end_date`، يحسب مدة التصنيع من `actual_start_date`، ويطلق إشعار DB لكل الأقسام بأن المنتج جاهز — بالبناء على آلة حالة `WorkOrder` ونظام الإشعارات القائم (نمط `notifyNow` لتفادي مشكلة طابور الإنتاج المُسجّلة في [[sales-modifications-progress]]).

### 🟨 المرحلة 3 — ورقة الجودة
`QualitySheet` Model/Form/PDF بحقول العملية (نوع الموصل، عدد الأقطاب، اسم العملية) + أعمدة الاختبارات (L1-L2, L2-L3, PE-FE…)، إدخال وطباعة PDF (نمط `OfferPdfController`/mpdf)، ملء من الجودة، اعتماد نهائي من مدير المصنع، وتنبيه الأقسام. تعتمد على زرار الانتهاء (المرحلة 2).

### 🟥 المرحلة 4 — حساب الهالك (إذن الإهلاك + الأنواع الثلاثة)
إضافة حسابات الهالك/الفضلات لشجرة الحسابات (`ChartOfAccountsSeeder`)، و`DepreciationVoucher` (إهلاك) يخفّض قيمة المخزون من كرت الصنف ويرحّل لحساب الهالك مع قيد (`JournalEntry`/`OperationCostService`). معالجة الأنواع الثلاثة: الفضلات (لا تُحمَّل — مبنية في المرحلة 1) + الطبيعي (يُحمَّل على العملية) + الغير طبيعي (إهلاك ويُخرج من العملية). أعمق جزء محاسبي، وآخر مرحلة.

---

## ملاحظات / مخاطر
- **تمييز الفضلات عن الهالك:** الحفاظ على الفصل بين `scrap`/`production_entries` القائمة (كمية هالك/فاقد — المرحلة 4) وبين الفضلات القابلة للإرجاع (المرحلة 1) حتى لا تتداخل التسميات.
- **تقييم الفضلات:** نقيّمها بـ `unit_cost` للأصل حتى يكتمل عكس القيمة عن العملية؛ لو رغب العميل في «قيمة فضلات مخفّضة» فهي إضافة بسيطة لاحقاً.
- **الذرّية عبر صنفين:** ساقا الـ post (إخراج الأصل/إدخال الفضلات) في `DB::transaction` واحد كما في `IssueVoucherService::post`.
- بعد الموافقة بـ **«ابدأ التنفيذ»** أبدأ المرحلة 1 ملفاً ملفاً بالترتيب أعلاه.
