# خطة: قائمة المواد التلقائية (Standard BOM) + المرونة والتعديل اليدوي

> **المصدر:** `مكتب ادارة المشروعات.pptx` (11 سلايد) — جولة تعديلات جديدة على قسم التصنيع/مكتب ادارة المشروعات.
> **هذا الملف يغطّي جزءاً واحداً فقط بتفصيل التنفيذ الكامل:** سلايد 6 — **«قائمة المواد التلقائية Standard BOM» + «المرونة والتعديل اليدوي Flexible Consumption / Manual Override»**.
> باقي أجزاء الجولة (السلايدات 1، 2–4، 5، 7–9، 10–11) مذكورة كخريطة طريق مختصرة في نهاية الملف فقط — **لن تُنفَّذ الآن**.
> يتبع نفس عُرف `Manufacturing_Department_Plan.md` (تحليل → قرار تصميم → ملفات → RBAC → i18n → ثيم → اختبارات → كاش → معايير قبول).

---

## 1) نصّ المتطلب (سلايد 6)

> **«بخصوص مكتب ادارة المشروعات هو من يضيف امر التصنيع ويضيفه بالمنتج النهائى.»**
>
> **قائمة المواد التلقائية — Standard BOM:**
> «عند اختيار "كود المنتج التام" Finished Good Code في طلب التصنيع، يجب أن يقوم السيستم تلقائياً بجلب "كميات الخامات القياسية" اللازمة لإنتاج هذا المنتج بناءً على تركيبة المنتج التي سندخلها لاحقاً (بمجرد جهوزية السيستم).»
>
> **المرونة والتعديل اليدوي — Flexible Consumption / Manual Override:**
> «نحتاج إلى مرونة كاملة Flexibility داخل جدول الخامات في طلب التصنيع. مثال: المنتج التام القياسي (بارة 3000 أمبير) يحتاج في الأساس إلى 5 شرائط نحاس، يجب أن يتيح السيستم للمستخدم إمكانية تعديل هذه الكمية يدوياً في الطلب الحالي (لتصبح 4.5 أو 5.2 مثلاً) دون أن يؤثر هذا التعديل المؤقت على التركيبة الأساسية والثابتة للمنتج في قاعدة البيانات.»

**خلاصة المتطلب في جملتين:**
1. **تركيبة منتج قياسية** مرتبطة بكود المنتج التام (Finished Good) — تُدخَل مرة واحدة وتبقى ثابتة.
2. عند اختيار المنتج في أمر التصنيع، تُنسخ كمياتها القياسية إلى **جدول خامات خاص بهذا الأمر**، **قابل للتعديل اليدوي بحرية** لهذا الأمر فقط دون المساس بالتركيبة القياسية.

---

## 2) الوضع الحالي في الكود (ما الموجود فعلاً)

| العنصر | الحالة | المرجع |
|---|---|---|
| نموذج BOM | ✅ موجود لكنه **مربوط بالمشروع** لا بالمنتج | `Bom` (`project_id`, `version`, `status`) → `items()` |
| بنود BOM | ✅ موجود | `BomItem` (`item_id`, `quantity`, `waste_percentage`, `notes`) + `getTotalRequiredQuantityAttribute` (كمية + هدر) |
| ربط أمر التصنيع بالمنتج التام | ✅ موجود | `WorkOrder.output_item_id` → `outputItem()` (فلتر `finished_good`/`semi_finished`) |
| ربط أمر التصنيع بـ BOM | ✅ موجود (BOM المشروع) | `WorkOrder.bom_id` → `bom()` |
| بناء الصرف من BOM | ✅ موجود | `IssueVoucherService::createFromWorkOrder()` يقرأ `bom.items` |
| صرف تلقائي عبر Job | ✅ موجود | `ProcessWorkOrderMaterialsJob` (يستدعي `createFromWorkOrder` + `post`) |
| أنواع الأصناف | ✅ موجود | `ItemType` {raw_material, finished_good, semi_finished, consumable} |
| **تركيبة منتج قياسية مرتبطة بكود المنتج التام** | ❌ **غير موجود** | — *(هذا الجزء)* |
| **جلب تلقائي للخامات عند اختيار المنتج التام** | ❌ **غير موجود** | نموذج `WorkOrderResource` لا يفعل شيئاً عند تغيير `output_item_id` |
| **جدول خامات قابل للتعديل لكل أمر تصنيع (Manual Override)** | ❌ **غير موجود** | الصرف يُبنى مباشرة من بنود الـ BOM بلا طبقة تعديل |

**الفجوة الأساسية:** الـ BOM الحالي «BOM مشروع» (نسخة لكل مشروع)، بينما سلايد 6 يريد «BOM منتج قياسي» (تركيبة ثابتة لكل كود منتج تام) + طبقة تعديل يدوي على مستوى أمر التصنيع.

---

## 3) قرارات التصميم

> حسب عُرف الخطط ([[department-workflow]]): نختار الأقرب للمتطلب بدون سؤال، ونوثّق البديل المرفوض.

### قرار 1 — «تركيبة المنتج القياسية» = BOM مربوط بالمنتج التام (Product-scoped Standard BOM)

- **✅ المُعتمد — توسيع `Bom` ليكون قابلاً للربط بمنتج تام:**
  إضافة عمود nullable `output_item_id` على `boms` + جعل `project_id` **nullable**. يظهر مفهومان من نفس الجدول:
  - **BOM قياسي (Standard/Library):** `output_item_id` مضبوط، `project_id = null` → «تركيبة المنتج الثابتة» المطلوبة في السلايد.
  - **BOM مشروع (القائم حالياً):** `project_id` مضبوط → يبقى كما هو بدون كسر.
  علاقة جديدة `Bom::outputItem()` وعلاقة عكسية `Item::standardBoms()`؛ نختار «آخر BOM قياسي مُعتمد» للمنتج عند الجلب.
  **لماذا:** يعيد استخدام `Bom`/`BomItem`/`BomResource`/`BomPolicy` بالكامل (نفس الفورم والبنود والاعتماد والتكلفة)، ويحقّق حرفياً «تركيبة المنتج» بأقل هجرة (عمودان + إسقاط NOT NULL). لا حاجة لجدول/مورد جديد لتعريف التركيبة.

- **🔁 البديل المرفوض — جدول `product_boms` مستقل جديد:**
  أنظف مفاهيمياً (فصل تام بين تركيبة المنتج وBOM المشروع) لكنه يكرّر مورد Filament كاملاً + منطق التكلفة + السياسة + i18n، ويضاعف الصيانة بلا مكسب حقيقي — التركيبتان لهما نفس الشكل (بنود item+quantity+waste). مرفوض.

### قرار 2 — «المرونة والتعديل اليدوي» = جدول خامات لكل أمر تصنيع (Per-WO Material Lines)

- **✅ المُعتمد — جدول `work_order_materials` مستقل + Repeater في فورم أمر التصنيع:**
  عند اختيار `output_item_id` (أو بزرّ «جلب الخامات القياسية») تُنسخ بنود BOM المنتج القياسي إلى بنود أمر التصنيع (مُقاسة على `planned_quantity`)، ثم يعدّلها المستخدم بحرية لهذا الأمر فقط. **مصدر الحقيقة للصرف يتحول إلى بنود أمر التصنيع** (لا بنود الـ BOM).
  **لماذا:** يحقّق حرفياً «تعديل الكمية يدوياً في الطلب الحالي دون أن يؤثر على التركيبة الثابتة» — التعديل يعيش على نسخة الأمر لا على الـ BOM. ويجعل الصرف يعكس ما اتصرف فعلاً (يمهّد لسلايد 7–9: مخطط=BOM، فعلى=الصرف، فاقد=الفرق).

- **🔁 البديل المرفوض — تعديل بنود الـ BOM مباشرة قبل الصرف:**
  أبسط (لا جدول جديد) لكنه يخالف صراحةً «دون التأثير على التركيبة الثابتة» — أي تعديل يلوّث التركيبة القياسية لكل الأوامر التالية. مرفوض.

### قرار 3 — سلوك الجلب: نسخ لمرة واحدة لا ربط حيّ (Copy-on-select, not live-link)

- **✅ المُعتمد:** الجلب **ينسخ** كميات التركيبة إلى بنود الأمر مرة واحدة (مع تحجيمها على الكمية المخططة). بعدها الأمر مستقل تماماً عن الـ BOM. زرّ «إعادة الجلب» يعيد الملء (باستبدال البنود بعد تأكيد) لو غيّر المستخدم المنتج أو الكمية.
  **لماذا:** يطابق «تعديل مؤقت لا يؤثر على التركيبة»، ويتجنّب تعقيد المزامنة الحيّة، ويحفظ لقطة ثابتة للصرف والمقارنة.

---

## 4) التغييرات بالملفات

### (أ) قاعدة البيانات — هجرتان

**هجرة 1** `..._add_output_item_to_boms.php`:
- `boms.output_item_id` foreignId **nullable** → `items` `nullOnDelete`, مع `index`.
- تعديل `boms.project_id` ليصبح **nullable** (كان NOT NULL) — عبر `->change()` (يتطلب `doctrine/dbal` — نتحقق من وجوده؛ موجود في `vendor/`).
- (اختياري تحقّقي) قيد تطبيقي في الكود: BOM إما `project_id` أو `output_item_id` (لا يُفرَض على مستوى DB لتفادي تعقيد CHECK عبر المحرّكات).

**هجرة 2** `..._create_work_order_materials_table.php` (تطابق نمط `issue_voucher_lines`):
- `work_order_materials`: `id`, `work_order_id → work_orders cascadeOnDelete`, `item_id → items` (restrict/`cascadeOnDelete` حسب نمط البنود القائم), `quantity decimal(14,4)`, `unit_cost decimal(12,2) default 0`, `notes nullable`, `is_manual boolean default false` (علامة أن السطر عُدّل/أُضيف يدوياً — للتقارير)، `timestamps`.

### (ب) Models

- `app/Models/Bom.php`:
  - `fillable` += `output_item_id`.
  - علاقة `outputItem(): BelongsTo` (Item, `output_item_id`).
  - scope `scopeStandard()` = `whereNotNull('output_item_id')` و`scopeForProject()` كما هو ضمناً.
  - `getActivitylogOptions` += `output_item_id`.
- `app/Models/Item.php`:
  - علاقة `standardBoms(): HasMany` (Bom, `output_item_id`).
  - helper `latestApprovedStandardBom(): ?Bom` = `standardBoms()->where('status', BomStatus::Approved)->latest()->first()` (أو أعلى `version`).
- `app/Models/WorkOrder.php`:
  - علاقة `materials(): HasMany` (WorkOrderMaterial).
  - `getPlannedMaterialCostAttribute()` = `sum(quantity × unit_cost)` لبنود الأمر (يُستخدم كـ«المخطط» في المقارنة).
- `app/Models/WorkOrderMaterial.php` — **جديد** (نسخة من `IssueVoucherLine`): `fillable` (`work_order_id`, `item_id`, `quantity`, `unit_cost`, `notes`, `is_manual`)، `casts` (`quantity`/`unit_cost` decimal، `is_manual` bool)، علاقتا `workOrder()` و`item()`، `LogsActivity`، و`Syncable` مع `syncWritableFields()` مناسب (المكتب يؤلّف؛ الأرضية للقراءة — على الأرجح `[]` مثل `BomItem`).

### (ج) Service — `app/Services/WorkOrderMaterialService.php` (جديد)

- `fetchStandardMaterials(WorkOrder $wo): array` — يجلب `latestApprovedStandardBom()` لـ `outputItem`، يبني بنوداً بكميات = `bomItem->total_required_quantity × planned_quantity` (تحجيم على الكمية المخططة) و`unit_cost` من كرت الصنف (`item->unit_cost`). يرمي `errors.work_order.no_standard_bom` لو لا توجد تركيبة. **دالة نقية تُرجع مصفوفة** (يستهلكها الفورم/الأكشن دون كتابة مباشرة).
- `syncMaterials(WorkOrder $wo, array $lines): void` — يستبدل بنود الأمر داخل `DB::transaction` (حذف الحالي + إنشاء الجديد) — يستخدمه زرّ إعادة الجلب.

### (د) rewire الصرف — `app/Services/IssueVoucherService.php`

- `createFromWorkOrder()` يقرأ الآن من **بنود أمر التصنيع** أولاً:
  - لو `workOrder->materials` غير فارغة → بناء بنود الصرف منها (كمية + تكلفة).
  - وإلا (توافق خلفي) → السلوك القديم من `bom.items`.
  - رسالة الخطأ تتوسّع: `errors.issue.no_materials` بدل `no_bom` عند غياب الاثنين.
- **أثر جانبي مقصود:** الصرف يصبح انعكاساً لِما قرّره المكتب فعلاً (بعد التعديل اليدوي) لا للتركيبة القياسية الخام — أساس «الفعلى» في سلايد 9 لاحقاً.

### (هـ) Filament

- `app/Filament/Resources/BomResource.php`:
  - إضافة `Select::make('output_item_id')` (فلتر `whereIn type finished_good/semi_finished`, `getOptionLabelFromRecordUsing` sku — name) بجوار `project_id`، **كلاهما غير مطلوب فردياً** لكن مطلوب أحدهما (تحقّق `->rule` مخصّص أو رسالة).
  - عمود جدول «المنتج التام» (`outputItem.sku`) + شارة نوع الـ BOM (قياسي/مشروع).
- `app/Filament/Resources/WorkOrderResource.php`:
  - **Repeater `materials`** (relationship) في قسم جديد «جدول الخامات»: كل سطر = `Select item_id` (raw_material/consumable, `notScrap`) + `TextInput quantity` (numeric) + `TextInput unit_cost` (numeric، محجوب خلف `inventory.view_pricing`، يُملأ افتراضياً من `item->unit_cost` عبر `afterStateUpdated`). `reorderable(false)`، `addable`/`deletable` = true (المرونة الكاملة).
  - على `output_item_id` → `live()` + `afterStateUpdated`: لو الجدول فارغ، جلب الخامات القياسية تلقائياً وملء الـ Repeater (عبر `$set('materials', ...)`). لو غير فارغ → لا يدهس (يحترم التعديل اليدوي) بل يُترك لزرّ الجلب.
  - زرّ فورم `fetch_standard_materials` («جلب الخامات القياسية»، أيقونة `heroicon-o-arrow-down-tray`): يستدعي `WorkOrderMaterialService::fetchStandardMaterials` ويملأ الـ Repeater بعد `requiresConfirmation` (لأنه يستبدل). مرئي عند وجود `output_item_id`.
  - Placeholder «تكلفة الخامات المخططة» = `planned_material_cost` (للقراءة).

### (و) Policy

- لا سياسة جديدة: بنود أمر التصنيع تُدار داخل `WorkOrderResource` (محكومة بصلاحيات `work_orders.*` القائمة). BOM القياسي يُدار بـ `BomPolicy` القائمة. **لا RBAC جديد للأصناف/الأدوار** (نقطة تبسيط مقصودة).

---

## 5) RBAC

- **لا صلاحيات جديدة.** تأليف التركيبة القياسية = صلاحيات `boms.*` القائمة (مكتب ادارة المشروعات/المكتب الفنى يملكها). تحرير جدول خامات الأمر = صلاحيات `work_orders.create/update` القائمة.
- تُذكر ملاحظة [[department-workflow]] فقط لو لزم منح لاحق — لا ينطبق هنا.

---

## 6) i18n (EN/AR بتطابق تام)

- `lang/{en,ar}/resources.php`:
  - `boms.fields.output_item` + `boms.columns.output_item` + `boms.bom_type.{standard,project}`.
  - `work_orders.sections.materials` + `work_orders.fields.material_item/material_quantity/material_unit_cost/planned_material_cost` + `work_orders.actions.fetch_standard_materials` + `work_orders.notifications.materials_fetched`.
- `lang/{en,ar}/errors.php`: `work_order.no_standard_bom`, `issue.no_materials` (توسيع/إضافة).
- تأكيد تكافؤ عدد المفاتيح EN == AR (الجرد الحالي المُسجّل: resources 1417 / errors 31 — تُحدَّث بزيادة متطابقة).

## 7) الثيم / RTL

لا ألوان hex؛ ألوان Filament الدلالية فقط، و`__()` في كل النصوص (RTL + light/dark تلقائي) — مطابق لباقي الموارد.

---

## 8) الاختبارات — `tests/Feature/Manufacturing/StandardBomTest.php`

- `latestApprovedStandardBom` تُرجع أحدث BOM قياسي مُعتمد للمنتج (وتتجاهل BOM المشروع وغير المُعتمد).
- `fetchStandardMaterials` تبني بنوداً بكميات = كمية التركيبة (شاملة الهدر) × الكمية المخططة، وبتكلفة كرت الصنف؛ وترمي خطأ لو لا توجد تركيبة قياسية.
- **المرونة:** تعديل كمية بند في أمر التصنيع **لا يغيّر** بنود الـ BOM القياسي في DB (تأكيد ثبات التركيبة).
- `IssueVoucherService::createFromWorkOrder` يبني الصرف من **بنود أمر التصنيع** عند وجودها (لا من bom.items)، ويحافظ على السلوك القديم عند غيابها (توافق خلفي — يبقى اختبار الصرف القائم أخضر).
- `WorkOrderMaterialFactory` + إبقاء `BomFactory` منتجاً لـ BOM قياسي (state `standard()`).
- تشغيل مجموعة Manufacturing كاملة + اختبارات `IssueVoucher`/`Bom` القائمة لتأكيد عدم الكسر.

---

## 9) الكاش (بعد التنفيذ)

`php artisan optimize:clear; filament:clear-cached-components; icons:clear; migrate; permission:cache-reset; npm run build; queue:restart` (وصفة [[department-workflow]]؛ `npm run build` احتياطاً لتغيّرات الفورم، و`migrate` للهجرتين).

---

## 10) معايير القبول (Definition of Done)

- [ ] أقدر أعرّف **تركيبة منتج قياسية** (BOM مربوط بكود منتج تام) من مورد الـ BOM، وتبقى ثابتة.
- [ ] عند اختيار المنتج التام في أمر التصنيع، تُجلب كميات الخامات القياسية تلقائياً في جدول الخامات (مُقاسة على الكمية المخططة).
- [ ] أقدر أعدّل الكميات يدوياً في جدول أمر التصنيع (4.5 / 5.2 …) **دون** أن تتغيّر التركيبة القياسية في قاعدة البيانات.
- [ ] إذن الصرف يُبنى من جدول خامات الأمر (بعد التعديل) لا من التركيبة الخام؛ والصرف القديم (BOM مشروع) ما زال يعمل.
- [ ] i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## ملحق — خريطة باقي أجزاء الجولة (لن تُنفَّذ الآن)

| الجزء | السلايد | ملخص | تبعية على هذا الجزء؟ |
|---|---|---|---|
| إعادة التسمية | 1 | «أوامر التشغيل»→«أوامر التصنيع»، «المكتب الفنى»→«مكتب ادارة المشروعات» (i18n بحت) | لا |
| حقول ورقة الجودة | 2–4 | حقول جديدة (مساحة المقطع/الجسم الخارجى/درجة الحماية/الدهان/الطراز/الأمبير) + حذف نوع التوصيل + checkboxes + قراءتان لكل خانة | لا (يبني على Phase 3 السابق) |
| صرف من أمر التصنيع + تكلفة تلقائية | 7–8 | إذن الصرف يسحب كود الخامة ويحوّل المنتج التام→خامات؛ تكلفة الوحدة تلقائية من كرت الصنف (آخر تسعير) | **نعم** (يستهلك جدول خامات الأمر) |
| تقرير الإنتاج والفاقد | 9 | مخطط (قيمة أمر التصنيع) / فعلى (قيمة الصرف) / فاقد (الفرق) + نسبة الفاقد | **نعم** («المخطط» = تكلفة خامات الأمر) |
| ملخص الأدوار + للمخزون | 10–11 | توثيق الأدوار؛ إضافة المنتج التام لمخزون التام عند انتهاء التصنيع | لا |

> **ملاحظة تسلسل:** هذا الجزء (Standard BOM + جدول خامات الأمر) هو **الأساس** لأجزاء 7–9، لذا تنفيذه أولاً منطقي.
