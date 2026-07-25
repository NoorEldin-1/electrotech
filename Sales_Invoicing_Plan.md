# خطة تنفيذ: فوترة أذون التسليم (Financial Department — سلايد 10)

> جولة `Financial Department.pptx`. **الجزء المختار من التعديلات: سلايد 10 فقط** (المبيعات — إذن التسليم مقابل الفاتورة).
> باقي السلايدات موثّقة في القسم 1 مع سبب استثنائها من هذه الجولة.

---

## 1. تحليل الملف بالكامل والربط بالنظام الحالي

| السلايد | المطلوب | الحالة في النظام | القرار |
|---|---|---|---|
| 2 | قيود اليومية المزدوجة + 3 أنواع مستند ملوّنة (أمر صرف/إيصال توريد/قيد تسوية) + رقم تسلسلي لكل نوع | ✅ منفّذ — `JournalEntry` + `JournalEntryLine` + `DocumentType` + `JournalEntryService::generateEntryNumber` + `entry_serial` (commit `4e47daf`) | لا شيء |
| 3 | الترحيل التلقائي لدفتر أستاذ كل حساب + رصيد متحرك | ✅ منفّذ — `GeneralLedgerService` + `LedgerEntriesRelationManager` + `GeneralLedgerReport` | لا شيء |
| 4 | ميزان مراجعة بالإجماليات فقط | ✅ منفّذ — `TrialBalanceService` + `app/Filament/Pages/TrialBalance.php` | لا شيء |
| 5–9 | شجرة الحسابات بتفريعاتها (أصول ثابتة/متداولة، خصوم، مصروفات، إيرادات، حقوق ملكية) | ✅ البنية منفّذة — `Account` (code/name/type/nature/currency/parent_id) + `AccountType` + `ChartOfAccountsSeeder` | **بيانات لا كود** — أي حساب ناقص يُضاف من شاشة شجرة الحسابات أو بسطر في الـ seeder. لا يحتاج تطويراً |
| 10 | **المبيعات**: إذن تسليم ≠ فاتورة. إجمالي فواتير المبيعات يجب أن يساوي إجمالي أذون التسليم، وكل إذن له حالة: **مفوتر بالكامل / مفوتر جزئياً / غير مفوتر** (عينات أو سحب شخصي) | ❌ **غير موجود إطلاقاً** — `DeliveryVoucher` ليس فيه أي حقل فاتورة | ✅ **هذه الجولة** |
| 11 | **المشتريات**: نفس الفكرة لأذون الإضافة (مفوتر برقم فاتورة / غير مفوتر ويُقفل بسبب) | 🟡 نصف موجود — `AdditionVoucher.invoice_number` + `invoice_value` موجودان، الناقص فقط حالة الفوترة وسبب الإقفال | مؤجّل — نفس النمط بعد اعتماد سلايد 10 |
| 12 | مراكز التكلفة للعملية + تحميلها بأذون الصرف + فرق أمر التصنيع (الهالك) + إقفالها في تكلفة البضاعة المباعة عند التسليم | 🟡 موجود بمعظمه — `OperationCostService` + `OperationCostFile` + `WorkOrderMaterialVarianceService` + `OperationLifecycleService`. الناقص فقط الإقفال المحاسبي التلقائي في ح/تكلفة البضاعة المباعة | مؤجّل |

**سبب اختيار سلايد 10**: الفجوة الوحيدة الصفرية (لا يوجد أي كود لها)، مستقلة تماماً (لا تلمس المحاسبة المزدوجة ولا المخزون)، وقيمتها عالية: بدونها لا توجد أي طريقة لمعرفة أي بضاعة خرجت بدون فاتورة ضريبية.

---

## 2. الوضع الحالي لإذن التسليم (حقائق من الكود)

- `app/Models/DeliveryVoucher.php` — `voucher_number` (DV-YYYYMM-####)، `customer_id`، `project_id`، `total_value`، اعتماد مزدوج (فني + مالي)، `status: DeliveryVoucherStatus`.
- `app/Services/DeliveryVoucherService.php::activateIfFullyApproved` — عند اكتمال التوقيعين: خصم المخزون التام + **قيد مدين للعميل بقيمة التسليم** في `AccountEntry` + تثبيت `total_value` + `status=Active`.
- `app/Filament/Resources/DeliveryVoucherResource.php` — مجموعة `warehouse`، `navigationSort=43`، أعمدة: الرقم/العميل/التاريخ/فني/مالي/القيمة/الحالة، أكشنز داخل `ActionGroup` (اتفاقية `row-actions-dropdown-convention`).
- **لا يوجد** أي مفهوم فاتورة على الإذن. `FinancialClaim` هي مطالبة مالية على مستوى المشروع لا فاتورة ضريبية لكل إذن ⇒ لا تعارض.

---

## 3. القرارات التصميمية (اتخذتها بنفسي مع التبرير)

1. **موديل مستقل `SalesInvoice`** بعلاقة `hasMany` من إذن التسليم — **وليس** حقلين على الإذن. السبب: السلايد ينص على «مفوتر جزئياً مثل مبيعات تجزئة»، أي إذن واحد قد تُصدر له أكثر من فاتورة بأرقام مختلفة. حقل واحد لا يستوعب ذلك.
2. **رقم الفاتورة يدوي وفريد** (لا توليد تلقائي بنمط `DV-`): الفاتورة الضريبية تُصدر من منظومة الفاتورة الإلكترونية/دفتر الفواتير خارج النظام، والنظام يسجّل رقمها للمطابقة.
3. **الفاتورة لا تنتج أي قيد محاسبي.** السبب الحاسم: تفعيل إذن التسليم **بالفعل** يقيد مديناً على العميل بقيمة التسليم؛ أي قيد إضافي من الفاتورة = ازدواج في رصيد العميل. الفاتورة هنا **مستند ضريبي/رقابي** فقط. (موثّق في تعليق الموديل حتى لا يضيفها أحد لاحقاً.)
4. **الفوترة مسموحة فقط لإذن `Active`**: قبل الاعتماد المزدوج لا تكون البضاعة خرجت فعلاً ولا `total_value` مثبتة، فلا معنى لفاتورة.
5. **منع الفوترة الزائدة**: مجموع الفواتير ≤ قيمة الإذن (بسماحية قرش واحد للتقريب). هذا هو **الفرض العملي** لقاعدة السلايد «قيمة فواتير المبيعات = قيمة أذون التسليم».
6. **تخزين الحالة (denormalized) لا حسابها لحظياً**: عمودان `invoiced_value` + `invoicing_status` على `delivery_vouchers` تُحدّثهما الخدمة عند أي تغيير في الفواتير. السبب: الفرز والفلترة والإجماليات في جدول Filament بدون subqueries ثقيلة — والسلايد يطلب صراحة «في قائمة أذون التسليم يجب معرفة حالة الإذن».
7. **سبب عدم الفوترة `non_invoice_reason`** حقل نصي على الإذن (عينات/سحب شخصي/…): السلايد يذكر هذين المثالين صراحةً كحالة «غير مفوتر» مشروعة، فلا بد من توثيق السبب.
8. **مكان الشاشة**: مجموعة `finance` (`navigationSort=56`، قبل شجرة الحسابات 57) — المتطلب جاء من الإدارة المالية والفاتورة مستند ضريبي، مع إبقاء أذون التسليم في `warehouse` كما هي.
9. **المطابقة (إجمالي الفواتير = إجمالي الأذون)** تُعرض عبر `Summarizer` أسفل جدول أذون التسليم (إجمالي القيمة + إجمالي المفوتر) مع فلتر حالة الفوترة — أوفر وأدق من صفحة تقرير جديدة، ويعمل مع أي فلتر يطبّقه المستخدم.

---

## 4. التنفيذ التفصيلي

### 4.1 قاعدة البيانات
`database/migrations/2026_07_26_000001_create_sales_invoices_table.php`
- جدول `sales_invoices`: `id`, `invoice_number` (unique), `delivery_voucher_id` (FK cascade), `customer_id` (FK nullOnDelete — لقطة للفلترة), `invoice_date`, `amount` decimal(14,2), `notes`, `created_by` (FK nullOnDelete), `timestamps`, `softDeletes`. فهارس: `index(delivery_voucher_id)`, `index(invoice_date)`.
- إضافة على `delivery_vouchers`: `invoiced_value` decimal(14,2) default 0، `invoicing_status` string default `not_invoiced` + index، `non_invoice_reason` string nullable.

### 4.2 Enum
`app/Enums/InvoicingStatus.php` — `NotInvoiced` (danger) / `PartiallyInvoiced` (warning) / `FullyInvoiced` (success)، `implements HasLabel, HasColor` بنمط `LossType` (ألوان سيمانتية تعمل في الوضعين الفاتح والداكن).

### 4.3 الموديلات
- `app/Models/SalesInvoice.php` — `deliveryVoucher()`, `customer()`, `createdBy()`, `LogsActivity`، تعبئة `customer_id` تلقائياً من الإذن في `creating`.
- تعديل `DeliveryVoucher` — إضافة الحقول الثلاثة للـ `fillable`/`casts` + `invoices(): HasMany` + `isFullyInvoiced()`.

### 4.4 الخدمة
`app/Services/SalesInvoicingService.php`
- `record(DeliveryVoucher $v, array $data, ?User $u): SalesInvoice` — يتحقق: الإذن `Active` (`errors.sales_invoice.voucher_not_active`)، والمبلغ لا يجاوز المتبقي (`errors.sales_invoice.exceeds_voucher_value`)، ثم ينشئ الفاتورة ويعيد الحساب داخل `DB::transaction`.
- `recalculate(DeliveryVoucher $v): void` — `invoiced_value = sum(invoices.amount)` ثم تصنيف الحالة: صفر → `not_invoiced`؛ ≥ القيمة (سماحية 0.01) → `fully_invoiced`؛ غير ذلك → `partially_invoiced` (وإذا `total_value = 0` → `not_invoiced`).
- `remainingFor(DeliveryVoucher $v): float`.

### 4.5 Filament
- `SalesInvoiceResource` (مجموعة `finance`, sort 56): نموذج (اختيار إذن تسليم `Active` يعرض الرقم + العميل + المتبقي، رقم الفاتورة، التاريخ، المبلغ، ملاحظات) + جدول (رقم الفاتورة/التاريخ/الإذن/العميل/المبلغ) + فلاتر (العميل، نطاق تاريخ) + `ActionGroup` بالاتفاقية + إجمالي `Sum` على المبلغ. الحفظ/التعديل/الحذف يمرّ بالخدمة لإعادة الحساب.
- تعديل `DeliveryVoucherResource`: عمود `invoicing_status` (badge) + عمود `invoiced_value` (محجوب عن غير مخوّل الأسعار) + `SelectFilter` على حالة الفوترة + `Summarizer::Sum` على `total_value` و`invoiced_value` + أكشن **«تسجيل فاتورة»** (Modal داخل الـ ActionGroup، للأذون `Active` غير المفوترة بالكامل) + أكشن **«سبب عدم الفوترة»**.

### 4.6 RBAC
صلاحيات جديدة في `RoleAndPermissionSeeder::getPermissions()`: `sales_invoices.view/create/edit/delete`.
التوزيع: **Finance** (view/create/edit) — المالك؛ **Sales_Manager** (view/create/edit)؛ **Sales** (view)؛ **General_Manager** (view)؛ Admin تلقائياً.
`SalesInvoicePolicy` بنمط `DeliveryVoucherPolicy`. أكشنات إذن التسليم محمية بـ `sales_invoices.create`.

### 4.7 i18n / Theme / RTL
- `lang/{en,ar}/resources.php`: كتلة `sales_invoices` كاملة + إضافات على `delivery_vouchers.columns/actions/fields` + `enums.invoicing_status` + مفتاح في قائمة `resources` بالتنقل.
- `lang/{en,ar}/errors.php`: `sales_invoice.voucher_not_active`, `sales_invoice.exceeds_voucher_value`.
- كل النصوص عبر `__()`؛ ألوان badge سيمانتية فقط ⇒ يعمل تلقائياً في الوضع الداكن/الفاتح و RTL.

### 4.8 الاختبارات
`database/factories/SalesInvoiceFactory.php` + `tests/Feature/Sales/SalesInvoicingTest.php`:
حالة «غير مفوتر» افتراضياً؛ فاتورة جزئية → `partially_invoiced`؛ استكمال القيمة → `fully_invoiced`؛ رفض الفوترة الزائدة؛ رفض الفوترة لإذن غير مفعّل؛ حذف الفاتورة يعيد الحالة؛ الفاتورة **لا** تنشئ أي `AccountEntry` إضافي (منع الازدواج)؛ RBAC؛ وجود مفاتيح الترجمة en/ar.

### 4.9 المخاطر
1. ازدواج قيد العميل — مُعالج بالقرار 3 + اختبار صريح.
2. تعديل قيمة إذن مفعّل — غير ممكن (`total_value` تُثبّت عند التفعيل والتحرير محجوب عن `Active`).
3. الأذون القديمة — الافتراضي `not_invoiced` + `invoiced_value=0` صحيح بأثر رجعي.

### 4.10 مسح الكاش محلياً
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build
```
> دور Finance/Sales موجودان مسبقاً و`ensureInitialRolesExist` لا تحدّث الأدوار القائمة ⇒ تُمنح صلاحيات `sales_invoices.*` من شاشة الأدوار في البيئات القائمة (Admin يأخذها تلقائياً).
