# خطة تنفيذ — قسم إدارة المشروعات: الخط الزمني للعملية (Presentation.pptx)

> الحالة: **تم التنفيذ بالكامل (2026-10-12) — غير مرفوع (لا commit / push / deploy).**
>
> فروق عن الخطة أثناء التنفيذ:
> - عدد المراحل الفعلي **18** (8 مبدئية + 7 تنفيذية + 3 تسليم وتركيب)، وليس 19.
> - منح الصلاحيات للأدوار الموجودة: أمر `php artisan permissions:grant-project-management` (idempotent) — يلزم تشغيله على الإنتاج بعد الـdeploy.
> - مكوّن الخط الزمني في `resources/views/filament/components/operation-timeline.blade.php` (داخل مسار يفحصه Tailwind)، والوضع الداكن عبر `et-report` + قواعد `.et-timeline` غير الطبقية في theme.css.
> - endpoint إنهاء التركيب يخزّن المحضر باسم يولّده السيرفر (نفس قاعدة `POST /attachments`).

---

## 1. تحليل العرض (8 شرائح)

| # | المحتوى | المتطلب المستخرج |
|---|---|---|
| 1 | قسم إدارة المشروعات = خدمة عملاء: لما العميل يسأل عن ميعاد التسليم أو الباقي عليه، نعرف العملية في أي مرحلة (رفع مقاسات / تصنيع / تركيب) | شاشة متابعة لكل العمليات: المرحلة الحالية + الخط الزمني + موعد التسليم المتوقع والأيام المتبقية |
| 2 | صورة قسم «مرفقات المشروع» الحالي (UPLOAD / VENDOR LIST / DROWING) + مرفقات جديدة: (1) **UPLOAD يصبح «الرسم المبدئي»** (2) اعتماد الرسم المبدئي (3) الرسم التنفيذي (4) اعتماد الرسم التنفيذي (5) ملاحظات الاستشاري (6) اعتماد الاستشاري (7) محاضر التسليم (8) رسومات من العميل | 7 تصنيفات مرفقات جديدة + إعادة تسمية Upload |
| 3 | مراحل العملية من «العمليات النشطة» حتى التركيب والتسليم بمحاضر الاستلام | النطاق: العمليات النشطة |
| 4 | المخطط الكامل (ثعباني، يُقرأ من اليمين): **المبيعات → التواصل مع العميل → رسومات من العميل → رسم مبدئي → اعتماد الرسم المبدئي → طلب تصنيع (بدء/انتهاء) → جاهزية الموقع → رفع مقاسات → رسم تنفيذي → اعتماد الرسم التنفيذي → طلب التصنيع (بدء/انتهاء) → التسليم → التركيب (بدء/انتهاء)** | ترتيب المراحل. **ملاحظة: أمرا تصنيع منفصلان** — واحد بعد الاعتماد المبدئي وواحد بعد الاعتماد التنفيذي |
| 5 | كل مرحلة «تُعتبر منتهية» بحدث محدد في النظام (التواصل يُسجَّل من قسم إدارة العملاء؛ رسومات العميل/الرسم المبدئي = وجود ملف في المرفقات بهذا الاسم) | الخط الزمني **مشتق تلقائياً** من مستندات النظام، لا إدخال يدوي للمرحلة |
| 6 | المرحلة الأولى: التواصل ← قسم إدارة العملاء · رسومات العميل ← رفع ملفها · الرسم المبدئي ← رفع ملفه · الاعتماد المبدئي ← رفع ملفه · طلب تصنيع ← إنشاء أمر تشغيل · البدء/الانتهاء ← بدء/انتهاء الأمر في المصنع | قواعد الاكتمال للمجموعة 1 |
| 7 | المرحلة الثانية: جاهزية الموقع ← قسم إدارة العملاء · رفع المقاسات ← رفع ملفها · الرسم التنفيذي ← رفع ملفه · الاعتماد التنفيذي ← رفع ملفه · طلب التصنيع/البدء/الانتهاء ← أمر التشغيل | قواعد الاكتمال للمجموعة 2 |
| 8 | التسليم ← عند التسليم · بدء التركيب · انتهاء التركيب **عن طريق محاضر تسليم الأعمال** | قواعد الاكتمال للمجموعة 3 |

---

## 2. الربط بالموجود في المشروع

| المتطلب | الموجود حالياً | الحالة |
|---|---|---|
| مرفقات المشروع بالتصنيف | `AttachmentCategory` enum + `ProjectResource::attachmentCategorySchema()` (FileUpload لكل تصنيف) + `AttachmentPersistence::sync` | 🟡 إضافة 7 تصنيفات + إعادة تسمية `Upload` |
| رفع مقاسات | تصنيف `SiteMeasurement` موجود + `SiteSurvey` (معاينة موقع) | ✅ يُستخدم كإشارة (ملف مقاسات **أو** معاينة موقع مسجلة) |
| الخط الزمني | `OperationTimelineService` (من ماليات.pptx سلايد 7) — مشتق، بدون جداول. مراحله: sales / activated / technical_office / order_approved / in_factory / manufacturing_finished / qa_approved / delivered / installed / completed. معروض في `OperationCostFile` + API `GET projects/{project}/timeline` + اختبار `OperationTimelineTest` | 🟡 **إعادة بناء المراحل** حسب العرض الجديد (نفس فلسفة الاشتقاق) |
| «قسم إدارة العملاء» (التواصل، جاهزية الموقع) | مجموعة التنقل `sales_crm` = «المبيعات وإدارة العملاء» — **لكن لا يوجد سجل تواصل مع العميل إطلاقاً** (`ActivityResource` هو سجل تدقيق النظام) | ❌ جديد: سجل متابعة العملاء |
| طلب تصنيع / البدء / الانتهاء | `WorkOrder.created_at` · `actual_start_date` (يُضبط في `WorkOrderService::start`) · `manufacturing_finished_at` | ✅ الإشارات موجودة — 🟡 ينقص **تحديد مرحلة الأمر** (مبدئي/تنفيذي) |
| التسليم | `DeliveryVoucher.activated_at` (مستخدم فعلاً في الخط الزمني) | ✅ |
| بدء التركيب | `Installation.started_at` (`InstallationService::start`) | ✅ |
| انتهاء التركيب بمحاضر تسليم الأعمال | `InstallationService::complete` يدوي بلا مستند. (`DeliveryMinute` = محضر تسليم البضاعة عند التسليم، ليس محضر تسليم الأعمال) | 🟡 ربط الإنهاء بمرفق «محاضر التسليم» |
| موعد التسليم المتوقع | `Project.end_date` | ✅ يُستخدم للأيام المتبقية/التأخير |
| شاشة إدارة المشروعات | `OperationsOverview` (الإدارة العامة) = عدّادات فقط، بلا مراحل | ❌ شاشة جديدة تحت مجموعة `technical_office` («مكتب إدارة المشروعات») |
| API | مرفقات عامة `POST attachments` (تقبل أي قيمة enum تلقائياً) · `projects/{project}/timeline` · work-orders CRUD · installations start/complete · meta/enums | 🟡 تحديثات (تفصيلها في المرحلة 6) |

---

## 3. قرارات التصميم (اخترت الأقرب للمتطلبات)

1. **الخط الزمني يظل مشتقاً بالكامل** — لا عمود «مرحلة حالية». كل مرحلة تُقرأ من حدث فعلي (مرفق مرفوع، سجل تواصل، أمر تشغيل، إذن تسليم، تركيب). حذف المرفق ⇒ المرحلة ترجع «لم تُنجز».
2. **Upload ← «الرسم المبدئي»:** نُعيد تسمية الحالة إلى `PreliminaryDrawing` مع **الإبقاء على القيمة المخزنة `upload`** — فلا migration للبيانات ولا كسر لعملاء الـAPI الذين يرسلون `upload`. الأثر: الملفات المرفوعة سابقاً تحت UPLOAD ستظهر كـ«الرسم المبدئي» (وهذا ما طلبه العرض حرفياً «بدلاً من upload»).
3. **أمرا التصنيع:** عمود جديد `work_orders.timeline_phase` (`preliminary` | `executive`). القيمة الافتراضية عند الإنشاء تُحسب تلقائياً: `executive` إذا كان للعملية ملف «اعتماد الرسم التنفيذي»، وإلا `preliminary`. قابل للتعديل من فورم أمر التشغيل. الأوامر القديمة: migration يملأها بنفس القاعدة بناءً على تاريخ الإنشاء مقابل تاريخ رفع الاعتماد التنفيذي (لا يوجد ⇒ `executive` لأن أغلب الأوامر الحالية أوامر تنفيذ فعلية).
   - مرحلة «تصنيع» في مجموعة ما = أدنى `created_at` (طلب) / أدنى `actual_start_date` (بدء) / انتهاء **كل** أوامر المجموعة (انتهاء) — نفس منطق `allReached` الحالي.
   - إذا وصلت العملية للمرحلة التنفيذية دون أي أمر مبدئي ⇒ مراحل التصنيع المبدئي تُعلَّم **«تم تخطيها»** (skipped) بدل «لم تُنجز»، حتى لا تبدو العملية متأخرة.
4. **قسم إدارة العملاء:** نموذج جديد `CustomerInteraction` (سجل متابعة العملاء) تحت مجموعة `sales_crm`:
   - الحقول: `project_id`, `customer_id` (يُملأ من العملية), `type` (`contact` تواصل | `site_readiness` جاهزية الموقع | `follow_up` متابعة عامة), `channel` (مكالمة/زيارة/واتساب/بريد), `interacted_at`, `site_ready` (bool — لنوع جاهزية الموقع), `notes`, `created_by`.
   - مرحلة «التواصل مع العميل» = أول تفاعل من نوع `contact`. مرحلة «جاهزية الموقع» = أول تفاعل `site_readiness` بـ`site_ready = true`.
   - يظهر كـ Resource مستقل («متابعة العملاء») + RelationManager على ملف العملية.
5. **انتهاء التركيب بمحاضر التسليم:**
   - أكشن «إنهاء التركيب» في `InstallationResource` يطلب **رفع ملف محضر تسليم الأعمال** (إجباري) ⇒ يُحفظ كمرفق `handover_minutes` على العملية ثم يُنهي التركيب.
   - رفع ملف «محاضر التسليم» من مرفقات العملية (أو من الـAPI) ⇒ **Observer على `Attachment`** يُنهي تلقائياً أي تركيب `InProgress` لنفس العملية. (Observer وليس داخل `AttachmentPersistence` حتى يعمل للـFilament والـAPI معاً.)
   - `InstallationService::complete` يرفض الإنهاء إذا لم يوجد محضر تسليم مرفوع للعملية (حماية على مستوى الخدمة ⇒ تشمل الـAPI).
   - مرحلة «انتهاء التركيب» في الخط الزمني = أدنى تاريخ رفع محضر بعد بدء التركيب، وإلا `Installation.completed_at`.
6. **مراحل التسليم / الأيام المتبقية:** `expected_delivery` = `Project.end_date`؛ `days_remaining` (موجب) أو `days_overdue` (إذا تجاوز ولم يُسلَّم).
7. **الخط الزمني القديم يُستبدل ولا يُكرر** — خط زمني واحد للنظام كله (الشاشة الجديدة + ملف تكلفة العملية + الـAPI + ملف العملية). المراحل غير الواردة في العرض (اعتماد أمر التشغيل، اعتماد الجودة، إتمام العملية) تُحذف من الخط الزمني لأن العرض حدد قائمته صراحةً. ⚠️ هذا يغيّر **مفاتيح** المراحل في استجابة `GET projects/{project}/timeline` (الشكل نفسه يبقى + حقول مضافة) — يجب إبلاغ فريق تطبيق Flutter.

### المراحل النهائية (المفتاح ← الإشارة)

| المجموعة | المفتاح | التسمية | يُعتبر منتهياً عند |
|---|---|---|---|
| **1. المرحلة المبدئية** | `sales` | المبيعات | إنشاء العملية |
| | `customer_contact` | التواصل مع العميل | أول تفاعل `contact` في متابعة العملاء |
| | `customer_drawings` | رسومات من العميل | رفع مرفق `customer_drawings` |
| | `preliminary_drawing` | رسم مبدئي | رفع مرفق `upload` (الرسم المبدئي) |
| | `preliminary_approval` | اعتماد الرسم المبدئي | رفع مرفق `preliminary_approval` |
| | `preliminary_mo_created` | طلب تصنيع | إنشاء أمر تشغيل `preliminary` |
| | `preliminary_mo_started` | بدء التصنيع | `actual_start_date` لأول أمر مبدئي |
| | `preliminary_mo_finished` | انتهاء التصنيع | `manufacturing_finished_at` لكل الأوامر المبدئية |
| **2. المرحلة التنفيذية** | `site_readiness` | جاهزية الموقع | تفاعل `site_readiness` + `site_ready` |
| | `site_measurement` | رفع مقاسات | رفع مرفق `site_measurement` أو تسجيل `SiteSurvey` |
| | `executive_drawing` | رسم تنفيذي | رفع مرفق `executive_drawing` |
| | `executive_approval` | اعتماد الرسم التنفيذي | رفع مرفق `executive_approval` |
| | `executive_mo_created` / `_started` / `_finished` | طلب التصنيع / البدء / الانتهاء | نفس القاعدة لأوامر `executive` |
| **3. التسليم والتركيب** | `delivered` | التسليم | أول إذن تسليم `activated_at` |
| | `installation_started` | بدء التركيب | `Installation.started_at` |
| | `installation_finished` | انتهاء التركيب | محضر تسليم الأعمال (انظر القرار 5) |

لكل مرحلة في الناتج: `key, label, group, at, reached, skipped, detail, days_from_start`. «المرحلة الحالية» = آخر مرحلة منجزة، ومعها اسم المجموعة (يجيب على سؤال العميل: «في رفع مقاسات / تصنيع / تركيب»).

---

## 4. مراحل التنفيذ

كل مرحلة تشمل: صلاحيات (Seeder + Policy) · اختبارات `tests/Feature/ProjectManagement/` · ترجمة en/ar (resources/navigation/errors + كتل `roles.*`) · فاتح/داكن + RTL (ألوان Filament الدلالية + توكنز `var(--surface-*)` في الـblade) · ربط بما يتصل به.

### المرحلة 1 — تصنيفات المرفقات الجديدة
- `AttachmentCategory`: إعادة تسمية `Upload` ← `PreliminaryDrawing` (القيمة `upload`)، وإضافة: `CustomerDrawings` (`customer_drawings`)، `PreliminaryApproval`، `ExecutiveDrawing`، `ExecutiveApproval`، `ConsultantNotes`، `ConsultantApproval`، `HandoverMinutes` — بأيقونات.
- مساعد `projectCategories()` في الـenum بدل قائمة الاستثناءات المكررة في `ProjectResource`.
- فورم المرفقات: تقسيم الـ17 حقلاً إلى مجموعات داخل القسم (Fieldsets قابلة للطي): **مستندات المبيعات** (رسومات من العميل، BOQ، SPECES، VENDOR LIST، Submittal، قبول العميل) · **الرسومات والاعتمادات** (الرسم المبدئي، اعتماده، مقاسات الموقع، DROWING، الرسم التنفيذي، اعتماده) · **الاستشاري** (ملاحظات، اعتماد) · **التسليم** (محاضر التسليم). نفس سلوك الرفع/التنزيل/الصلاحيات الحالي.
- تحديث كل استخدامات `AttachmentCategory::Upload` (إن وُجدت) والاختبارات `AttachmentCategoryTest` / `AttachmentRelocationTest`.
- ترجمات: `resources.enums.attachment_category.*` (ar: الرسم المبدئي، اعتماد الرسم المبدئي، الرسم التنفيذي، اعتماد الرسم التنفيذي، ملاحظات الاستشاري، اعتماد الاستشاري، محاضر التسليم، رسومات من العميل).

### المرحلة 2 — متابعة العملاء (قسم إدارة العملاء)
- Migration `customer_interactions` + Model (SoftDeletes + LogsActivity) + Factory + enums `InteractionType`, `InteractionChannel`.
- `CustomerInteractionResource` (مجموعة `sales_crm`، تسمية «متابعة العملاء»): جدول بفلاتر (نوع، عملية، عميل، تاريخ) + أكشنات صفوف داخل ⋮ ActionGroup (اتفاقية المشروع).
- `CustomerInteractionsRelationManager` على `ProjectResource` + عرض آخر تفاعل على `CustomerResource`.
- صلاحيات: `customer_interactions.view`, `customer_interactions.manage` + `CustomerInteractionPolicy`. تُمنح: Sales, Sales_Manager (manage)؛ Technical_Office, General_Manager (view)؛ Admin تلقائياً.
- إضافة النموذج لـ`ActivityResource` maps و`ActivitiesRelationManager` على العملية (يظهر في تاريخ العملية).

### المرحلة 3 — مرحلة أمر التشغيل (مبدئي/تنفيذي)
- Migration: `work_orders.timeline_phase` (string، nullable→ يُملأ) + backfill حسب القرار 3.
- enum `WorkOrderPhase` + cast + fillable + تعبئة تلقائية في `WorkOrder::booted creating`.
- `WorkOrderResource`: حقل Select في الفورم + عمود Badge + فلتر.
- اختبارات: التعبئة التلقائية قبل/بعد رفع الاعتماد التنفيذي، الـbackfill.

### المرحلة 4 — إعادة بناء الخط الزمني + إنهاء التركيب بالمحاضر
- إعادة كتابة `OperationTimelineService::stages()` بالمراحل الـ19 في 3 مجموعات، مع `skipped` و`group`، و`summary()` جديدة تعيد: المرحلة الحالية + مجموعتها + نسبة الإنجاز + `expected_delivery` + `days_remaining/overdue`.
- أداء: تحميل كل الإشارات باستعلامات مجمّعة (min created_at لكل تصنيف مرفق بـ`groupBy`، إلخ) + دالة `forMany(Collection $projects)` للشاشة الجماعية لتفادي N+1.
- `AttachmentObserver` (created) ⇒ إنهاء التركيب الجاري عند رفع `handover_minutes`.
- `InstallationService::complete`: يتطلب محضر تسليم مرفوع (رسالة خطأ في `errors.php`)؛ أكشن الإنهاء في `InstallationResource` يطلب رفع الملف.
- تحديث عرض الخط الزمني في `operation-cost-file.blade.php` (عناوين المجموعات + حالة «تم التخطي»).
- إعادة كتابة `OperationTimelineTest` لتغطية كل قاعدة اكتمال في الجدول أعلاه + التخطي + التأخير.

### المرحلة 5 — شاشة إدارة المشروعات (الخط الزمني للعمليات)
- صفحة Filament `ProjectsTimeline` تحت مجموعة `technical_office` («مكتب إدارة المشروعات»)، صلاحية جديدة `operations.timeline`.
- جدول العمليات النشطة: الكود، العميل، **المرحلة الحالية (Badge بلون المجموعة)**، شريط نسبة الإنجاز، موعد التسليم المتوقع، الأيام المتبقية / التأخير (أحمر)، فلتر حسب المجموعة الحالية (مبدئية / تنفيذية / تسليم وتركيب) + بحث.
- الضغط على عملية ⇒ slide-over بالخط الزمني الكامل مجمّعاً في 3 مراحل (نفس مكوّن blade المعاد استخدامه من المرحلة 4 — مكوّن `<x-operation-timeline>` واحد).
- قسم «الخط الزمني» داخل ملف العملية (`EditProject`) لمن يملك الصلاحية — يكفي موظف خدمة العملاء فتح العملية.
- منح `operations.timeline`: Sales, Sales_Manager, Technical_Office, Factory_Manager, General_Manager.

### المرحلة 6 — تحديثات الـAPI (تطبيق Flutter)
| الـEndpoint | التحديث |
|---|---|
| `POST/GET attachments` | التصنيفات الجديدة مقبولة تلقائياً (`Rule::enum`)؛ رفع `handover_minutes` يُنهي التركيب عبر الـObserver — اختبار يثبت ذلك |
| `GET meta/enums` | إضافة `InteractionType`, `InteractionChannel`, `WorkOrderPhase` (+ ظهور تصنيفات المرفقات الجديدة) |
| `GET projects/{project}/timeline` | الشكل الجديد: `stages[]` بحقول `group`, `skipped` + `summary` (`current_stage`, `current_group`, `progress_percent`, `expected_delivery`, `days_remaining`, `days_overdue`). ⚠️ مفاتيح المراحل تغيّرت |
| `GET projects` / `projects/{id}` (Sales) | إضافة `current_stage` (من `forMany` لتفادي N+1) |
| `work-orders` store/update/show | حقل `timeline_phase` (اختياري؛ يُحسب تلقائياً إذا لم يُرسل) |
| `installations/{id}/complete` | 422 إذا لا يوجد محضر تسليم؛ يقبل اختيارياً ملف `handover_file` (multipart) يُحفظ ثم يُنهي |
| جديد: `customer-interactions` | CRUD كامل (index بفلاتر project/customer/type، show، store، update، destroy) بنفس اتفاقيات الـAPI (FormRequest، Resource، ability `sales`، صلاحيات `customer_interactions.*`) |
| جديد: `projects-timeline` (Reports) | قائمة العمليات النشطة بملخص الخط الزمني (نفس بيانات الشاشة) |
- اختبارات في `tests/Feature/Api/` + تحديث `API_PROGRESS.md` وتوثيق Scribe annotations.

### المرحلة 7 — الصلاحيات للأدوار الموجودة + التوثيق + التحقق
- الـSeeder لا يعدّل الأدوار الموجودة ⇒ أمر Artisan صغير idempotent (`permissions:grant-timeline`) أو تعليمات tinker لمنح الصلاحيات الجديدة للأدوار القائمة محلياً وفي الإنتاج (وقت الـdeploy).
- تحديث صفحة `/documentation` (أقسام المبيعات + مكتب إدارة المشروعات) بشرح المرفقات الجديدة ومتابعة العملاء والشاشة الجديدة.
- التحقق: `php artisan test` كامل + Pint، ثم وصفة الكاش (`optimize:clear; filament:clear-cached-components; icons:clear; permission:cache-reset; npm run build; queue:restart`) ومراجعة بصرية للشاشات فاتح/داكن + RTL.

---

## 5. مخاطر / ملاحظات
- **تغيير مفاتيح مراحل الـtimeline في الـAPI** — يحتاج إبلاغ فريق Flutter (الحقول القديمة `technical_office`, `in_factory`, `qa_approved`… لن تعود).
- الملفات المرفوعة سابقاً تحت UPLOAD ستُحسب «رسماً مبدئياً» ⇒ عمليات قديمة قد تظهر بمرحلة «رسم مبدئي» منجزة.
- Backfill أوامر التشغيل القديمة تقريبي (أغلبها `executive`)؛ قابل للتصحيح يدوياً من الفورم.
- لا إشعارات عند تغيّر المرحلة (غير مطلوب في العرض) — يمكن إضافتها لاحقاً.
