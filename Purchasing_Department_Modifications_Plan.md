# خطة تنفيذ تعديلات قسم المشتريات (Purchasing / Procurement — Modifications Round)

> مصدر المتطلبات: `المشتريات.pptx` (11 شريحة) + `المشتريات.md`.
> جولة **مراجعة/تغذية راجعة** على وحدة المشتريات المبنيّة بالفعل (PurchaseOrder + Supplier +
> AdditionVoucher). ليست بناءً من الصفر. تتبع نفس منهجية خطط الأقسام السابقة
> (`Inventory_Department_Plan.md`، `Sales_Department_Modifications_Plan.md`).
>
> **الحالة: خطة للمراجعة — لم يبدأ التنفيذ.** عند الموافقة («ابدأ التنفيذ») نُنفّذ مرحلة بمرحلة.

---

## 1. ملخّص المعمارية الحالية (ما الذي نعدّله)

| الطبقة | الملف | الدور الحالي |
|--------|-------|--------------|
| نموذج أمر الشراء | `app/Models/PurchaseOrder.php` | `project_id, supplier_id, po_number, supplier_name, supplier_contact, status, total_amount, notes, expected_delivery_date, created_by, approved_by, approved_at`. مولّد رقم `PO-YYYYMM-XXXX`. `recalculateTotal()` = Σ(كمية×سعر) فقط |
| بند الأمر | `app/Models/PurchaseOrderItem.php` | `item_id, quantity, unit_price, received_quantity` + `line_total / remaining_quantity / isFullyReceived()` |
| المورد | `app/Models/Supplier.php` | `name, contact_person, phone, email, tax_number, address, notes` + `balance` (من AccountEntry) |
| إذن الإضافة | `app/Models/AdditionVoucher.php` (+`AdditionVoucherLine`) | مستند الاستلام. `voucher_number (AV-YYYYMM-XXXX)`, `supplier_id (مطلوب)`, `purchase_order_id (اختياري!)`, `invoice_*`, `status (draft/posted)`. `post()` يضيف مخزون + يقيّد على حساب المورد |
| الصنف | `app/Models/Item.php` + `ItemResource` | `sku, name, type, unit, unit_cost, minimum_stock, description`. صفحات: List/Create/Edit فقط (لا ViewItem) |
| فورم/جدول أمر الشراء | `app/Filament/Resources/PurchaseOrderResource.php` | الفورم يستخدم **حقول نصّية حرّة** `supplier_name`/`supplier_contact` (وليس العلاقة!)، المشروع غير مفلتر، الحالة Select يدوي، زر `receive` يضيف مخزون مباشرةً |
| خدمة الأمر | `app/Services/PurchaseOrderService.php` | `receiveItems()` → يحدّث `received_quantity` + `InventoryService::addStock(reference: $PO)` **بدون** إذن إضافة و**بدون** قيد مورد |
| إذن الإضافة (خدمة) | `app/Services/AdditionVoucherService.php` | `post()` → مخزون + `AccountEntry` (Credit) للمورد |
| الحالة (enum) | `app/Enums/PurchaseOrderStatus.php` | Draft / Submitted / PartiallyReceived / Received / Cancelled |
| الصلاحيات | `RoleAndPermissionSeeder` + `PurchaseOrderPolicy` | `purchase_orders.{view,create,edit,approve,receive,delete}` موجودة. `Procurement` يملك approve/receive؛ **`Technical_Office` لا يملك أي صلاحية مشتريات** |
| الترجمة | `lang/{en,ar}/resources.php` (`purchase_orders`, `suppliers`) + `navigation.php` (`groups.procurement`) + `errors.php` (`purchase_order`) | AR/EN كاملة للموجود |
| المرفقات (مرجع للنمط) | `app/Models/Attachment.php` + `AttachmentCategory` + `ProjectResource\Pages\AttachmentPersistence.php` | مرتبط بـ `project_id` فقط (NOT NULL). FileUpload متعدّد/قابل للتنزيل/الفتح على القرص `public` |
| الطباعة (مرجع للنمط) | `OfferPdfController` + `resources/views/pdf/offer.blade.php` + `routes/web.php` | mpdf، RTL/LTR، علامة مائية، `?lang=ar\|en`، ActionGroup `print_en/print_ar` |

### ثغرات/حقائق مفصلية اكتُشِفت أثناء التحليل (مهمّة للقرارات)
1. **`supplier_id` موجود فعلًا** على جدول `purchase_orders` (مهاجرة `2026_06_01_000002`) لكنّ الفورم **يتجاهله** ويكتب اسمًا حرًّا. ⇒ الربط «تفعيل» وليس بناءً جديدًا.
2. **مساران لإضافة المخزون**: «استلام أمر الشراء» (يضيف مخزون مباشرة) و«ترحيل إذن الإضافة» (يضيف مخزون + قيد مورد). تشغيلهما معًا لنفس البضاعة ⇒ **ازدواج مخزون**. هذا بالضبط ما تطلب الشرائح 1/7/9 توحيده.
3. `AdditionVoucherResource` **يحتوي مسبقًا** على `Select` لـ `purchase_order_id` ⇒ نيّة التصميم أصلًا أن يكون إذن الإضافة هو مستند استلام أمر الشراء.
4. لا توجد ضرائب (14% قيمة مضافة / 1% أرباح تجارية) في أي مكان حاليًا — `total_amount` = المجموع الخام فقط.
5. لا توجد طباعة PDF لأمر الشراء، ولا مرفقات للمورد/الأمر، ولا صفحة عرض (كرت) للصنف.

---

## 2. خريطة الشرائح → المتطلبات (التحليل والربط الذكي)

| الشريحة | الطلب (مختصر) | الحالة | الجزء المتأثر | المرحلة |
|---------|----------------|:------:|----------------|:------:|
| **1** | دورة العمل: إنشاء كمسودة → اعتماد مدير المكتب الفني (يتحوّل تلقائيًا «مرسل») → استلام المخازن بإذن إضافة → إقفال الأمر بالمقارنة (مستلم/جزئي) | 🟡 | المخطّط العام للدورة (تتفرّع على م2 + م5) | 2 و5 |
| **2** | (مخطّط مفاهيمي) دراسة الكميات/مراجعة المخزون؛ الحجز لا أثر فعلي على المخزن؛ الخروج بإذن صرف | ✅ | مفاهيمي — مغطّى بـ StockReservation/IssueVoucher. لا تغيير | — |
| **3** | منع إنشاء أمر شراء لمورد غير مسجّل (ونفسه للعملاء) + مرفقات المورد (سجل تجاري/بطاقة ضريبية) + ضريبة 14% قيمة مضافة و**خصم 1%** أرباح تجارية، مع **استثناء 1% لبعض الموردين** + مستند إثبات | ❌🟡 | فورم الأمر (علاقة المورد) + حقول ضريبية + إعداد + مرفقات المورد | 1 و3 |
| **4** | المشاريع = **النشطة فقط** + **حذف «بيانات تواصل المورد»** (موجودة في ملف المورد) | 🟡 | فورم أمر الشراء | 1 |
| **5** | **زر اعتماد** أمر الشراء من خلال مدير المكتب الفني | ❌ | جدول الأمر (Action) + Policy + RBAC | 2 |
| **6** | **إرفاق صورة لأمر الشراء** (تحوي تفاصيل أكثر: ض.ق.م + ض. الأرباح) + بنود الأمر | 🟡 | مرفقات الأمر + (التفاصيل تظهر في PDF م4) | 3 |
| **7** | **إقفال الأمر برقم إذن الإضافة** والمقارنة تلقائيًا + مراجعة إذن الإضافة تُظهر أمر الشراء (واعتماد المكتب الفني للوارد) + **إرفاق الملف** | 🟡 | توحيد الاستلام عبر إذن الإضافة | 5 |
| **8** | **طباعة أمر الشراء** كمستند (بيانات المورد، خصم/عدم خصم 1%، اعتماد مدير المكتب الفني) — «تقارير داخلية» | ❌ | PdfController + Blade + Route + Action | 4 |
| **9** | إذن الإضافة: **Serial Number** (موجود) + **اسم المورد لا يُشترط ربطه بملف مورد** (توجد أذون بلا فاتورة/أمر شراء) + عدّة أصناف (موجود) | 🟡 | جعل مورد إذن الإضافة اختياريًا + اسم حرّ احتياطي | 5 |
| **10** | **كرت صنف** عند الضغط على أي صنف + ملاحظة أن «تكلفة الوحدة متغيّرة» (سعر السوق) | 🟡 | صفحة ViewItem + رابط فتح الصنف من بند الأمر | 3 |
| **11** | **إرفاق الملف وليس ملاحظات** (في حوار SMB/«تحت اليد») | 🟡 | إضافة FileUpload للحوار (جانب المبيعات/العمليات — أولوية أدنى) | 6 |

> **ملاحظة على الشريحة 11:** الحوار المعروض هو حوار مبيعات/عمليات («نقل العملية إلى تحت اليد» + ملاحظة SMB) وموجود في `InHandProjectResource` (الإجراء `action` حول السطر 145). أُدرِجَ في ديك المشتريات على الأرجح كملاحظة واجهة عامة: «اجعلها مرفق ملف لا حقل ملاحظة». نُنفّذها كبند صغير منفصل في المرحلة 6 مع تنبيه أنها خارج صميم المشتريات.

---

## 3. قرارات تصميمية (اتُّخِذت اختيارًا للأنسب — قابلة للنقض قبل التنفيذ)

1. **«المشاريع النشطة»** = `ProjectStatus::InProgress` + `ProjectStatus::InHand` (المشاريع المُرساة/قيد التنفيذ — المشتريات تحدث في كليهما). البديل الأضيق: `InProgress` فقط (يطابق `scopeActive()` و`ActiveProjectResource`) — تغيير سطر واحد.
2. **المورد مصدرًا للحقيقة:** الفورم يستخدم `Select supplier_id` (relationship) **مطلوبًا**؛ نحتفظ بـ `supplier_name` كـ **لقطة (snapshot)** تُملأ تلقائيًا من المورد عند الحفظ (حتى لا يتغيّر اسم أوامر قديمة لو أُعيد تسمية المورد، ولأجل الـ PDF). نحذف حقل `supplier_contact` من الفورم (العمود يبقى للتوافق الخلفي). العمود `supplier_id` يبقى nullable في DB (لأوامر قديمة) لكنه **مطلوب على مستوى الفورم**.
3. **الضرائب إعداد:** ملف جديد `config/procurement.php`: `vat_percentage = 14`, `profit_tax_percentage = 1`. أمر الشراء يخزّن `subtotal, vat_amount, profit_tax_amount, apply_profit_tax`؛ والمعادلة:
   `total = subtotal + (subtotal × 14%) − (apply_profit_tax ? subtotal × 1% : 0)`.
4. **استثناء الـ 1%:** حقل `profit_tax_exempt` (boolean، الافتراضي `false` = **يُخصم** 1%) على المورد + مرفق إثبات للاستثناء. عند إنشاء الأمر تُلتقط القيمة في `apply_profit_tax = ! supplier->profit_tax_exempt` كـ snapshot.
5. **توحيد الاستلام عبر إذن الإضافة (القرار المعماري الأهم):** إذن الإضافة هو مستند الاستلام الوحيد الذي يضيف مخزونًا. عند **ترحيل** إذن إضافة مربوط بأمر شراء، يقوم تلقائيًا بـ: تحديث `received_quantity` لبنود الأمر المطابقة + إعادة حساب حالة الأمر (Submitted→Partially→Received). ونُزيل إضافة المخزون المباشرة من «استلام أمر الشراء» لمنع الازدواج. (تفصيل المخاطرة والبدائل في المرحلة 5.)
6. **اعتماد الأمر:** زر `approve` ينقل Draft→Submitted (= «مرسل») ويسجّل `approved_by/approved_at`. الصلاحية `purchase_orders.approve` موجودة؛ نمنحها (مع `view`) لدور `Technical_Office` **يدويًا** لأن الـSeeder لا يعدّل أدوارًا قائمة.
7. **المرفقات (المورد/الأمر):** نوسّع `Attachment` ليصبح polymorphic (إضافة `attachable_type/attachable_id` nullable، وجعل `project_id` nullable). المشروع يستمر عبر `project_id` كما هو (صفر مخاطرة على المرفقات القائمة)، والمورد/الأمر يستخدمان الـ morph. فئات جديدة في `AttachmentCategory`. البديل: جدولان مستقلان `supplier_attachments`/`purchase_order_attachments` (أقل أناقة لكن أعزل). **الموصى به: التوسعة الـ polymorphic.**
8. **مورد إذن الإضافة اختياري:** `supplier_id` يصبح nullable + حقل `supplier_name` حرّ احتياطي؛ لا يُنشأ قيد حساب مورد إلا عند وجود `supplier_id`. (يطابق الشريحة 9.)

---

## 4. قيود شاملة على كل المراحل (إلزامية — تتبع منهجية الأقسام)

1. **AR/EN:** أي نص جديد يُضاف كمفتاح في **كلا** `lang/en/resources.php` و`lang/ar/resources.php` تحت `purchase_orders`/`suppliers`/`addition_vouchers`/`items` (نفس البنية: `fields/sections/columns/actions/notifications/pdf`)، و`enums.purchase_order_status` عند الحاجة. ممنوع نص hard-coded في الفورم أو الـ PDF. أي صلاحية جديدة تُضاف لكتلتي `roles.groups` و`roles.permissions` (AR+EN) لواجهة إدارة الأدوار.
2. **الثيم (فاتح/داكن + RTL):** كل عناصر الفورم مكوّنات Filament (ألوان دلالية semantic) ⇒ تتبع الثيم تلقائيًا. الـ Blade للـ PDF خاص بالطباعة (يحترم RTL/LTR عبر `app()->getLocale()` مثل قالب العرض)، لا علاقة له بالثيم.
3. **الكاش/المزامنة:** جداول `purchase_orders, purchase_order_items, suppliers, addition_vouchers, attachments` **ليست** ضمن سجل المزامنة (`config/sync.php`: project, item, inventory, bom, bom_item, work_order, inventory_transaction فقط) ⇒ إضافة أعمدة آمنة. `Item` قابل للمزامنة لكن read-only (`syncWritableFields()` ترجع `[]`) ⇒ لا نضيف أعمدة لـ items. عدّاد لوحة `dashboard:pending_pos` يُبطَل أصلًا في `PurchaseOrderObserver::saved/deleted` ⇒ تغيّر الحالة عبر الاعتماد/الاستلام يُبطله تلقائيًا (لا كاش جديد مطلوب).
4. **الصلاحيات (RBAC):** كل إجراء/قسم جديد يُغلَّف بـ Policy method وصلاحية. منح صلاحيات لأدوار **قائمة** يتم يدويًا (الـSeeder لا يمسّها — Admin فقط يُزامَن تلقائيًا).
5. **الاختبارات:** لكل مرحلة اختبارات في `tests/Feature/Procurement/` (مجلد جديد) على نمط `tests/Feature/Sales/` (RBAC، i18n parity، منطق الحسابات/الانتقالات). الإبقاء على الجناح أخضر؛ فشل `NetworkResilienceTest` (2) سابق وغير متعلّق.
6. **بعد كل تنفيذ:** وصفة الكاش المحلية: `php artisan optimize:clear; filament:clear-cached-components; icons:clear; permission:cache-reset; npm run build; queue:restart`.

---

## 5. المراحل (مرتّبة من الأسهل إلى الأصعب)

### المرحلة 1 — فورم أمر الشراء: المورد + المشاريع النشطة + الضرائب (شرائح 3 جزئيًا، 4)

**الهدف:** ربط المورد الحقيقي، فلترة المشاريع، حذف بيانات التواصل، وإدخال منطق الضرائب (14% + خصم 1% مع الاستثناء).

**التغييرات:**
- **مهاجرة** `..._add_tax_columns_to_purchase_orders`: `subtotal dec(15,2) default 0`, `vat_amount dec(15,2) default 0`, `profit_tax_amount dec(15,2) default 0`, `apply_profit_tax boolean default true`. (`total_amount` موجود.)
- **مهاجرة** `..._add_profit_tax_exempt_to_suppliers`: `profit_tax_exempt boolean default false`.
- **`config/procurement.php`** جديد: `vat_percentage`, `profit_tax_percentage` (+ تعليقات بالعربي مثل `config/operations.php`).
- **`PurchaseOrder`**: إضافة الأعمدة للـ `fillable` + `casts`؛ إعادة كتابة `recalculateTotal()` لتحسب subtotal/vat/profit_tax/total؛ `logOnly` يضمّ الأعمدة الجديدة. علاقة `supplier()` موجودة.
- **`PurchaseOrderResource::form`**:
  - استبدال `TextInput supplier_name` + `TextInput supplier_contact` بـ `Select::make('supplier_id')->relationship('supplier','name')->searchable()->preload()->required()->createOptionForm(...)` (إنشاء مورد سريع اختياري). حذف `supplier_contact`.
  - `Select project_id` → `->relationship('project','name', fn ($q) => $q->whereIn('status', [InProgress, InHand]))` أو `modifyQueryUsing`.
  - الحالة: إزالة التعديل اليدوي للحالة (افتراضي Draft، تُدار بالإجراءات) — أو `->disabled()->dehydrated()` بقيمة Draft عند الإنشاء.
  - قسم/Placeholder «الإجماليات» (subtotal/VAT 14%/خصم 1%/الإجمالي) live، خلف صلاحية `inventory.view_pricing`.
- **`CreatePurchaseOrder`/`EditPurchaseOrder`**: `mutateFormDataBeforeSave` يضبط `supplier_name = Supplier::find(supplier_id)->name` (snapshot) و`apply_profit_tax = ! supplier->profit_tax_exempt`. استدعاء `recalculateTotal()` بعد حفظ البنود (`afterSave`/`afterCreate`).
- **`SupplierResource::form`**: إضافة `Toggle profit_tax_exempt` (+ helper).
- **`SupplierResource::table`** و**`PurchaseOrderResource::table`**: أعمدة الضريبة/الاستثناء (toggleable).

**RBAC:** لا جديد. **i18n:** `purchase_orders.fields.{subtotal,vat_amount,profit_tax_amount,apply_profit_tax}`, `sections.totals`, `suppliers.fields.profit_tax_exempt(_helper)`, `config` labels. **اختبارات:** حساب الإجماليات (مع/بدون استثناء)، المورد مطلوب، فلتر المشاريع النشطة، اختفاء `supplier_contact`. **كاش:** لا جديد.

---

### المرحلة 2 — اعتماد أمر الشراء (مدير المكتب الفني) (شرائح 1.2، 5)

**الهدف:** زر اعتماد ينقل Draft → Submitted (مرسل) ويثبت المعتمِد ووقته.

**التغييرات:**
- **`PurchaseOrderResource::table` action `approve`**: مرئي عند `status === Draft` و`can('approve', $record)`؛ `requiresConfirmation`؛ يضبط `status = Submitted, approved_by = auth()->id(), approved_at = now()`؛ إشعار نجاح.
- **`PurchaseOrderPolicy::approve(User, PO)`** = `can('purchase_orders.approve')`.
- منطق اختياري: منع `edit`/`delete` بعد الاعتماد (تقييد التعديل على Draft) — توصية، يُحسم عند التنفيذ.
- **منح يدوي:** `Technical_Office` يحصل على `purchase_orders.view` + `purchase_orders.approve` (تنكر/واجهة) — موثّق في «ما بعد التنفيذ». (بديل: إبقاء الاعتماد على دور `Procurement` الذي يملكه أصلًا، لكن النص يقول «مدير المكتب الفني».)
- تأكيد تسمية AR لـ `Submitted` = «مرسل» في `enums.purchase_order_status.submitted` (يطابق الشريحة 5).

**RBAC:** منح يدوي لـ Technical_Office. **i18n:** `purchase_orders.actions.approve`, `notifications.approved`, تأكيد label «مرسل». **اختبارات:** الاعتماد ينقل الحالة + يثبت approved_by/at؛ غير المصرّح له لا يرى الزر؛ الزر يختفي بعد الاعتماد. **كاش:** `pending_pos` يُبطَل تلقائيًا عبر الـObserver عند تغيّر الحالة.

---

### المرحلة 3 — مرفقات المورد وأمر الشراء + كرت الصنف (شرائح 3، 6، 10)

**الهدف:** رفع مستندات المورد (سجل تجاري/بطاقة ضريبية/إثبات استثناء 1%) وصورة أمر الشراء، وفتح كرت الصنف.

**التغييرات:**
- **مهاجرة** توسعة `attachments`: `attachable_type`/`attachable_id` (nullable, index)، وجعل `project_id` nullable. **`Attachment`**: علاقة `attachable()` morphTo + إبقاء `project()`؛ تعميم منطق الفئات.
- **`AttachmentCategory`**: فئات جديدة `SupplierCommercialRegistry='commercial_registry'`, `SupplierTaxCard='tax_card'`, `SupplierProfitTaxExemption='profit_tax_exemption'`, `PurchaseOrderScan='po_scan'` (+ AR/EN labels + أيقونات).
- **خدمة تعميم** `AttachmentPersistence` لتقبل أي `Model` (morph) لا المشروع فقط — أو نسخة `EntityAttachmentPersistence` بنفس نمط `relocatePending/sync`.
- **`SupplierResource::form`**: قسم مرفقات (FileUpload لكل فئة مورد) خلف `suppliers.edit`. **`SupplierResource` Create/Edit pages**: hooks `mutateFormDataBefore*`+`after*` لمزامنة المرفقات (نمط ProjectResource).
- **`PurchaseOrderResource::form`**: قسم «صورة أمر الشراء» (FileUpload فئة `po_scan`) + hooks المزامنة في صفحات الأمر.
- **`ItemResource`**: إضافة `ViewItem` page (كرت قراءة) في `getPages()` + `ViewAction` يفتحها. **`PurchaseOrderResource` بند الأمر**: `Select item_id` يحصل على `->suffixAction(Action 'open_item' ->url(ItemResource::getUrl('view', [record])) openUrlInNewTab)` أو hint رابط.

**RBAC:** البوابات `suppliers.edit`/`purchase_orders.edit`/`attachments.download`؛ `items.view` لكرت الصنف. **i18n:** `enums.attachment_category.*` الجديدة، `suppliers.sections.attachments`, `purchase_orders.sections.attachment`, `items` actions/view. **اختبارات:** رفع/قراءة مرفق مورد وربطه polymorphic؛ مرفق أمر الشراء؛ ViewItem يعمل؛ المشروع لا يتأثر (مرفقاته القديمة سليمة). **كاش/مزامنة:** آمن (لا items عمود).

---

### المرحلة 4 — طباعة أمر الشراء PDF (شرائح 8، 6)

**الهدف:** مستند Purchase Order قابل للطباعة (ترويسة الشركة، بيانات المورد، البنود، subtotal، ض.ق.م 14%، خصم/عدم خصم 1%، الإجمالي، اعتماد مدير المكتب الفني).

**التغييرات (انعكاس نمط العرض):**
- **`app/Http/Controllers/PurchaseOrderPdfController.php`**: نسخة من `OfferPdfController` — `Gate::authorize('print', $po)`، `?lang=ar|en`، تحميل `supplier, items.item, project, approvedBy, createdBy`، mpdf (utf-8/A4/autoScriptToLang)، علامة مائية اختيارية.
- **`resources/views/pdf/purchase-order.blade.php`**: ترويسة ELECTROTECH (base64 logo) + عنوان PURCHASE ORDER + جدول ميتا (رقم الأمر/التاريخ/المورد/المشروع) + جدول البنود (صنف/كمية/سعر/إجمالي) + الإجماليات (subtotal، VAT 14%، خصم 1% إن وُجد، Total) + سطر «المورد لم يُخصم منه 1%» شرطيًا + بلوك توقيع «اعتماد مدير المكتب الفني» (`approvedBy->name`) + تذييل العناوين.
- **`routes/web.php`**: `Route::middleware('auth')->get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderPdfController::class,'show'])->name('purchase_orders.pdf')`.
- **`PurchaseOrderResource::table`**: ActionGroup `print` (`print_en`/`print_ar`) `openUrlInNewTab`، مرئي عند `can('print', $record)`.
- **`PurchaseOrderPolicy::print`** = `can('purchase_orders.print')`.
- **صلاحية جديدة** `purchase_orders.print` في `RoleAndPermissionSeeder` (Admin تلقائي؛ تُضاف لتعريفات Procurement/Technical_Office/Finance الافتراضية، ومنح يدوي للأدوار القائمة).

**RBAC:** صلاحية `purchase_orders.print` + Policy. **i18n:** `purchase_orders.pdf.*` (title, fields, vat, profit_tax, no_deduction_note, approved_by, signature)، `actions.{print,print_en,print_ar}`، كتلتا roles للصلاحية الجديدة. **اختبارات:** smoke للـ PDF (200 + Content-Type)، بوابة الصلاحية (403)، طباعة عربي/إنجليزي. **كاش:** لا.

---

### المرحلة 5 — توحيد الاستلام عبر إذن الإضافة (الأصعب) (شرائح 1.3‑1.4، 7، 9)

**الهدف:** جعل إذن الإضافة مستند الاستلام الفعلي المربوط بأمر الشراء، مع مقارنة تلقائية وإقفال الأمر، ومنع ازدواج المخزون، وجعل مورد الإذن اختياريًا.

**التغييرات:**
- **مهاجرة** `addition_vouchers`: جعل `supplier_id` nullable + إضافة `supplier_name` (string nullable). **`AdditionVoucher`**: `supplier_name` في fillable؛ accessor لاسم العرض (`supplier?->name ?? supplier_name`).
- **`AdditionVoucherResource::form`**: `supplier_id` يصبح غير مطلوب (`->required(false)`) + `TextInput supplier_name` يظهر عند غياب المورد؛ + (اختياري) FileUpload لصورة الإذن (فئة مرفق جديدة `addition_voucher_scan`).
- **`AdditionVoucherService::post()`**: بعد إضافة المخزون — إن `purchase_order_id` موجود: لكل سطر، طابِق بند الأمر بنفس `item_id` وزِد `received_quantity` (بحدّ المطلوب)، ثم أعد حساب حالة الأمر (منطق `updatePurchaseOrderStatus`). القيد المحاسبي للمورد **فقط** عند `supplier_id` موجود.
- **`PurchaseOrder`**: علاقة عكسية `additionVouchers()` (hasMany) لعرض الأذون المربوطة + عمود/قسم «المستلَم عبر» في صفحة عرض الأمر.
- **إعادة هيكلة «استلام أمر الشراء»** (القرار الحرج):
  - **الموصى به (Option B):** استبدال زر `receive` الحالي بإجراء «إنشاء إذن إضافة» يفتح فورم إنشاء AV مُعبّأً مسبقًا (المشروع/المورد من الأمر + أسطر بالكميات المتبقية + `purchase_order_id` مربوط). الاستلام الفعلي يتم بترحيل الإذن (الذي يضيف المخزون ويحدّث الأمر). **نُزيل** `InventoryService::addStock` المباشر من `PurchaseOrderService::receiveItems` (أو نُلغي الدالة) لمنع الازدواج.
  - **بديل أخفّ (Option A):** إبقاء حوار الاستلام لكن إضافة حقل «رقم إذن الإضافة» + FileUpload، وربطه/تسجيله فقط (دون إضافة مخزون مزدوجة) — أقل تكاملًا.
- **مقارنة تلقائية:** منطق `received vs ordered` موجود (`isFullyReceived/remaining_quantity`) ⇒ يُعاد استخدامه عند الترحيل.

**⚠️ مخاطر يجب التعامل معها في هذه المرحلة:**
- **ازدواج المخزون** إن بقي المساران فعّالين — لذا الإزالة الصريحة لإضافة المخزون من مسار الأمر شرط.
- **اختبارات/مصانع قائمة** تعتمد على `receiveItems` الحالي — تُحدَّث أو يُحافَظ على الدالة كغلاف يُنشئ AV.
- **توافق البيانات القديمة:** أوامر مستلمة سابقًا (received_quantity>0) دون AV — لا رجعية مطلوبة، فقط عدم كسرها.

**RBAC:** `purchase_orders.receive` (موجود) للإجراء؛ `addition_vouchers` صلاحيات الترحيل (موجودة). **i18n:** `addition_vouchers.fields.supplier_name`, `purchase_orders.actions.create_addition_voucher`, relations «الأذون المربوطة»، رسائل المقارنة. **اختبارات:** ترحيل AV مربوط يرفع `received_quantity` ويضبط الحالة (جزئي/كامل)؛ **لا ازدواج مخزون** (تأكيد رصيد واحد)؛ AV بلا مورد لا يُنشئ قيدًا؛ AV بمورد يُنشئ قيدًا. **كاش:** `pending_pos` يُبطَل عبر الـObserver.

---

### المرحلة 6 — إرفاق ملف بدل ملاحظة في حوار SMB/«تحت اليد» (شريحة 11)

**الهدف:** في حوار التأكيد ذي ملاحظة SMB، إتاحة إرفاق ملف بدل/إضافةً إلى الملاحظة.

**التغييرات:**
- في `InHandProjectResource` (الإجراء `action` حول السطر 145–183): إضافة `FileUpload` اختياري للحوار، يُخزَّن كمرفق للمشروع (فئة مناسبة، مثلًا `Submittal` أو فئة جديدة `customer_acceptance`).
- **تنبيه:** هذا بند جانب مبيعات/عمليات، أولوية أدنى؛ يُنفّذ بعد صميم المشتريات. يُراجَع مع المستخدم أي حوار بالضبط مقصود إن لزم.

**RBAC:** صلاحية الإجراء الحالية. **i18n:** label/helper للمرفق. **اختبارات:** الحوار يقبل ملفًا ويخزّنه. **كاش:** لا.

---

## 6. ملخّص الملفات المتأثرة (مرجع سريع)

| النوع | الملفات |
|------|---------|
| **مهاجرات (جديدة)** | tax columns على `purchase_orders`؛ `profit_tax_exempt` على `suppliers`؛ توسعة polymorphic لـ `attachments`؛ `addition_vouchers` (supplier nullable + supplier_name) |
| **Config** | `config/procurement.php` (جديد) |
| **Models** | `PurchaseOrder`, `Supplier`, `AdditionVoucher`, `Attachment` |
| **Enums** | `AttachmentCategory` (فئات جديدة) |
| **Resources/Forms** | `PurchaseOrderResource`, `SupplierResource`, `AdditionVoucherResource`, `ItemResource` (+ صفحاتها Create/Edit/View) |
| **Services** | `PurchaseOrderService`, `AdditionVoucherService`, (تعميم) `AttachmentPersistence` |
| **Policies** | `PurchaseOrderPolicy` (approve, print) |
| **PDF** | `PurchaseOrderPdfController` + `resources/views/pdf/purchase-order.blade.php` + `routes/web.php` |
| **RBAC** | `RoleAndPermissionSeeder` (`purchase_orders.print`) + منح يدوي لـ Technical_Office |
| **i18n** | `lang/{en,ar}/resources.php` (+ `enums`, `roles.{groups,permissions}`), `errors.php` عند الحاجة |
| **Tests** | `tests/Feature/Procurement/*` (جديد) |
| **(جانبي)** | `InHandProjectResource` (شريحة 11) |

---

## 7. أسئلة/قرارات مفتوحة للمراجعة قبل التنفيذ

1. **«المشاريع النشطة»** = InProgress + InHand (المقترح) أم InProgress فقط؟
2. **مسار الاستلام** (المرحلة 5): Option B (توحيد عبر إذن الإضافة — الموصى به) أم Option A (التقاط رقم/ملف فقط)؟ هذا أكبر قرار معماري وله أثر على المخزون.
3. **اعتماد الأمر:** دور `Technical_Office` (كما يقول النص) أم الإبقاء على `Procurement`؟
4. **المرفقات:** توسعة `Attachment` polymorphic (الموصى به) أم جداول منفصلة؟
5. **الشريحة 11**: تأكيد أنها مقصودة ضمن هذه الجولة (تبدو جانب مبيعات).

> الافتراضات أعلاه مأخوذة بالخيار الأنسب/الأقرب للمتطلبات (وفق منهجية الأقسام). عند «ابدأ التنفيذ» نبدأ بالمرحلة 1 ونتوقف للمراجعة بين المراحل إن رغبت.
