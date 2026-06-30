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

## 🟩 المرحلة 2 — زرار «انتهاء التصنيع» + حساب المدة + تنبيه الأقسام (تفصيل التنفيذ)

> **الصورة:** سلايد 2 (الجزء العلوي). النص: «تاريخ انتهاء التصنيع — بغض النظر عن جميع المراحل يجب وضع زر لانتهاء العملية في التصنيع ككل بدون النظر الى المراحل، لحساب وقت التصنيع وتنبيه بقية الأقسام بأن المنتج جاهز للتسليم».

### 5.1 النطاق (ماذا تُنجِز هذه المرحلة)
1. زرار **«انتهاء التصنيع»** على أمر الشغل، **مستقل عن آلة الحالة/بوابة الجودة** (يعمل من `InProgress` أو `QaReview`).
2. يسجّل **لحظة انتهاء التصنيع** ويحسب **مدة التصنيع** من `actual_start_date` ويخزّنها كرقم ثابت للتقارير.
3. يطلق **إشعاراً لكل الأقسام**: «المنتج جاهز للتسليم» — بالبناء على بنية `OperationActivated` / `NotifyDepartmentsOfActivation` القائمة، لكن بإرسال **متزامن** (`notifyNow`) لتفادي اعتماد الطابور.

### 5.2 قرار التصميم: علاقة الزرار بـ `complete()` القائم
> حسب عُرف الخطط ([[department-workflow]]): نختار الأقرب للمتطلب بدون سؤال.

- **✅ المُعتمد — إشارة مستقلة (Orthogonal Signal):** «انتهاء التصنيع» = حقل/حدث منفصل **لا يلمس المخزون ولا التكلفة ولا يتطلّب جودة**، يعيش **بجانب** بوابة الجودة و`complete()` (التي تظل الإغلاق المالي/المخزني الرسمي). يُخزَّن في عمود **جديد** `manufacturing_finished_at` **وليس** `actual_end_date`؛ لأن `WorkOrderService::complete()` يضبط `actual_end_date` بعد الجودة، فلو أعدنا استخدامه يتضارب الزرّان. هذا يطابق حرفياً «بدون النظر إلى المراحل» ويفصل **إشارة الجاهزية** عن **الإغلاق المحاسبي**.
- **🔁 البديل المرفوض — دمجه في `complete()`:** أبسط، لكنه يبقى محكوماً ببوابة الجودة (`isQaApproved()` + حالة `QaReview`) فيخالف «بغض النظر عن جميع المراحل»، ويخلط تنبيه «جاهز للتسليم» بحركة المخزون/التكلفة. مرفوض.

> **نتيجة مهمة:** بعد المرحلة 2، أمر الشغل ممكن يكون «انتهى تصنيعه» (إشارة + مدة + تنبيه) قبل أو بعد بوابة الجودة. الجودة و`complete()` تبقى كما هي بلا تعديل سلوكي.

### 5.3 التغييرات بالملفات

**(أ) قاعدة البيانات — هجرة واحدة جديدة** `..._add_manufacturing_finish_to_work_orders.php`:
- `manufacturing_finished_at` `timestamp` nullable.
- `manufacturing_duration_minutes` `unsignedInteger` nullable (لقطة المدة وقت الضغط — قيمة تقارير ثابتة).
- `manufacturing_finished_by` foreignId nullable → `users` `nullOnDelete`.

**(ب) Model — `app/Models/WorkOrder.php`**
- `fillable` + `casts`: الأعمدة الثلاثة (`manufacturing_finished_at ⇒ datetime`, `manufacturing_duration_minutes ⇒ integer`).
- `syncWritableFields()`: إضافة الثلاثة (المشغّل على أرضية المصنع يضغط الزرار عبر مزامنة العميل).
- علاقة `manufacturingFinishedBy(): BelongsTo` (User).
- `isManufacturingFinished(): bool` (`manufacturing_finished_at !== null`).
- Accessor `getManufacturingDurationHumanAttribute(): ?string` — `CarbonInterval::minutes($m)->cascade()->forHumans()` (يحترم لغة التطبيق → عربي/RTL تلقائياً).
- `getActivitylogOptions()`: إضافة `manufacturing_finished_at` لقائمة `logOnly` (أثر تدقيق).
- ⚠️ **مراجعة `app/Sync/Resolvers/WorkOrderStateMachineResolver.php`**: نتأكد أن ختم `manufacturing_finished_at` لا يصطدم بقواعد انتقال الحالة (هو حقل مستقل لا يغيّر `status`، فمن المتوقّع ألا يحتاج تعديلاً — يُتحقّق أثناء التنفيذ).

**(ج) Service — `app/Services/WorkOrderService.php`** ميثود جديدة:
```
finishManufacturing(WorkOrder $wo): void
```
- حارس: `actual_start_date` مضبوط والحالة ∈ {`InProgress`, `QaReview`} — وإلا `throw RuntimeException(errors.work_order.cannot_finish_manufacturing)`.
- Idempotent: لو `isManufacturingFinished()` ⇒ return بلا كتابة (نفس فلسفة باقي الأكشنات).
- `update(['manufacturing_finished_at' => now(), 'manufacturing_finished_by' => Auth::id(), 'manufacturing_duration_minutes' => $wo->actual_start_date?->diffInMinutes(now())])`.
- `ManufacturingFinished::dispatch($wo)` بعد التحديث.
- **لا يلمس المخزون ولا التكلفة** (هذا يبقى في `complete()`).

**(د) Event + Listener** (نسخة من نمط التنشيط)
- `app/Events/ManufacturingFinished.php` — يحمل `WorkOrder` (`Dispatchable` + `SerializesModels`)، مرآة `OperationActivated`.
- `app/Listeners/NotifyDepartmentsOfManufacturingFinish.php` — يجلب المستلمين بـ `whereHas('roles', ...)` من `config('manufacturing.finish_notify_roles')`، ويرسل **متزامناً** عبر `notifyNow($notification->toDatabase())` (نمط `SalesAlertService::sendNow`، **لا** `sendToDatabase` الذي يعتمد على عامل طابور — درس [[sales-modifications-progress]] / commit `de53656`). الإشعار يحمل زر Action يفتح أمر الشغل (أو إنشاء إذن تسليم).
- التسجيل: **اكتشاف Laravel التلقائي** للمستمعين (نفس ملاحظة `AppServiceProvider` سطر 64-66؛ لا حاجة لـ `Event::listen` يدوي).

**(هـ) Config** — `config/operations.php`: مفتاح جديد `manufacturing_finish_notify_roles` (تعليق عربي مثل البقية)، الافتراضي = نفس قائمة `activation_notify_roles` (كل الأقسام: `General_Manager, Technical_Office, Procurement, Factory_Manager, Warehouse_Manager, Finance, Sales_Manager`). أسماء أدوار غير معروفة تُتجاهَل؛ مصفوفة فارغة تعطّل التنبيه.

**(و) Filament — `WorkOrderResource`**
- أكشن جديد `finish_manufacturing` («انتهاء التصنيع»): أيقونة `heroicon-o-flag`، لون `primary`، `requiresConfirmation`، نمط idempotent بإعادة قراءة `fresh()` (مثل `complete`/`start`).
  - `visible`: `actual_start_date !== null && manufacturing_finished_at === null && status ∉ {Pending, Cancelled, Completed} && can('work_orders.finish_manufacturing')`.
  - يستدعي `WorkOrderService::finishManufacturing()` ويُظهر توست نجاح/فشل.
- عمود جدول `manufacturing_finished_at` (date, toggleable) + عمود/شارة «المدة» من `manufacturing_duration_human` (toggleable).
- في الـ form: `Placeholder` للقراءة (وقت الانتهاء + المدة + بواسطة مَن) داخل قسم الكميات/الجدول أو قسم مستقل صغير.

**(ز) Permission — `RoleAndPermissionSeeder`**
- إضافة `work_orders.finish_manufacturing` للـ catalog بعد `work_orders.complete`.
- الافتراضي: يُمنح لـ `Factory_Manager` (في `getDefaultRoleDefinitions`)؛ `Admin` آلياً.
- **Gotcha مُسجّل ([[department-workflow]]):** السيدر لا يعدّل أدواراً موجودة → بعد التشغيل تُمنح الصلاحية لـ `Factory_Manager` يدوياً (tinker/UI) لو الدور موجود مسبقاً.

### 5.4 RBAC
صلاحية واحدة جديدة `work_orders.finish_manufacturing`. القراءة/الرؤية محكومة بها على مستوى الأكشن. لا Policy جديدة (الأكشن على المورد القائم).

### 5.5 i18n (EN/AR بتطابق تام)
- `lang/{en,ar}/resources.php` تحت `work_orders`:
  - `actions.finish_manufacturing`.
  - `fields`: `manufacturing_finished_at`, `manufacturing_duration`, `manufacturing_finished_by`.
  - `columns`: `finished_at`, `duration`.
  - `notifications`: `manufacturing_finished` (توست الفاعل) + `ready_for_delivery_title` / `ready_for_delivery_body` (نص تنبيه الأقسام، مع `:product` / `:wo_number`).
- `lang/{en,ar}/errors.php`: `work_order.cannot_finish_manufacturing`.
- `lang/{en,ar}/resources.php` تحت `roles.permissions.work_orders`: تسمية `finish_manufacturing` لواجهة إدارة الأدوار.
- تأكيد تكافؤ عدد المفاتيح EN == AR.

### 5.6 الثيم / RTL
لا ألوان hex؛ ألوان Filament الدلالية فقط، و`__()` في كل النصوص. `forHumans()` للمدة عبر locale التطبيق (يعطي عربي + RTL تلقائياً) — مطابق لباقي الموارد.

### 5.7 الاختبارات — `tests/Feature/Manufacturing/ManufacturingFinishTest.php`
- `finishManufacturing` يضبط `manufacturing_finished_at` ويحسب `manufacturing_duration_minutes` من `actual_start_date` بدقّة.
- **Idempotent**: نداء ثانٍ لا يدهس القيمة الأولى.
- **حارس**: أمر شغل `Pending` (لم يبدأ) أو `Cancelled` يرمي خطأ.
- **حدث**: `ManufacturingFinished` يُطلَق (`Event::fake`) — نمط `OperationLifecycleTest`.
- **تنبيه**: المستمع ينشئ إشعار DB لأدوار الأقسام المُعدّة (تأكيد عبر `DatabaseNotification` count أو `Notification::fake` — انتبه أنّ `notifyNow` لا يمرّ بالطابور).
- **عدم تلوّث محاسبي/مخزني**: لا تُنشأ `InventoryTransaction` ولا يتغيّر `actual_material_cost` عند الانتهاء.
- accessor `manufacturing_duration_human` يُنسّق صحيحاً.

### 5.8 الكاش (بعد التنفيذ)
`php artisan optimize:clear; filament:clear-cached-components; icons:clear; permission:cache-reset; npm run build; queue:restart` (وصفة [[department-workflow]]).

### 5.9 معايير القبول (Definition of Done)
- [ ] زرار «انتهاء التصنيع» ظاهر لأمر شغل بدأ (InProgress/QaReview) ومخفي بعد الانتهاء أو لو لم يبدأ.
- [ ] الضغط يسجّل وقت الانتهاء + المدة المحسوبة، **بدون** أي حركة مخزون أو تغيير تكلفة.
- [ ] كل الأقسام المُعدّة تستلم إشعار «المنتج جاهز للتسليم» في الجرس فوراً (متزامن).
- [ ] لا تعارض مع بوابة الجودة/`complete()` (يعملان كما هما؛ `actual_end_date` لم يُمَس).
- [ ] RBAC + i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## 🟨 المرحلة 3 — ورقة الجودة (إدخال + طباعة + اعتماد المدير + تنبيه) — تفصيل التنفيذ

> **الصور:** سلايد 2 (الجزء السفلي) + سلايد 3.
> - سلايد 2: «يجب عند الضغط على زر انتهاء التصنيع — من خلال قسم الجودة — طباعة ورقة الجودة... ويجب عملها على البرنامج وطباعتها من خلال البرنامج».
> - سلايد 3: «إدخال البيانات على البرنامج ثم طباعتها + جميع بيانات العملية (نوع الموصل، عدد الأقطاب، اسم العملية) ويُملأ باقي الجدول من خلال قسم الجودة، واعتماده من مدير المصنع كاعتماد نهائي، ويكون هناك تنبيه لجميع الأقسام أن العملية تم الانتهاء من تصنيعها».

### 6.1 النطاق (ماذا تُنجِز هذه المرحلة)
1. **ورقة جودة منظَّمة (`QualitySheet`)** مربوطة بأمر الشغل، فيها **ترويسة بيانات العملية** (نوع الموصل، نوع التوصيل، مساحة المقطع، عدد الأقطاب، اسم العملية، تاريخ الاختبار) + **جدول بنود الاختبار** (الصفوف = بنود/قطع، الأعمدة = اختبارات العزل/الاستمرارية كما في الصورة).
2. **إدخال على البرنامج + طباعة PDF** (نمط `PurchaseOrderPdfController`/mpdf، عربي/إنجليزي) بشكل يطابق ورقة الجودة في الصورة (نموذج `PRF-01-01`) مع خانتَي توقيع: **مراقب الجودة** و**مدير المصنع**.
3. **تدفّق اعتماد من مرحلتين:** قسم الجودة يُدخل النتائج ويوقّع (`fill`) ← مدير المصنع يعتمد نهائياً (`approve`).
4. **تنبيه كل الأقسام** عند الاعتماد النهائي بأن العملية تم الانتهاء من تصنيعها — **حدث مستقل** عن تنبيه «جاهز للتسليم» في المرحلة 2.

### 6.2 قرارات التصميم
> حسب عُرف الخطط ([[department-workflow]]): نختار الأقرب للمتطلب بدون سؤال، ونوثّق البديل المرفوض.

- **✅ ورقة منظَّمة لا صورة مرفقة:** سلايد 3 ينصّ «يجب عملها **على البرنامج** وطباعتها **من خلال البرنامج**» → نمذجة **مُهيكَلة** (Model + بنود + طباعة)، **ليست** مجرد رفع scan. (لذلك لا نستخدم `Attachment` فقط.)
- **✅ أعمدة اختبار ثابتة (Discrete Columns):** أعمدة الاختبار في الصورة قياسية لهذا المنتج (`PE-(L1&L2&L3&N)`, `FE-(L1&L2&L3&N)`, `N-(L1&L2&L3)`, `L1-(L2&L3)`, `L2-L3`) → أعمدة نصّية مستقلة (تُخزَّن قيمة/نتيجة لكل خانة). أوضح للعرض والطباعة والاستعلام.
  - **🔁 البديل المرفوض — JSON عام للنتائج:** أمرن لو تغيّرت الاختبارات لكل منتج، لكنه يعقّد الطباعة والتحقّق ويخالف بساطة الصورة. لو لزم لاحقاً منتج بأعمدة مختلفة، الترقية لـ JSON إضافة محدودة.
- **✅ مورد مستقل مربوط بأمر الشغل (`WorkOrder hasMany QualitySheet`، عملياً واحدة):** ورقة لها بنود متعددة (`QualitySheet hasMany QualitySheetLine`). `hasMany` (لا `hasOne`) للسماح بإعادة اختبار/نسخة ثانية دون هجرة مستقبلية.
- **✅ العلاقة بزرار «انتهاء التصنيع» (المرحلة 2):** `WorkOrderService::finishManufacturing()` يستدعي — بعد ختم الانتهاء — `QualitySheetService::ensureForWorkOrder($wo)` (**find-or-create** لمسودة مملوءة ببيانات العملية من أمر الشغل/الصنف). هذا يحقّق حرفياً «عند الضغط على زر انتهاء التصنيع... ورقة الجودة»، ويبقى idempotent (لا يكرّر الورقة). الورقة تظل قابلة للإنشاء يدوياً من موردها أيضاً.
  - **🔁 البديل المرفوض — أكشن مستقل «إنشاء ورقة جودة»:** يفصل المرحلتين أكثر، لكنه يفقد الربط النصّي مع زرار الانتهاء. (نُبقي الإنشاء اليدوي متاحاً كمظلّة، لكن الـ auto-create هو المسار الأساسي.)
- **✅ تنبيه الاعتماد حدث مستقل (`QualitySheetApproved`):** سلايد 3 = تنبيه عند **اعتماد المدير النهائي للورقة** (جودة اجتازت + توقيع المصنع)، وهو مختلف دلالياً عن تنبيه المرحلة 2 (انتهاء فيزيائي/جاهز للتسليم). لذلك حدث + مستمع + مفتاح config منفصل، بنفس نمط الإرسال المتزامن (`notifyNow`).

### 6.3 التغييرات بالملفات

**(أ) قاعدة البيانات — هجرة واحدة جديدة** `..._create_quality_sheets_tables.php` (تطابق نمط `create_issue_vouchers_tables`):
- `quality_sheets`: `id`, `sheet_number unique`, `work_order_id → work_orders cascadeOnDelete`, `test_date`, بيانات العملية (`conductor_type`, `connection_type`, `cross_section`, `poles_count` unsignedInteger nullable, `operation_name`), `status default('draft') index`, توقيع الجودة (`qa_filled_by → users nullOnDelete`, `qa_filled_at` nullable, `qa_inspector_notes` nullable), اعتماد المصنع (`factory_approved_by → users nullOnDelete`, `factory_approved_at` nullable), `notes` nullable, `created_by → users nullOnDelete`, `timestamps`, `softDeletes`.
- `quality_sheet_lines`: `id`, `quality_sheet_id → quality_sheets cascadeOnDelete`, `line_no` unsignedInteger, `label` (بند رقم X / بالطلب), `piece_number` nullable, `assembly` nullable, `visual_quality` nullable, `required_size` nullable، أعمدة الاختبار النصّية النِلابل: `test_pe_l123n`, `test_fe_l123n`, `test_n_l12l3`, `test_l1_l2l3`, `test_l2_l3`, `notes` nullable, `timestamps`.

**(ب) Enum — `app/Enums/QualitySheetStatus.php`** (يطابق نمط `WorkOrderStatus`: `implements HasLabel, HasColor`): `Draft` (رمادي) / `QaFilled` (تحذير) / `Approved` (نجاح)، مع `getLabel()` عبر `__()`.

**(ج) Models**
- `app/Models/QualitySheet.php` — `fillable`/`casts` (`status ⇒ QualitySheetStatus`, `test_date ⇒ date`, `qa_filled_at`/`factory_approved_at ⇒ datetime`, `poles_count ⇒ integer`)، علاقات `workOrder/lines/qaFilledBy/factoryApprovedBy/createdBy`، `isApproved()`/`isQaFilled()`، `generateSheetNumber()` ببادئة `QS-` (نفس قفل الـ Cache في `ReturnVoucher`)، و`LogsActivity` (`logOnly: sheet_number, status, qa_filled_at, factory_approved_at`).
- `app/Models/QualitySheetLine.php` — `fillable`/`casts` + علاقة `qualitySheet()`.
- `app/Models/WorkOrder.php` — إضافة علاقة `qualitySheets(): HasMany`.

**(د) Service — `app/Services/QualitySheetService.php`**
- `ensureForWorkOrder(WorkOrder $wo): QualitySheet` — find-or-create لمسودة (داخل `DB::transaction` + قفل بسيط لتفادي التكرار): يملأ ترويسة بيانات العملية من `$wo` (الاسم/المشروع/الصنف) ويُولّد بنوداً افتراضية فارغة (مثلاً 10 بنود «بالطلب») ليملأها قسم الجودة. idempotent: يرجّع الموجودة لو فيه ورقة غير مُعتمدة.
- `fill(QualitySheet $sheet, array $lines, ?string $inspectorNotes): void` — حارس: ليست `Approved` (وإلا `errors.quality_sheet.already_approved`). يحدّث البنود + `status = QaFilled`, `qa_filled_by = Auth::id()`, `qa_filled_at = now()`.
- `approve(QualitySheet $sheet): void` — حارس: `status === QaFilled` (وإلا `errors.quality_sheet.not_filled`). idempotent لو `Approved`. يضبط `status = Approved`, `factory_approved_by`, `factory_approved_at`، ثم `QualitySheetApproved::dispatch($sheet)`.

**(هـ) Event + Listener** (نسخة من نمط `ManufacturingFinished`)
- `app/Events/QualitySheetApproved.php` — يحمل `QualitySheet` (`Dispatchable` + `SerializesModels`).
- `app/Listeners/NotifyDepartmentsOfQualityApproval.php` — **اكتشاف تلقائي**؛ يقرأ `config('operations.quality_approval_notify_roles')`، `whereHas('roles', ...)`، ويرسل **متزامناً** عبر `notifyNow($notification->toDatabase())` (نفس درس `de53656`/المرحلة 2). الإشعار: أيقونة `heroicon-o-shield-check`، نجاح، زر Action يفتح أمر الشغل أو الورقة، نص «اجتازت العملية الجودة واعتُمدت — تم الانتهاء من تصنيعها».

**(و) Config — `config/operations.php`** مفتاح جديد `quality_approval_notify_roles` (تعليق عربي)، الافتراضي = نفس قائمة الأقسام السبعة (مثل `manufacturing_finish_notify_roles`).

**(ز) Filament — `QualitySheetResource` + Pages** (نمط `IssueVoucherResource`)
- مجموعة التنقّل `navigation.groups.manufacturing`، `navigationSort` بعد أوامر الشغل (مثلاً 55)، أيقونة `heroicon-o-clipboard-document-check`.
- نموذج: قسم ترويسة بيانات العملية (work_order_id، نوع الموصل/التوصيل/المقطع/الأقطاب/الاسم، تاريخ) + **Repeater** للبنود (رقم البند/القطعة/التجميع/الجودة الظاهرية/المقاس + 5 أعمدة اختبار + ملاحظات) + قسم اعتماد (Placeholders للقراءة: حالة الجودة + اعتماد المصنع).
- جدول: `sheet_number`, `workOrder.wo_number`, `status` (badge), `qaFilledBy.name`, `factoryApprovedBy.name`, `test_date`؛ فلاتر الحالة + Trashed.
- أكشنات:
  - `fill` («ملء الجودة»): مرئي عند `status !== Approved` و`can('quality_sheets.fill')` — يفتح فورم بنود (أو يوجّه للتحرير) ويستدعي `QualitySheetService::fill()`، idempotent بإعادة قراءة `fresh()`.
  - `approve` («اعتماد مدير المصنع»): مرئي عند `status === QaFilled` و`can('quality_sheets.approve')`، `requiresConfirmation`، idempotent.
  - `print_ar` / `print_en` داخل `ActionGroup` (نمط الـ PO تماماً): `url(route('quality_sheets.pdf', [...]))` + `openUrlInNewTab`، مرئي بشرط `can('print', $record)`.
- **`WorkOrderResource`**: زرار/رابط «ورقة الجودة» (يفتح/يُنشئ الورقة عبر `ensureForWorkOrder`) بجوار `finish_manufacturing`، مرئي عند `manufacturing_finished_at !== null` و`can('quality_sheets.create')`.
- صفحات `List/Create/Edit` نسخة من نظائر الأذون.

**(ح) Policy — `app/Policies/QualitySheetPolicy.php`** (نمط `PurchaseOrderPolicy`): `viewAny/view/create/update` على `quality_sheets.{view,create}`، + `fill` (`quality_sheets.fill`)، `approve` (`quality_sheets.approve`)، `print` (`quality_sheets.print`)، `delete`. **اكتشاف تلقائي** (مثل `ReturnVoucherPolicy`).

**(ط) PDF**
- `app/Http/Controllers/QualitySheetPdfController.php` — نسخة من `PurchaseOrderPdfController`: `Gate::authorize('print', $sheet)`، اختيار `?lang` (ar/en)، تحميل العلائق، شعار base64، watermark، mpdf (`autoScriptToLang`/`autoLangToFont` لتشكيل عربي سليم)، اسم ملف `qs-<number>.pdf`.
- `resources/views/pdf/quality-sheet.blade.php` — ترويسة الشركة + بيانات العملية (نوع الموصل/الأقطاب/الاسم/التاريخ) + **جدول الاختبار** (أعمدة الصورة) + خانتا توقيع (مراقب الجودة + مدير المصنع) + تذييل `PRF-01-01`. نفس CSS وأسلوب `purchase-order.blade.php` (RTL تلقائي حسب `app()->getLocale()`).
- `routes/web.php` — `Route::middleware('auth')->get('quality-sheets/{qualitySheet}/pdf', [QualitySheetPdfController::class, 'show'])->name('quality_sheets.pdf')`.

**(ي) Permission — `RoleAndPermissionSeeder`**
- catalog: `quality_sheets.view/create/fill/approve/print` (بعد كتلة `work_orders.*`).
- الافتراضي: `Factory_Manager` يأخذ الخمسة (يملك `approve_qa` اليوم فهو مدير المصنع المعتمِد)؛ `Technical_Office`/`General_Manager` يأخذان `view`+`print`؛ `Admin` آلياً.
- **Gotcha مُسجّل ([[department-workflow]]):** السيدر لا يعدّل أدواراً موجودة → تُمنح صلاحيات الجودة يدوياً لـ `Factory_Manager` بعد التشغيل (tinker/UI).

### 6.4 تدفّق الاعتماد (جوهر المرحلة)
`finishManufacturing()` (م2) → `ensureForWorkOrder()` يُنشئ **Draft** ببيانات العملية وبنود فارغة → قسم الجودة يملأ النتائج ويوقّع (`fill` → `QaFilled`) → يطبع PDF للمراجعة → مدير المصنع يعتمد (`approve` → `Approved` + `QualitySheetApproved`) → المستمع يُشعِر **كل الأقسام** «تم الانتهاء من تصنيع العملية واعتماد جودتها» فوراً (متزامن).

### 6.5 RBAC
خمس صلاحيات جديدة `quality_sheets.{view,create,fill,approve,print}`. الفصل بين `fill` (قسم الجودة) و`approve` (مدير المصنع) يحقّق «يُملأ من الجودة ويُعتمد من المدير». لا تعارض مع `work_orders.approve_qa` القائمة (بوابة QA على أمر الشغل تبقى كما هي؛ ورقة الجودة طبقة توثيق/طباعة مستقلة).

### 6.6 i18n (EN/AR بتطابق تام)
- `lang/{en,ar}/resources.php`: كتلة `quality_sheets` كاملة (label/sections/fields/columns/actions/notifications/status + كتلة `pdf.*` لعناوين الطباعة والأعمدة والتوقيعات) + سطر `navigation` ضمن مجموعة `manufacturing`.
- كتل `roles.groups.quality_sheets` و`roles.permissions.quality_sheets.{view,create,fill,approve,print}`.
- `lang/{en,ar}/errors.php`: `quality_sheet.already_approved`, `quality_sheet.not_filled`.
- تأكيد تكافؤ عدد المفاتيح EN == AR (الجرد الحالي: resources 1298، errors 29 — تُحدَّث بالزيادة المتطابقة).

### 6.7 الثيم / RTL / طباعة عربية
لا ألوان hex في الواجهة؛ ألوان Filament الدلالية فقط، و`__()` في كل النصوص. الـ PDF يعيد استخدام CSS `purchase-order.blade.php` و`autoScriptToLang`/`autoLangToFont` (تشكيل عربي سليم + RTL تلقائي) — مطابق لنمط الطباعة القائم.

### 6.8 الاختبارات — `tests/Feature/Manufacturing/QualitySheetTest.php`
- `ensureForWorkOrder` تُنشئ مسودة ببيانات العملية وبنود، وتكون idempotent (نداء ثانٍ لا يكرّر الورقة).
- `fill` يحدّث البنود ويضبط `QaFilled` + `qa_filled_by/at`؛ منع الملء بعد الاعتماد يرمي خطأ.
- `approve` يتطلّب `QaFilled` (وإلا خطأ)، يضبط `Approved` + اعتماد المصنع، **ويُطلق** `QualitySheetApproved` (`Event::fake`)، وهو idempotent.
- المستمع يُنشئ إشعار DB لأدوار الأقسام المُعدّة (انتبه `notifyNow` لا يمرّ بالطابور).
- ربط المرحلة 2: `finishManufacturing()` يستدعي `ensureForWorkOrder` (تأكيد وجود ورقة بعد الانتهاء) **دون** أي حركة مخزون/تكلفة.
- `QualitySheetPolicy`: `fill/approve/print` محكومة بالصلاحيات (اختياري `QualitySheetPolicyTest`).
- `QualitySheetFactory` + `QualitySheetLineFactory`. تشغيل مجموعة Manufacturing كاملة لتأكيد عدم الكسر.

### 6.9 الكاش (بعد التنفيذ)
`php artisan optimize:clear; filament:clear-cached-components; icons:clear; permission:cache-reset; npm run build; queue:restart` (وصفة [[department-workflow]] — `npm run build` هنا مطلوب لوجود blade طباعة جديد، والـ route cache يلتقط route الـ PDF الجديد).

### 6.10 معايير القبول (Definition of Done)
- [ ] بعد «انتهاء التصنيع» تُنشأ مسودة ورقة جودة تلقائياً ببيانات العملية، وقابلة للإنشاء يدوياً كذلك.
- [ ] قسم الجودة يملأ بنود الاختبار ويوقّع (`QaFilled`)، ومدير المصنع يعتمد نهائياً (`Approved`).
- [ ] طباعة PDF عربي/إنجليزي تطابق شكل ورقة الجودة (جدول الاختبار + توقيعا الجودة والمصنع + `PRF-01-01`).
- [ ] عند الاعتماد النهائي تستلم كل الأقسام إشعار «تم الانتهاء من تصنيع العملية واعتماد جودتها» فوراً (متزامن)، مستقلاً عن تنبيه المرحلة 2.
- [ ] RBAC (`quality_sheets.*` بفصل fill/approve) + i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## 🟥 المرحلة 4 — حساب الهالك (إذن الإهلاك + الأنواع الثلاثة + القيود) — تفصيل التنفيذ

> **الصور:** سلايد 1 (جزء «إذن إهلاك») + سلايد 5 (الهالك الطبيعي) + سلايد 6 (الهالك الغير طبيعي).
> - سلايد 1: «اذن اهلاك (مختص باخراج الخامات من مخزن الخامات وترحيلها الى حساب الهالك وسوف يتم شرح الهالك في قسم المالية)».
> - سلايد 5: «الهالك الطبيعى — يَنتج من عملية التقطيع (برادة) … معالجته محاسبياً بأنه يُحمَّل على العملية … ليس له وجود في السيستم ويُحمَّل على العملية».
> - سلايد 6: «الهالك الغير طبيعى — شريط/لوح كامل به عيب جودة أو خطأ عامل، تكلفة عالية ولا يمكن تحميله على العملية … يُعالَج بإذن إهلاك: يُخفَّض قيمة المخزون من كرت الصنف ويُرحَّل إلى حساب الهالك».

### 7.1 النطاق (ماذا تُنجِز هذه المرحلة)
1. **حسابات الهالك في شجرة الحسابات** (`ChartOfAccountsSeeder`): إضافة حساب «هالك التصنيع» (مصروف). حسابا «المخزون» (`1300`) و«مصروفات تشغيل» (`5010`) موجودان ويُعاد استخدامهما.
2. **إذن إهلاك (`DepreciationVoucher`)** بنمط `ReturnVoucher`/`IssueVoucher`: يُخرج الكمية الهالكة من **تحت التشغيل (WIP)** فيُخفّض رصيد/قيمة الصنف على **كرت الصنف**، ويُرحّلها محاسبياً بقيد متوازن (`JournalEntry`).
3. **معالجة الأنواع الثلاثة محاسبياً** عبر حقل `loss_type` على الإذن:
   - **الفضلات (type 1):** مبنية في **المرحلة 1** (`ReturnVoucher`) — لا تُحمَّل على العملية، ولها رصيد/قيمة. لا عمل جديد هنا؛ مرجع فقط.
   - **الهالك الطبيعي (type 2):** يُحمَّل على العملية (لا عكس للتكلفة) ويُرحَّل لـ«مصروفات تشغيل».
   - **الهالك الغير طبيعي (type 3):** يُعكَس عن العملية ويُرحَّل لـ«حساب الهالك».
4. **القيد المحاسبي التلقائي** عند ترحيل الإذن (تحقيق «وترحيلها إلى حساب الهالك») — أول جسر آلي بين حركة المخزون والـ GL في منظومة التصنيع.

### 7.2 قرارات التصميم
> حسب عُرف الخطط ([[department-workflow]]): نختار الأقرب للمتطلب بدون سؤال، ونوثّق البديل المرفوض.

- **✅ إذن واحد بحقل `loss_type` يقود الفرع المحاسبي (Natural / Abnormal):** الإذن نفسه (Model+Lines+Service+Resource+Policy) نسخة من `ReturnVoucher`، لكن `post()` يتفرّع على `loss_type`. هذا يوحّد «معالجة الأنواع الثلاثة» في مورد واحد بدل موردين، ويُبقي الفرق المحاسبي **معلَناً وقابلاً للتدقيق** على مستوى البند.
  - الفرق الجوهري بين النوعين:

    | `loss_type` | إخراج من WIP | عكس تكلفة العملية | القيد (`JournalEntry`) |
    |---|---|---|---|
    | **طبيعي** (natural) | ✔ | ✘ (يبقى مُحمَّلاً) | Dr `5010 مصروفات تشغيل` (غير موسوم بالعملية) / Cr `1300 المخزون` |
    | **غير طبيعي** (abnormal) | ✔ | ✔ `decrement` | Dr `5060 هالك التصنيع` / Cr `1300 المخزون` |

- **✅ لا ازدواج حساب في مركز التكلفة:** `OperationCostService.materialsCost` يحسب من **أذون الصرف المرحَّلة**، و`ledgerExpenses` يحسب من **سطور GL الموسومة بالعملية** (`project_id = project`). لذلك قيد الهالك (للنوعين) **غير موسوم بالعملية** (`project_id = null`) فلا يدخل مركز التكلفة مرتين. النوع الطبيعي يبقى محمَّلاً على العملية عبر **إذن الصرف الأصلي** (المادة صُرفت بالفعل = «يُحمَّل على العملية») دون عكس؛ والنوع الغير طبيعي يُعكَس بنفس نمط `ReturnVoucherService` (decrement العمودين). هذا يطابق سلوك المرحلة 1 ولا يتطلّب تعديل `OperationCostService`.
  - **🔁 البديل المرفوض — وسم قيد الطبيعي بالعملية (`project_id = project`):** يبدو منطقياً («يُحمَّل على العملية») لكنه **يزدوج** مع قيمة إذن الصرف داخل `OperationCostService` (مرة كخامة، مرة كمصروف). مرفوض.

- **✅ القيد عبر `JournalEntryService::post()` بنمط `OperationPaymentService::postJournalFor`:** ننشئ `JournalEntry` بحالة Draft + سطرين (مدين/دائن) ثم `journals->post()` (يفرض التوازن والذرّية). نوع المستند `DocumentType::Settlement` (قيد تسوية `JV`) و`document_number = voucher_number` للتتبّع، فلا نوسّع enum «الثلاثة الملوّنة» في `Financial_Department.md`.
  - **🔁 البديل المرفوض — `DocumentType` جديد (`LossWriteoff/LV`):** أنظف للفلترة لكنه يلمس الـ enum المرتبط بسلايد المالية. مؤجَّل كتحسين اختياري؛ رقم الإذم على القيد يكفي للتتبّع.

- **✅ سقوط رشيق لو الحسابات غائبة (Graceful skip):** لو لم يُحلَّ حساب الهالك/المخزون بالكود (لم تُزرع الشجرة بعد)، تُنفَّذ حركة المخزون وعكس التكلفة ويُتخطّى القيد بصمت (نفس فلسفة `OperationPaymentService`)، فلا ينكسر الترحيل في بيئة بلا GL.

- **✅ الإخراج من تحت التشغيل (WIP) لأمر شغل:** الهالك حدث أثناء التصنيع، فالإذن مربوط بأمر شغل (`work_order_id` مطلوب، مرآة `ReturnVoucher`) ويُخرج من `WorkInProgress`. (إهلاك مخزني عام غير مرتبط بأمر شغل = توسعة مستقبلية خارج نطاق سلايدات التصنيع.)

### 7.3 التغييرات بالملفات

**(أ) قاعدة البيانات — هجرة واحدة جديدة** `..._create_depreciation_vouchers_tables.php` (تطابق `create_return_vouchers_tables`):
- `depreciation_vouchers`: `id`, `voucher_number unique`, `work_order_id → work_orders cascadeOnDelete`, `voucher_date`, `loss_type` string default `'abnormal'` index, `status default('draft') index`, `total_value decimal(14,2) default 0`, `journal_entry_id` nullable FK → `journal_entries` `nullOnDelete`, `notes nullable`, `issued_by → users nullOnDelete`, `signed_by → users nullOnDelete`, `signed_at nullable`, `timestamps`, `softDeletes`.
- `depreciation_voucher_lines`: `id`, `depreciation_voucher_id → cascadeOnDelete`, `item_id → items`, `quantity decimal(14,4)`, `unit_cost decimal(12,2) default 0`, `timestamps`.

**(ب) Enum — `app/Enums/LossType.php`** (`implements HasLabel, HasColor`): `Natural` (طبيعي، `warning`) / `Abnormal` (غير طبيعي، `danger`)، `getLabel()` عبر `__('resources.enums.loss_type.*')`.

**(ج) Models**
- `app/Models/DepreciationVoucher.php` — نسخة من `ReturnVoucher`: `fillable`/`casts` (`status ⇒ VoucherStatus`، `loss_type ⇒ LossType`، `total_value decimal:2`، `voucher_date date`، `signed_at datetime`)، علاقات `workOrder/lines/issuedBy/signedBy/journalEntry`، `isPosted()`، `generateVoucherNumber()` ببادئة `DPV-` (نفس قفل الـ Cache)، و`LogsActivity`.
- `app/Models/DepreciationVoucherLine.php` — نسخة من `ReturnVoucherLine` + علاقة `item()`.
- `app/Models/WorkOrder.php` — علاقة `depreciationVouchers(): HasMany` (للأكشن والتقارير).

**(د) Service — `app/Services/DepreciationVoucherService.php`** (يطابق `ReturnVoucherService` + نمط القيد في `OperationPaymentService`):
- المُنشئ يحقن `InventoryService` و`JournalEntryService`.
- `createFromWorkOrder(WorkOrder): DepreciationVoucher` *(مساعد)* — إذن Draft ببنود من الأصناف المصروفة للأمر (كميات 0 ليملأها المستخدم)، مرآة `ReturnVoucherService::createFromWorkOrder`.
- `post(DepreciationVoucher $voucher): void` — الحراس (مُرحَّل بالفعل → `errors.voucher.already_posted`؛ لا بنود موجبة → `errors.voucher.no_lines`)، ثم `DB::transaction`:
  1. لكل بند qty>0: `inventoryService->deductStock(item, qty, from: WorkInProgress, reference: $voucher, unitCost)` (يرمي `errors.inventory.insufficient_stock` لو WIP غير كافٍ)؛ `total += qty × unitCost`.
  2. لو `loss_type === Abnormal`: عكس التكلفة عن العملية — `project->decrement('actual_cost', $total)` + `workOrder->decrement('actual_material_cost', $total)` (تحقيقاً لـ«لا يمكن تحميلها على العملية»). النوع الطبيعي **لا يعكس** (يبقى محمَّلاً عبر إذن الصرف).
  3. `postLossJournal($voucher, $total)` — يحلّ `1300` (مخزون) وحساب الهالك حسب النوع (`5060` غير طبيعي / `5010` طبيعي) بالكود من `config`؛ لو حُلّا: ينشئ `JournalEntry` Draft (`DocumentType::Settlement`, `document_number=voucher_number`, `currency='EGP'`) + سطرين Dr lossAccount / Cr inventoryAccount (`amount=$total`, `project_id=null`)، ثم `journals->post($entry->fresh('lines'))`، و`voucher->journal_entry_id = entry->id`. لو غاب حساب → تخطٍّ صامت.
  4. `voucher->update(status=Posted, total_value=$total, signed_by, signed_at)`.

**(هـ) Config — `config/operations.php`** مفاتيح جديدة (تعليق عربي مثل البقية):
- `inventory_account_code => '1300'`
- `abnormal_loss_account_code => '5060'`
- `natural_loss_account_code => '5010'`

**(و) شجرة الحسابات — `database/seeders/ChartOfAccountsSeeder.php`**
- إضافة سطر `['5060', 'هالك التصنيع', $expense, 'EGP']` ضمن كتلة المصروفات (آمن: `firstOrCreate` على `code`). `1300`/`5010` موجودان.

**(ز) Filament — `DepreciationVoucherResource` + Pages** (نمط `ReturnVoucherResource`)
- مجموعة التنقّل `navigation.groups.warehouse`، `navigationSort` بعد الارتداد (مثلاً 44)، أيقونة `heroicon-o-fire` (إهلاك).
- النموذج: `work_order_id` + `Select` لـ `loss_type` (طبيعي/غير طبيعي) + Repeater للبنود (item + quantity minValue 0 + unit_cost محجوب خلف `inventory.view_pricing`) + `ItemResource::quickViewAction()`.
- الجدول: `voucher_number`, `workOrder.wo_number`, `loss_type` (badge), `status` (badge), `total_value` (خلف pricing)، `signed_at`؛ فلاتر الحالة + `loss_type` + Trashed.
- `postAction()` يستدعي `DepreciationVoucherService::post()` (idempotent بإعادة قراءة `fresh()` ونمط toast نجاح/فشل).
- صفحات `List/Create/Edit` نسخة من نظائر الارتداد.
- **`WorkOrderResource`**: أكشن `write_off_loss` («إهلاك / Write-off») بجوار `return_scrap`، يفتح/يُنشئ إذن إهلاك للأمر، مرئي عند حالة `InProgress` و`can('depreciation_vouchers.create')`.

**(ح) Policy — `app/Policies/DepreciationVoucherPolicy.php`** نسخة من `ReturnVoucherPolicy` على `depreciation_vouchers.*` (اكتشاف تلقائي).

**(ط) RBAC — `RoleAndPermissionSeeder`**
- catalog: `depreciation_vouchers.{view,create,post}` بعد كتلة `return_vouchers.*` (السطور 146–148).
- الافتراضي: `Warehouse_Manager` (الثلاثة، يملك ارتداد كامل) + `Factory_Manager` (view+create)؛ `Finance` (view) لمتابعة القيد؛ `Admin` آلياً.
- **Gotcha مُسجّل ([[department-workflow]]):** السيدر لا يعدّل أدواراً موجودة → تُمنح الصلاحيات يدوياً (tinker/UI) للأدوار القائمة بعد التشغيل.

### 7.4 i18n (EN/AR بتطابق تام)
- `lang/{en,ar}/resources.php`: كتلة `depreciation_vouchers` كاملة (label/sections/fields شاملة `loss_type` + columns/actions/notifications) + سطر `navigation` ضمن مجموعة `warehouse`؛ `enums.loss_type.{natural,abnormal}`؛ اسم حساب `هالك التصنيع` (إن لزم في عرض). كتل `roles.groups.depreciation_vouchers` و`roles.permissions.depreciation_vouchers.{view,create,post}`.
- `lang/{en,ar}/errors.php`: نعيد استخدام `voucher.already_posted`/`voucher.no_lines` و`inventory.insufficient_stock` (موجودة)؛ نضيف `depreciation.*` فقط لو لزم نص خاص.
- تأكيد تكافؤ عدد المفاتيح EN == AR (جرد بعد المرحلة 3: resources 1384، errors 31 — تُحدَّث بالزيادة المتطابقة).

### 7.5 الثيم / RTL
لا ألوان hex؛ ألوان Filament الدلالية فقط (`warning`/`danger`/`success`)، و`__()` في كل النصوص — مطابق لباقي الموارد (light/dark + RTL تلقائي).

### 7.6 الاختبارات — `tests/Feature/Manufacturing/DepreciationVoucherTest.php` (+ `DepreciationVoucherFactory`)
- **غير طبيعي:** post يُنقص رصيد الصنف في WIP، **ويعكس** `project.actual_cost` + `workOrder.actual_material_cost` بالقيمة، ويُنشئ قيداً **متوازناً ومُرحَّلاً** Dr `5060` / Cr `1300` (غير موسوم بالعملية)، ويضبط `journal_entry_id`.
- **طبيعي:** post يُنقص WIP، **لا يعكس** تكلفة العملية، ويُنشئ قيد Dr `5010` / Cr `1300`.
- **حراس:** إذن مُرحَّل يرمي خطأ (idempotent)؛ بنود فارغة ترمي خطأ؛ WIP غير كافٍ يرمي خطأ.
- **القيد:** متوازن (`isBalanced`) ومُرحَّل (`JournalStatus::Posted`)؛ مسار «الحسابات غائبة» يتخطّى القيد بنجاح دون كسر حركة المخزون.
- تشغيل مجموعات Manufacturing + المالية/GL (`TrialBalance`/`OperationCost`/`OperationPayment`) للتأكد من عدم الكسر.

### 7.7 الكاش (بعد التنفيذ)
`php artisan optimize:clear; filament:clear-cached-components; icons:clear; db:seed --class=ChartOfAccountsSeeder; db:seed --class=RoleAndPermissionSeeder; permission:cache-reset; npm run build; queue:restart` (وصفة [[department-workflow]] + بذر الشجرة لإنشاء `5060` + بذر الصلاحيات؛ المنح اليدوية للأدوار القائمة كالعادة). لا blade/route جديد ⇒ `npm run build` احتياطي فقط.

### 7.8 معايير القبول (Definition of Done)
- [ ] أقدر أنشئ إذن إهلاك لأمر شغل، أختار النوع (طبيعي/غير طبيعي)، أملأ البنود، وأرحّله.
- [ ] بعد الترحيل: رصيد الصنف في WIP نقص وقيمته على كرت الصنف انخفضت.
- [ ] **غير طبيعي:** `actual_cost`/`actual_material_cost` نقصا (خرج عن العملية)، وقيد متوازن Dr `5060 هالك التصنيع` / Cr `1300 المخزون` مُرحَّل.
- [ ] **طبيعي:** التكلفة بقيت على العملية (لا عكس)، وقيد Dr `5010 مصروفات تشغيل` / Cr `1300` مُرحَّل.
- [ ] القيد **غير موسوم بالعملية** فلا يزدوج في مركز التكلفة؛ مسار بلا GL لا يكسر الترحيل.
- [ ] RBAC (`depreciation_vouchers.*`) + i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## ملاحظات / مخاطر
- **تمييز الفضلات عن الهالك:** الحفاظ على الفصل بين `scrap`/`production_entries` القائمة (كمية هالك/فاقد — المرحلة 4) وبين الفضلات القابلة للإرجاع (المرحلة 1) حتى لا تتداخل التسميات.
- **تقييم الفضلات:** نقيّمها بـ `unit_cost` للأصل حتى يكتمل عكس القيمة عن العملية؛ لو رغب العميل في «قيمة فضلات مخفّضة» فهي إضافة بسيطة لاحقاً.
- **الذرّية عبر صنفين:** ساقا الـ post (إخراج الأصل/إدخال الفضلات) في `DB::transaction` واحد كما في `IssueVoucherService::post`.
- **حساب الهالك (المرحلة 4):** القيد آلي عبر `JournalEntryService::post` (يفرض التوازن)؛ يبقى خارج مركز التكلفة (`project_id=null`) لتفادي الازدواج. الجسر الوحيد القائم بين المخزون والـ GL قبلها كان `OperationPaymentService` (مُعطَّل افتراضياً)، فالمرحلة 4 أول ترحيل آلي لقيد من حركة مخزون.
- **الحالة:** المراحل 1–3 **منفَّذة** (المرحلة 1 مُلتزَمة، 2–3 غير مُلتزَمة). المرحلة 4 مكتوبة بتفصيل التنفيذ الكامل (قسم 7) وتنتظر **«ابدأ التنفيذ»** لأبدأها ملفاً ملفاً بالترتيب أعلاه.
