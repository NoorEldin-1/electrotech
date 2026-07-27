# خطة تنفيذ: فوترة أذون الإضافة (Financial Department — سلايد 11)

> جولة `Financial Department.pptx` + `قائمة المواد.pptx`. **الجزء المختار: سلايد 11 فقط** (المشتريات — إذن الإضافة مقابل فاتورة المورّد).
> باقي السلايدات موثّقة في القسم 1 مع سبب استثنائها.

---

## 1. تحليل الصور بالكامل والربط بالنظام الحالي

### أ) صور `قائمة المواد.pptx` (3 سلايدات)

| السلايد | المطلوب | الحالة في النظام | القرار |
|---|---|---|---|
| 1 | شجرة المنتج (BOM) + تفجير الخامات لأمر التشغيل + قياس الانحراف (الهالك) بين أمر التشغيل وأذون الصرف | ✅ منفّذ — `Bom` + `BomLine` + `StandardBom` + `WorkOrderMaterialVarianceService` (commit `4e47daf` و`f9fd9ed`) | لا شيء |
| 2 | قيود اليومية بجانب مدين ودائن + رقم قيد + رقم مستند + 3 أنواع مستند ملوّنة | ✅ منفّذ — `JournalEntry` + `DocumentType` + `entry_serial` + دفتر اليومية التحليلي (commit `4e47daf`) | لا شيء |
| 3 | دفتر أستاذ الخزينة برصيد متحرك (مدين + دائن → الرصيد الجديد) | ✅ منفّذ — `GeneralLedgerService` + كشف الحساب المطبوع | لا شيء |

**⇒ ملف `قائمة المواد` كله منفّذ بالفعل في الجولات السابقة.** لا فجوة فيه.

### ب) صور `Financial Department.pptx` (12 سلايد)

| السلايد | المطلوب | الحالة في النظام | القرار |
|---|---|---|---|
| 2–4 | قيود اليومية + الترحيل التلقائي لدفتر الأستاذ + ميزان المراجعة بالإجماليات | ✅ منفّذ | لا شيء |
| 5–9 | شجرة الحسابات (أصول ثابتة/متداولة، خصوم، مصروفات، إيرادات، حقوق ملكية) | ✅ البنية منفّذة (`Account` + `ChartOfAccountsSeeder`) | **بيانات لا كود** |
| 10 | المبيعات: إذن تسليم ≠ فاتورة + حالة فوترة لكل إذن | ✅ **منفّذ في الجولة السابقة** (commit `4b49730`) | لا شيء |
| 11 | **المشتريات**: إذن الإضافة ≠ الفاتورة. إجمالي فواتير المشتريات = إجمالي أذون الإضافة، وفي قائمة أذون الإضافة يجب معرفة حالة الإذن: **مفوتر** (بمعرفة رقم الفاتورة) / **غير مفوتر** (ويتم إقفاله مع وجود سبب الإقفال) | 🟡 نصف موجود — `invoice_number` + `invoice_value` موجودان على `AdditionVoucher`، لكن **لا حالة فوترة ولا سبب إقفال ولا مطابقة قيم ولا فلترة** | ✅ **هذه الجولة** |
| 12 | مراكز التكلفة: فتح مركز باسم العملية + تحميله بأذون الصرف + فرق الهالك + إقفاله في ح/تكلفة البضاعة المباعة عند التسليم | 🟡 موجود بمعظمه — `OperationCostService` + `OperationCostFile` + `WorkOrderMaterialVarianceService`. الناقص فقط **الإقفال المحاسبي التلقائي** | مؤجّل |

**سبب اختيار سلايد 11:**
1. الفجوة الوحيدة الباقية التي طلبها العميل **حرفياً على شاشة موجودة** («في قائمة أذون الإضافة يجب معرفة حالة الإذن») — أي قيمة فورية ومرئية.
2. متماثل تماماً مع سلايد 10 المعتمَد بالفعل ⇒ نفس النمط، نفس المصطلحات، منحنى تعلّم صفر للمستخدم.
3. مستقل: لا يلمس القيد المزدوج ولا المخزون (بخلاف سلايد 12 الذي يمسّ الإقفال المحاسبي ويستحق جولة كاملة بمفرده).
4. قيمته الرقابية عالية: بدونه لا توجد طريقة لمعرفة أي بضاعة **دخلت المخزن ولم تصل فاتورتها** — وهي التزام غير مسجَّل على الشركة.

---

## 2. الوضع الحالي لإذن الإضافة (حقائق من الكود)

- `app/Models/AdditionVoucher.php` — `voucher_number` (AV-YYYYMM-####)، `supplier_id` (اختياري) + `supplier_name`، `purchase_order_id`، **`invoice_number`** + **`invoice_value`**، `status: VoucherStatus` (Draft/Posted)، `lines_value` accessor (Σ كمية × تكلفة).
- `app/Services/AdditionVoucherService.php::post` — يضيف المخزون، ثم **يقيد دائناً للمورّد بقيمة `invoice_value`** (أو `lines_value` إن كانت صفراً) في `AccountEntry`، ثم يقفل أمر الشراء ويحوّل الحالة إلى `Posted`.
- `AdditionVoucherResource` — مجموعة `warehouse`، `navigationSort=41`، أعمدة: الرقم/المورّد/التاريخ/رقم الفاتورة/قيمة الفاتورة/الحالة. أكشن واحد فقط: «ترحيل».
- **الناقص**: لا حالة فوترة، لا تاريخ فاتورة، لا إقفال بسبب، لا مطابقة بين قيمة الفاتورة وقيمة البضاعة المستلمة، لا إجماليات أسفل الجدول.

---

## 3. القرارات التصميمية (مع التبرير)

1. **لا جدول فواتير مشتريات مستقل** — بخلاف المبيعات. السببان:
   - السلايد يطلب حالتين فقط (مفوتر / غير مفوتر) ولا يذكر الفوترة الجزئية إطلاقاً — بينما سلايد المبيعات ذكرها صراحةً («مثل مبيعات تجزئة»).
   - `invoice_value` هي **مصدر قيد المورّد** عند الترحيل. جدول أبناء موازٍ يخلق مصدرَي حقيقة للقيمة نفسها ⇒ خطر ازدواج/تعارض في دفتر المورّد. إعادة استخدام الحقلين الموجودين أأمن وأصدق.
2. **ثلاث حالات فعلية** في `PurchaseInvoicingStatus`: `not_invoiced` (منتظر الفاتورة — خطر مالي) / `invoiced` (وصلت الفاتورة برقمها) / `closed_uninvoiced` (أُقفل نهائياً بسبب مكتوب). السلايد يذكر حالتين لأن الإقفال عنده نتيجة الحالة الثانية؛ فصلهما ضروري وإلا اختلط «لم تصل بعد» بـ «لن تصل أبداً» — وهما نقيضان محاسبياً.
3. **الحالة تُشتق ولا تُدخَل يدوياً**: تُحسب في `saving` على الموديل من `invoice_number` و`closed_at` ⇒ استحالة أن تكذب الحالة مهما كان المسار (نموذج، خدمة، seeder، factory).
4. **الإقفال متاح للأذون المرحَّلة فقط**: الإذن المسودة قابل للتعديل والحذف، فلا معنى لإقفاله. الإقفال إقرار بأن بضاعة **دخلت المخزن فعلاً** ولن تُفوتَر.
5. **الفوترة متاحة قبل الترحيل وبعده**: الفاتورة قد تسبق دخول البضاعة أو تليها. النموذج الحالي يسمح بإدخال رقم الفاتورة عند الإنشاء، ولا يصح كسر ذلك.
6. **تعديل قيمة الفاتورة بعد الترحيل يُصحِّح قيد المورّد** (`AccountEntry` المرتبط بالإذن) داخل نفس المعاملة. السبب الحاسم: القيد رُحِّل بقيمة تقديرية (`lines_value`) لأن الفاتورة لم تكن قد وصلت؛ ترك القيد على القيمة القديمة يعني **رصيد مورّد خاطئ**. لا ننشئ قيداً ثانياً (ازدواج) بل نصحّح القائم، ويُسجَّل التغيير في سجل النشاط.
7. **`received_value` عمود مخزَّن** (Σ الأسطر) وليس accessor فقط: السلايد يطلب مطابقة إجمالي الفواتير بإجمالي أذون الإضافة، والإجماليات والفرز والفلترة في Filament تحتاج عموداً حقيقياً في SQL. يُحدَّث تلقائياً من hooks على `AdditionVoucherLine`.
8. **فلتر «فرق في القيمة»**: يعرض الأذون المفوترة التي تختلف فيها قيمة الفاتورة عن قيمة البضاعة المستلمة بأكثر من قرش — وهو **الفرض العملي** لقاعدة السلايد.
9. **صلاحية جديدة `addition_vouchers.invoice`** منفصلة عن `create`/`post`: تسجيل الفاتورة وإقفال الإذن قرار **مالي** لا مخزني؛ أمين المخزن يستلم البضاعة، والمالية تقرر حالة الفوترة والإقفال.
10. **الشاشة تبقى مكانها** (`warehouse`, sort 41) — السلايد ينص على «في قائمة أذون الإضافة»، فلا شاشة جديدة.

---

## 4. التنفيذ التفصيلي

### 4.1 قاعدة البيانات
`database/migrations/2026_07_27_000001_add_purchase_invoicing_to_addition_vouchers.php` — إضافات على `addition_vouchers`:
- `invoice_date` date nullable (بعد `invoice_number`) — الفترة الضريبية للفاتورة.
- `received_value` decimal(14,2) default 0 — Σ (كمية × تكلفة) للأسطر، مخزَّنة للإجماليات.
- `invoicing_status` string default `not_invoiced` + index.
- `closure_reason` string nullable + `closed_at` timestamp nullable + `closed_by` FK users nullOnDelete.
- تعبئة رجعية في `up()`: الأذون التي لها `invoice_number` تصبح `invoiced`، و`received_value` يُحسب من الأسطر.

### 4.2 Enum
`app/Enums/PurchaseInvoicingStatus.php` — `NotInvoiced` (danger) / `Invoiced` (success) / `ClosedUninvoiced` (gray)، `implements HasLabel, HasColor` بنمط `InvoicingStatus`.

### 4.3 الموديلات
- `AdditionVoucher`: إضافة الحقول للـ `fillable`/`casts`، علاقة `closedBy()`، hook `saving` يشتق `invoicing_status`، ودوال `isInvoiced()` / `isClosedUninvoiced()` / `invoiceValueMismatch()`.
- `AdditionVoucherLine`: hooks `saved`/`deleted` تُحدِّث `received_value` على الأب (بنمط `SalesInvoice` مع `DeliveryVoucher`).

### 4.4 الخدمة
`app/Services/PurchaseInvoicingService.php`
- `recordInvoice(AdditionVoucher $v, array $data, ?User $u): AdditionVoucher` — يرفض القيمة السالبة (`errors.purchase_invoice.invalid_value`)، يمسح الإقفال إن وُجد، يحدّث الحقول، ويصحّح قيد المورّد إن كان الإذن مرحَّلاً وتغيّرت القيمة — كله في `DB::transaction`.
- `closeWithoutInvoice(AdditionVoucher $v, string $reason, ?User $u): void` — يشترط `Posted` (`errors.purchase_invoice.not_posted`)، ويرفض إقفال إذن له رقم فاتورة (`errors.purchase_invoice.already_invoiced`).
- `reopen(AdditionVoucher $v): void` — يمسح الإقفال ويعيد الحالة إلى `not_invoiced`.
- `recalculateReceivedValue(AdditionVoucher $v): void`.
- `syncSupplierEntry(AdditionVoucher $v, float $amount): void` — private؛ يعدّل `AccountEntry` المرتبط بالإذن.

### 4.5 Filament
- **النموذج**: `invoice_date` بجوار `invoice_number`.
- **الجدول**: عمود `invoicing_status` (badge + وصفه سبب الإقفال)، عمود `received_value` (محجوب عن غير مخوّل الأسعار) بإجمالي `Sum`، إجمالي `Sum` على `invoice_value`، ولون `danger` عند اختلاف القيمتين.
- **الفلاتر**: `SelectFilter` على حالة الفوترة + فلتر `value_mismatch` (فرق بين قيمة الفاتورة والمستلم).
- **الأكشنز** داخل الـ `ActionGroup` القائم (اتفاقية `row-actions-dropdown-convention`): «تسجيل الفاتورة» / «إقفال بدون فاتورة» / «إعادة فتح».

### 4.6 RBAC
- صلاحية جديدة `addition_vouchers.invoice` في `getPermissions()`.
- التوزيع: **Finance** (المالك) + **Procurement** + Admin تلقائياً. **Warehouse_Manager مستثنى عمداً**: الدور محروم أصلاً من `inventory.view_pricing` بنص مواصفات النظام («الأسعار محجوبة عن أمناء المخازن»)، وقيمة الفاتورة مال.
- `AdditionVoucherPolicy::invoice()` جديدة، والأكشنز الثلاثة محمية بها.

### 4.7 i18n / Theme / RTL
- `lang/{en,ar}/resources.php`: إضافات على `addition_vouchers.fields/columns/actions/notifications/filters` + `enums.purchase_invoicing_status`.
- `lang/{en,ar}/errors.php`: كتلة `purchase_invoice` (3 مفاتيح).
- `lang/{en,ar}/permissions.php`: وصف الصلاحية الجديدة.
- كل النصوص عبر `__()`، وألوان badge سيمانتية ⇒ تعمل في الوضعين الفاتح والداكن وفي RTL تلقائياً.

### 4.8 الاختبارات
`tests/Feature/Procurement/PurchaseInvoicingTest.php`:
إذن بلا فاتورة = `not_invoiced`؛ تسجيل فاتورة ⇒ `invoiced`؛ الإقفال بسبب ⇒ `closed_uninvoiced` مع حفظ السبب والمُقفِل؛ رفض إقفال إذن مسودة؛ رفض إقفال إذن مفوتر؛ إعادة الفتح؛ الفاتورة بعد الإقفال تلغيه؛ `received_value` يتتبّع الأسطر؛ اكتشاف فرق القيمة؛ تصحيح قيد المورّد بعد الترحيل **دون إنشاء قيد ثانٍ**؛ عدم المساس بالقيد لإذن غير مرحَّل؛ RBAC؛ مفاتيح الترجمة en/ar؛ ألوان الـ enum.

### 4.9 المخاطر ومعالجتها
| الخطر | المعالجة |
|---|---|
| ازدواج قيد المورّد عند تعديل قيمة الفاتورة | تعديل القيد القائم لا إنشاء جديد + اختبار صريح يعدّ القيود |
| بيانات قديمة بلا حالة | تعبئة رجعية في المهاجرة + `default` صحيح |
| كسر مسار الترحيل الحالي | `AdditionVoucherService::post` لم يُمَس إطلاقاً |
| حالة فوترة كاذبة لو حُرِّر الإذن من النموذج | الاشتقاق في `saving` على الموديل يغطي كل المسارات |

### 4.10 مسح الكاش محلياً
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build
```
> `ensureInitialRolesExist` لا تحدّث الأدوار القائمة ⇒ في البيئات القائمة تُمنح `addition_vouchers.invoice` من شاشة الأدوار (Admin يأخذها تلقائياً).
