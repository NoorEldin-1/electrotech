# خطة: دورة اعتماد أمر التصنيع بالأدوار (مسودة → اعتماد مكتب المشروعات → … → انتهاء التصنيع)

> **المصدر:** `مكتب ادارة المشروعات.pptx` (11 سلايد) — جولة تعديلات على قسم التصنيع/مكتب ادارة المشروعات.
> **هذا الملف يغطّي جزءاً واحداً فقط بتفصيل التنفيذ الكامل:** **السلايدات 5 و10 — «سلسلة اعتماد أمر التصنيع بالأدوار»**:
> - سلايد 5: مراحل الأمر: **من المسودة → اعتمادها من مدير مكتب المشروعات → إلى ورقة الجودة → واعتمادها من مدير المصنع → إلى انتهاء التصنيع**. (أوامر التصنيع لا علاقة لها بالهالك/الفضلات — منتجات غير قياسية.)
> - سلايد 10: توزيع الأدوار: **مكتب المشروعات** يكتب أمر التصنيع، **المخازن** تسجّل أمر الصرف وتربطه بالأمر، **المصنع** يعمل ورقة الجودة وينهي التصنيع.
>
> باقي أجزاء الجولة (السلايدات 1، 2–4، 6، 7–9، 11) **مُنفَّذة بالفعل** (انظر `PMO_StandardBOM_Plan.md` و`QualitySheet_Modifications_Plan.md`) ما عدا سلايد 1 (إعادة التسمية) المذكور كخريطة طريق في النهاية.
> يتبع نفس عُرف الخطط ([[department-workflow]]): تحليل → قرارات تصميم → ملفات → RBAC → i18n → ثيم → اختبارات → كاش → معايير قبول.

---

## 1) نصّ المتطلب (السلايدات 5 و10)

**سلايد 5:**
> «أوامر التشغيل (أوامر التصنيع) ليس لها علاقة بالهالك أو الفضلات (منتجات غير قياسية). وتكون مراحلها كالتالى:
> **من المسودة إلى اعتمادها من مدير مكتب المشروعات، إلى ورقة الجودة واعتمادها من مدير المصنع، إلى انتهاء التصنيع.»**

**سلايد 10:**
> «دور مكتب ادارة المشروعات: أنه يكتب أمر التصنيع.
> دور المخازن: أنها تسجّل أمر الصرف وربطها بأمر التصنيع.
> دور المصنع: أنه يعمل ورقة جودة وينهي التصنيع لأمر التصنيع.»

**خلاصة المتطلب في ثلاث جُمَل:**
1. أمر التصنيع يبدأ **مسودة** يؤلّفها مكتب المشروعات، ولا ينزل للمصنع إلا بعد **اعتماد مدير مكتب المشروعات**.
2. بعد الاعتماد يمرّ بمراحل التصنيع الحالية حتى **ورقة الجودة واعتماد مدير المصنع** ثم **انتهاء التصنيع**.
3. الأدوار مفصولة: المكتب يكتب/يعتمد الأمر، المخازن تصرف وتربط، المصنع ينفّذ وينهي.

---

## 2) الوضع الحالي في الكود (ما الموجود فعلاً)

| العنصر | الحالة | المرجع |
|---|---|---|
| دورة حياة الأمر | ✅ `Pending → InProgress → QaReview → Completed (+ Cancelled)` | `App\Enums\WorkOrderStatus` |
| خدمة دورة الحياة | ✅ `start / submitForQa / approveQa / complete / finishManufacturing` | `App\Services\WorkOrderService` |
| بوابة QA (بوابة مدير المصنع الأولى) | ✅ موجودة | `approveQa` + `work_orders.approve_qa` (دور `Factory_Manager`) |
| اعتماد ورقة الجودة (**اعتماد مدير المصنع** — سلايد 5) | ✅ موجود | `QualitySheetService::approve` → `factory_approved_by/at` + `quality_sheets.approve` (دور `Factory_Manager`) |
| انتهاء التصنيع | ✅ موجود | `finishManufacturing` + `work_orders.finish_manufacturing` |
| ربط أمر الصرف بأمر التصنيع (دور المخازن — سلايد 10) | ✅ موجود | `IssueVoucher.work_order_id` + `issueMaterials` + دور `Warehouse_Manager` (`issue_vouchers.post`) |
| مكتب المشروعات يكتب الأمر (سلايد 10) | ✅ موجود جزئياً | دور `Technical_Office` يملك `work_orders.create` (= مكتب المشروعات بعد إعادة التسمية سلايد 1) |
| **حالة «مسودة» (Draft)** | ❌ **غير موجود** | الأمر يُنشأ مباشرةً `Pending` — لا مرحلة مسودة قبل الاعتماد |
| **بوابة «اعتماد مدير مكتب المشروعات»** | ❌ **غير موجود** | لا صلاحية ولا أكشن ولا حقل اعتماد للمكتب؛ أي أمر يمكن بدؤه فوراً |
| **منع بدء التصنيع قبل اعتماد المكتب** | ❌ غير موجود | `start()` يقبل أي أمر `Pending` بلا اعتماد مسبق |

**الفجوة الأساسية:** المطلوب في سلايد 5 يضيف **مرحلتين في مقدّمة الدورة**: «مسودة» ثم «اعتماد مدير مكتب المشروعات». الطرف الآخر من السلسلة (ورقة الجودة + اعتماد مدير المصنع + انتهاء التصنيع) **مُنفَّذ بالكامل** — فيتحوّل هذا الجزء عملياً إلى: **إضافة حالة Draft + بوابة اعتماد للمكتب أمام دورة الحياة القائمة**، مع توثيق تطابق باقي المراحل بالأدوار (سلايد 10) دون كتابة جديدة لها.

---

## 3) قرارات التصميم

> حسب عُرف الخطط: نختار الأقرب للمتطلب بدون سؤال، ونوثّق البديل المرفوض.

### قرار 1 — «المسودة» = حالة أولى جديدة `Draft` في مقدّمة الدورة

- **✅ المُعتمد:** إضافة `WorkOrderStatus::Draft` كـ**الحالة الابتدائية** عند إنشاء الأمر. الدورة تصبح:
  `Draft → Pending → InProgress → QaReview → Completed` (+ `Cancelled` جانبياً).
  المكتب يؤلّف الأمر وهو `Draft`؛ لا يظهر للمصنع كأمر قابل للبدء حتى يُعتمَد.
  **لماذا:** يطابق حرفياً «من المسودة → اعتمادها». تمثيلها كحالة (لا كعلَم boolean) يجعلها تظهر في نفس شارة الحالة والفلاتر والجدول، ويستفيد من آلة الحالة القائمة (تقدّم أمامي أحادي الخطوة).

- **🔁 البديل المرفوض — علَم `is_approved_by_pmo` boolean دون حالة:** أقل هجرة، لكن «المسودة» في السلايد **مرحلة** لها عرض/فلترة/لون مستقل، والعلَم يخفيها داخل حالة `Pending` ويشوّش دلالة الحالة. مرفوض.

### قرار 2 — بوابة اعتماد المكتب = صلاحية + أكشن `approve_order` ينقل `Draft → Pending`

- **✅ المُعتمد:** صلاحية جديدة `work_orders.approve_order` + دالة خدمة `approveOrder()` تتحقق أن الأمر `Draft` ثم تنقله `Pending` وتسجّل `order_approved_by/at`. أكشن Filament `approve_order` مرئي فقط في `Draft` ولمن يملك الصلاحية. `start()` يبقى كما هو (يشترط `Pending`) فيصير التصنيع **ممتنعاً** قبل اعتماد المكتب تلقائياً.
  **لماذا:** يعيد استخدام نمط البوابات القائم بالحرف (نفس شكل `approve_qa`: صلاحية + أكشن + تسجيل مُعتمِد/وقت + idempotency)، ويحقّق «اعتمادها من مدير مكتب المشروعات» بأقل سطح تغيير. تسجيل المُعتمِد يوفّر أثراً تدقيقياً مطابقاً لبوابة QA.

- **🔁 البديل المرفوض — إعادة استخدام `work_orders.start` كأنه الاعتماد:** يدمج فِعلين مختلفين (اعتماد المكتب ≠ بدء المصنع) ويخلط الأدوار (المكتب يعتمد، المصنع يبدأ — سلايد 10). مرفوض.

### قرار 3 — «اعتماد مدير المصنع» = ربط بالموجود لا بناء جديد

- **✅ المُعتمد:** «اعتمادها من مدير المصنع» (سلايد 5) = **اعتماد ورقة الجودة القائم** (`QualitySheetService::approve` → `factory_approved_by`) وبوابة `approve_qa`، وكلاهما مملوك لدور `Factory_Manager`. **لا كود جديد**، فقط توثيق التطابق + التأكد أن التسلسل يعمل بعد إدراج حالة `Draft`.
  **لماذا:** المتطلب مُنفَّذ فعلاً؛ الجزء الجديد الوحيد أمامَ الدورة (المكتب) لا خلفها (المصنع). تجنّب إعادة اختراع بوابة موجودة.

### قرار 4 — الأدوار (سلايد 10): إسناد الصلاحية الجديدة لمكتب المشروعات فقط

- **✅ المُعتمد:** `work_orders.approve_order` تُمنَح افتراضياً لدور `Technical_Office` (= مكتب ادارة المشروعات بعد إعادة تسمية سلايد 1) + `Admin`. **لا تُمنَح** لـ`Factory_Manager` (المصنع ينفّذ ولا يعتمد الأمر — فصل الأدوار). المخازن (`Warehouse_Manager`) و«ربط الصرف بالأمر» يبقيان كما هما (مُنفَّذان).
  **لماذا:** يطابق سلايد 10 حرفياً (المكتب يكتب/يعتمد، المصنع ينفّذ). ملاحظة تشغيلية مهمّة: `RoleAndPermissionSeeder` **لا يلمس أدواراً قائمة** (يطبّق الافتراضات مرة واحدة عند الإنشاء فقط) → على التنصيبات القائمة يجب منح الصلاحية يدوياً من واجهة الأدوار (نوثّقها في معايير القبول).

---

## 4) التغييرات بالملفات

### (أ) Enum — `app/Enums/WorkOrderStatus.php`

- إضافة `case Draft = 'draft';` كأول حالة.
- `getLabel()`: يضيف مفتاح `draft` (موجود أصلاً عبر `__('resources.enums.work_order_status.draft')`).
- `getColor()`: `Draft => 'gray'` (وتحويل `Pending` إلى `'info'`/الإبقاء — نُبقي `Pending => 'gray'` كما هو أو نميّزه؛ الأبسط: `Draft => 'gray'`, `Pending => 'warning'` لتمييز «معتمد بانتظار البدء»). *(قرار عرضي بسيط — نوثّقه في i18n/الثيم.)*
- `getIcon()`: `Draft => 'heroicon-o-pencil-square'`.

### (ب) قاعدة البيانات — هجرة واحدة `..._add_order_approval_to_work_orders.php`

- إضافة أعمدة nullable إلى `work_orders`:
  - `order_approved_by` → `foreignId nullable` (`users`, `nullOnDelete`).
  - `order_approved_at` → `timestamp nullable`.
- (لا تغيير على قيمة `status` الافتراضية في DB — الافتراض يُضبط من الموديل/الفورم.)

### (ج) Model — `app/Models/WorkOrder.php`

- `fillable` += `order_approved_by`, `order_approved_at`.
- `casts` += `order_approved_at => 'datetime'`.
- `syncWritableFields()` += `order_approved_by`, `order_approved_at` (حتى يُسمح بها من مسار الـ sync عند الاعتماد الميداني — اختياري لكنه متسق مع `qa_approved_by`).
- علاقة `orderApprovedBy(): BelongsTo` (User, `order_approved_by`).
- helper `isOrderApproved(): bool` = `order_approved_by !== null` (بنمط `isQaApproved`).
- `getActivitylogOptions`: إضافة `order_approved_by` لقائمة `logOnly` (أثر تدقيقي).

### (د) Service — `app/Services/WorkOrderService.php`

- دالة جديدة `approveOrder(WorkOrder $workOrder): void`:
  - idempotent: لو `status !== Draft` وكان `isOrderApproved()` → نجاح صامت (بنمط `finishManufacturing`/`approveQa`).
  - تتحقق `status === Draft` وإلا ترمي `errors.work_order.cannot_approve_order`.
  - `update(['status' => Pending, 'order_approved_by' => Auth::id(), 'order_approved_at' => now()])`.
- `start()`: لا تغيير منطقي (يشترط `Pending`) — لكن رسالة `cannot_start` تبقى تعبّر ضمناً أن Draft لا يبدأ. (اختياري: رسالة أوضح «اعتمد الأمر أولاً».)

### (هـ) Filament — `app/Filament/Resources/WorkOrderResource.php`

- **الفورم:** `Select::make('status')` → `->default(WorkOrderStatus::Draft)` بدل `Pending`. (يبقى قابلاً للاختيار للأدمن؛ لغير الأدمن العملية عبر الأكشن.)
  - إضافة `Placeholder` لحالة اعتماد المكتب في قسم يشبه `qa_gate` (اسم المُعتمِد + التاريخ أو «بانتظار اعتماد المكتب»).
- **أكشن جدول جديد `approve_order`** (يوضَع قبل `start`):
  - `->label(__('resources.work_orders.actions.approve_order'))`, `->icon('heroicon-o-check-badge')`, `->color('success')`, `->requiresConfirmation()`.
  - `->visible(fn ($record) => $record->status === WorkOrderStatus::Draft && auth()->user()?->can('work_orders.approve_order'))`.
  - `->action(...)` بنمط idempotent القائم (re-read fresh؛ لو تجاوز Draft → نجاح صامت؛ وإلا `app(WorkOrderService::class)->approveOrder($record)`).
- **أكشن `start`:** لا تغيير (يبقى مرئياً في `Pending`) — لكنه الآن غير متاح على `Draft` تلقائياً.
- **الجدول/الفلاتر:** `SelectFilter::make('status')` يلتقط `Draft` تلقائياً من `WorkOrderStatus::class` — لا تغيير.

### (و) آلة الحالة للـ Sync — `app/Sync/Resolvers/WorkOrderStateMachineResolver.php`

- `rankOf()`: إدراج `Draft => 0` وإعادة ترقيم: `Pending => 1`, `InProgress => 2`, `QaReview => 3`, `Completed => 4`, `Cancelled => 99`.
- `match ($target)`: إضافة فرع `WorkOrderStatus::Pending => $service->approveOrder($wo)` (التقدّم `Draft(0) → Pending(1)` خطوة واحدة أمامية = اعتماد المكتب).
- تحديث تعليق «Forward-only transitions» في رأس الملف ليشمل `Draft → Pending (approve_order)`.
- منطق «single-step forward / already-advanced noop» يبقى كما هو (يعمل تلقائياً بعد إعادة الترقيم).

### (ز) Policy / RBAC — `RoleAndPermissionSeeder`

- إضافة `'work_orders.approve_order'` إلى مصفوفة `getPermissions()` (بعد `work_orders.create`).
- إضافتها إلى افتراضات دور `Technical_Office` (مكتب المشروعات). `Admin` يأخذها تلقائياً.
- **لا** تُضاف إلى `Factory_Manager` (فصل الأدوار — سلايد 10).
- (لا `WorkOrderPolicy` جديدة؛ الأكشن محكوم بالصلاحية مباشرةً كنمط باقي أكشنات الأمر.)

---

## 5) RBAC (الخلاصة)

- **صلاحية جديدة واحدة:** `work_orders.approve_order` → مكتب المشروعات (`Technical_Office`) + `Admin`.
- باقي الأدوار دون تغيير: المصنع (`Factory_Manager`) ينفّذ/يعتمد الجودة/ينهي؛ المخازن (`Warehouse_Manager`) تصرف وتربط.
- **تنبيه [[department-workflow]]:** التنصيبات القائمة لن تلتقط الصلاحية تلقائياً (الـseeder لا يلمس أدواراً موجودة) → منح يدوي من واجهة «الأدوار» لدور مكتب المشروعات. يُذكر في معايير القبول.

## 6) i18n (EN/AR بتطابق تام)

- `lang/{en,ar}/resources.php`:
  - `enums.work_order_status.draft` (EN: "Draft" / AR: "مسودة").
  - `work_orders.actions.approve_order` (EN: "Approve Order" / AR: "اعتماد الأمر").
  - `work_orders.notifications.order_approved`.
  - `work_orders.fields.order_approved_at` + `work_orders.qa.order_approved_by` / `order_pending` (نصوص الـ Placeholder، بنمط `qa.approved_by`/`qa.pending`).
- `lang/{en,ar}/errors.php`: `work_order.cannot_approve_order` (EN/AR).
- تأكيد تكافؤ عدد المفاتيح EN == AR بعد الإضافة.

## 7) الثيم / RTL

- لا ألوان hex؛ ألوان Filament الدلالية فقط (`gray`/`warning`/`success`) و`__()` في كل النصوص (RTL + light/dark تلقائي) — مطابق لباقي الأكشنات.

---

## 8) الاختبارات — `tests/Feature/Manufacturing/WorkOrderApprovalTest.php` (جديد) + توسيع القائم

- أمر جديد يُنشأ بحالة **`Draft`** افتراضياً.
- `approveOrder` ينقل `Draft → Pending` ويسجّل `order_approved_by/at`؛ ويرمي خطأ لو الحالة ليست `Draft`؛ و**idempotent** على إعادة النداء.
- `start()` **يرفض** أمراً `Draft` (لا يبدأ التصنيع قبل اعتماد المكتب) وينجح على `Pending`.
- **RBAC:** مستخدم بلا `work_orders.approve_order` لا يرى/لا ينفّذ الأكشن؛ ومكتب المشروعات يستطيع.
- **السلسلة الكاملة (سلايد 5):** `Draft →(approve_order)→ Pending →(start)→ InProgress →(submit_qa)→ QaReview →(quality sheet fill + factory approve + approve_qa)→ complete → Completed`.
- **Sync:** `resolveTransition` ينفّذ `Draft → Pending` عبر `approveOrder`، ويرفض القفزات متعددة الخطوات، ويعامل «تجاوز» الهدف كـnoop.
- تحديث `WorkOrderFactory` (وأي state) ليعكس الافتراضي `Draft`، وضبط الاختبارات القائمة التي تفترض بدء `Pending` مباشرةً (تمرّ عبر `approveOrder` أولاً أو تُنشئ بحالة `Pending` صراحةً).
- تشغيل مجموعة Manufacturing كاملة لتأكيد عدم الكسر.

## 9) الكاش (بعد التنفيذ)

`php artisan optimize:clear; filament:clear-cached-components; icons:clear; migrate; db:seed --class=RoleAndPermissionSeeder; permission:cache-reset; npm run build; queue:restart`
(`migrate` للهجرة، `db:seed` لتسجيل الصلاحية الجديدة في الكتالوج، `permission:cache-reset` لإعادة بناء كاش الصلاحيات.)

---

## 10) معايير القبول (Definition of Done)

- [ ] أمر التصنيع الجديد يبدأ **مسودة** (`Draft`) ولا يظهر للمصنع كأمر قابل للبدء.
- [ ] **مدير مكتب المشروعات** (ومن يملك `work_orders.approve_order`) يرى أكشن «اعتماد الأمر» على المسودة، وينقلها `Pending` مع تسجيل المُعتمِد/الوقت.
- [ ] المصنع **لا يستطيع** بدء التصنيع (`start`) قبل اعتماد المكتب.
- [ ] بعد الاعتماد تعمل بقية السلسلة كما هى: بدء → QA → **ورقة الجودة واعتماد مدير المصنع** → انتهاء التصنيع.
- [ ] الأدوار مطابقة لسلايد 10 (المكتب يعتمد، المصنع لا يعتمد الأمر، المخازن تصرف/تربط) — والصلاحية مُسنَدة يدوياً على التنصيبات القائمة.
- [ ] مسار الـ Sync يعبر `Draft → Pending`؛ i18n (EN==AR) + light/dark/RTL + الاختبارات خضراء + وصفة الكاش اتنفّذت.

---

## ملحق — خريطة باقي أجزاء الجولة (خارج نطاق هذا الملف)

| الجزء | السلايد | الحالة |
|---|---|---|
| إعادة التسمية | 1 | «أوامر التشغيل»→«أوامر التصنيع»، «المكتب الفنى»→«مكتب ادارة المشروعات» — i18n/navigation + تسمية الدور عرضياً. **لم يُنفَّذ.** |
| ورقة الجودة (مواصفات/checkboxes/قراءتان) | 2–4 | **مُنفَّذ** — `QualitySheet_Modifications_Plan.md`. |
| Standard BOM + المرونة اليدوية | 6 | **مُنفَّذ** — `PMO_StandardBOM_Plan.md`. |
| صرف من أمر التصنيع + تكلفة تلقائية | 7–8 | **مُنفَّذ** ضمن تغيير BOM. |
| تقرير الإنتاج والفاقد | 9 | **مُنفَّذ** — `ProductionEntry` + `ProductionEntryResource`. |
| إضافة التام للمخزون عند الانتهاء | 11 | **مُنفَّذ** — `WorkOrderService::complete()` (خطوة `addStock` لمخزن التام). |

> **ملاحظة تبعية:** هذا الجزء (بوابة اعتماد المكتب + حالة Draft) **مستقلّ ويقف أمام** الدورة القائمة — لا يكسر الأجزاء المُنفَّذة (BOM/الصرف/الجودة/الإنتاج)، فقط يُدرِج مرحلتين في مقدّمتها. سلايد 1 (إعادة التسمية) مكمّل طبيعي له لأنه يسمّي «مكتب المشروعات» صاحب البوابة الجديدة.
