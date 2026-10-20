# خطة إنشاء مستخدمي الموظفين وصلاحياتهم (Staff Users & RBAC)

المصدر: رسالة م. حسام (واتساب 2026-10-20) + `New Microsoft Excel Worksheet.xlsx`
> "عايز user بأسماء الناس دي، وكل user له وظائف معينة، كل user هيشوف الجزء الخاص به فقط"

الحالة: **تم التنفيذ (2026-10-20).** القرارات المأخوذة: Q1 = (ب) فتح الملف للعرض + إضافة مرفقات فقط (إضافة بلا حذف)؛
Q2 = إيميلات `name@electrotech.com` + كلمة مرور عشوائية تُطبع مرة واحدة؛ Q3/Q4 = لا اعتماد (يبقى لمكتب المشروعات / مدير المصنع)؛
Q5 = عرض فقط؛ Q6 = بلا أسعار؛ Q7 = لا dump من الإنتاج (اختبار على قاعدة محلية).
التنفيذ الفعلي استخدم أدواراً جديدة بالكامل (Sales_Officer, Sales_Head, Timeline_Viewer, Timeline_Coordinator,
Active_Operations_Viewer, Purchasing_Officer, Items_Editor, Purchase_Orders_Viewer, Warehouse_Keeper, Ledger_Viewer,
Quality_Inspector, Production_Viewer) بدل تعديل الأدوار القديمة، لأن الأدوار القديمة تمنح شاشات خارج نطاق كل موظف.
الاختبارات: tests/Feature/StaffAccessMatrixTest.php.

---

## 1. تحليل الشيت (مطابقة كل سطر بالشاشة والصلاحية الفعلية في الكود)

| # | الموظف | القسم ← الشاشة | المطلوب | الصلاحيات في الكود |
|---|---|---|---|---|
| 1 | **أسماء محمد** | مكتب إدارة المشروعات ← الخط الزمني للعمليات | تعديل | `operations.timeline` + (رابط "فتح الملف" يظهر فقط بـ `projects.edit`) — انظر سؤال Q1 |
|   |   | المبيعات ← العمليات النشطة | إضافة الأوراق ومرفقات المشروع فقط | `projects.view` + `attachments.upload` + `attachments.download` (بدون `projects.edit`) |
| 2 | **ياسين محمد** | المبيعات ← العمليات النشطة | مشاهدة فقط | `projects.view` |
|   |   | المشتريات ← أوامر الشراء | (كامل) | `purchase_orders.view/create/edit/receive/print` (+approve؟ Q3) |
|   |   | المشتريات ← الموردون | (كامل) | `suppliers.view/create/edit` (+`supplier_statements.view`) |
|   |   | المشتريات ← الأصناف | (كامل) | `items.view/create/edit` |
|   |   | المشتريات ← فئات الأصناف | (كامل) | نفس `items.*` (ItemCategoryPolicy تستخدم صلاحيات الأصناف) |
|   |   | المشتريات ← حجوزات المخزون | (كامل) | `operations.reserve` (StockReservationPolicy) |
| 3 | **رغدة** | المبيعات ← كل المبيعات | ما عدا الاعتمادات | دور Sales الحالي (بدون `projects.manager_approve`) |
| 4 | **مريم إبراهيم** | نفس رغدة | ما عدا الاعتمادات | نفس الدور |
| 5 | **م. عفاف فوزي** | المبيعات ← كل المبيعات | كامل بالاعتمادات | دور Sales_Manager |
|   |   | مكتب إدارة المشروعات ← الخط الزمني | مشاهدة فقط | `operations.timeline` (Sales_Manager فيه `projects.edit` ← رابط "فتح الملف" سيظهر؛ مقبول لأنها تملك تعديل المبيعات أصلاً) |
| 6 | **سامح مجدي** | المستودع ← كل المستودع | كامل | دور Warehouse_Manager |
|   |   | الإدارة المالية ← شجرة الحسابات / اليومية التحليلية / كشف حساب (دفتر أستاذ) / ميزان المراجعة | (عرض) | `accounts.view`, `journal_daybook.view`, `general_ledger.view`, `trial_balance.view` |
| 7 | **محمد دبور** | المستودع ← كل المستودع | كامل | دور Warehouse_Manager |
|   |   | المشتريات ← الأصناف + فئات الأصناف | كامل | `items.view/create/edit` |
|   |   | المشتريات ← أوامر الشراء | مشاهدة فقط | `purchase_orders.view` (الاستلام `receive` موجود أصلاً في دور المستودع) |
| 8 | **محمد عبد الرحيم** | التصنيع ← أوراق الجودة | (كامل) | `quality_sheets.view/create/fill/print` (+approve؟ Q4) |
| 9 | **م. زياد** | التصنيع ← الإنتاج والفاقد | (عرض — الشاشة للعرض فقط أصلاً) | `production_entries.view`, `scrap.view` |
|   |   | المبيعات ← العمليات النشطة | مشاهدة فقط | `projects.view` |

ملاحظة: الخانة الفارغة في عمود action فُسّرت "صلاحية كاملة على الشاشة" ما عدا الشاشات المالية لسامح (فسّرت عرض فقط — Q5).

---

## 2. القرار المعماري

**أدوار وظيفية صغيرة قابلة للتركيب (composable roles)** بدل دور لكل شخص، لأن:
- UserResource يربط المستخدم بأدوار متعددة فقط (لا توجد واجهة صلاحيات مباشرة على المستخدم).
- رغدة ومريم يشتركان، وسامح ودبور يشتركان في المستودع ← إعادة استخدام.
- الأدمن يستطيع لاحقاً تعديل كل دور من واجهة الأدوار (نمط السيدر الحالي: لا يلمس الأدوار الموجودة).

### الأدوار الجديدة (تُضاف في `getDefaultRoleDefinitions()`، تُنشأ فقط إن لم توجد)

| الدور | الصلاحيات |
|---|---|
| `Active_Operations_Viewer` | `projects.view`, `attachments.download`, `dashboard.view` |
| `Project_Attachments_Clerk` | `projects.view`, `attachments.upload`, `attachments.download`, `dashboard.view` |
| `PMO_Timeline_Viewer` | `operations.timeline`, `projects.view`, `dashboard.view` |
| `PMO_Timeline_Editor` | حسب إجابة Q1 |
| `Purchasing_Officer` | أوامر الشراء + الموردين + الأصناف/الفئات + `operations.reserve` + `inventory.view` (لازم لاختيار الأصناف) |
| `Items_Editor` | `items.view/create/edit` |
| `Purchase_Orders_Viewer` | `purchase_orders.view` |
| `Finance_Ledger_Viewer` | `accounts.view`, `journal_daybook.view`, `general_ledger.view`, `trial_balance.view` |
| `Quality_Inspector` | `quality_sheets.view/create/fill/print` (+approve حسب Q4) + `work_orders.view` (الورقة تُملأ تلقائياً من أمر التصنيع) |
| `Production_Viewer` | `production_entries.view`, `scrap.view` |

الأدوار الموجودة المعاد استخدامها: `Sales` (رغدة، مريم)، `Sales_Manager` (عفاف)، `Warehouse_Manager` (سامح، دبور).

### توزيع المستخدمين

| المستخدم | الأدوار |
|---|---|
| أسماء محمد | PMO_Timeline_Editor + Project_Attachments_Clerk |
| ياسين محمد | Purchasing_Officer + Active_Operations_Viewer |
| رغدة | Sales |
| مريم إبراهيم | Sales |
| م. عفاف فوزي | Sales_Manager + PMO_Timeline_Viewer |
| سامح مجدي | Warehouse_Manager + Finance_Ledger_Viewer |
| محمد دبور | Warehouse_Manager + Items_Editor + Purchase_Orders_Viewer |
| محمد عبد الرحيم | Quality_Inspector |
| م. زياد | Production_Viewer + Active_Operations_Viewer |

---

## 3. فجوات في الكود يجب سدّها حتى "يشوف الجزء الخاص به فقط"

يجب التحقق منها فعلياً أثناء التنفيذ (الاختبارات في المرحلة 4 تكشفها):

1. **`projects.view` يفتح كل شاشات المبيعات** (كل المشاريع، العطاءات، تحت التنفيذ، المفقودة) لا العمليات النشطة فقط. زياد وياسين وأسماء يجب أن يروا "العمليات النشطة" فقط ← صلاحية جديدة `active_operations.view` تتحكم في `ActiveProjectResource`، وباقي الموارد تبقى على `projects.view`. (+ تمنح للأدوار الحالية التي تملك `projects.view` عبر migration بيانات حتى لا يفقد أحد شيئاً على الإنتاج.)
2. **رفع المرفقات من العمليات النشطة بدون تعديل المشروع**: الرفع حالياً داخل فورم المشروع (`ProjectResource.php:233,284`) الذي يتطلب `projects.edit`. نضيف أكشن "إضافة مرفقات" في صفحة عرض العملية النشطة يظهر بـ `attachments.upload` فقط، ويحفظ المرفقات دون المرور بفورم التعديل.
3. **لوحة التحكم `StatsOverview`** بلا أي `canView` ← قد تعرض أرقاماً من أقسام أخرى. نضبط كل كارت حسب صلاحيته.
4. **الخط الزمني**: الصفحة مشتقة (للعرض)؛ "التعديل" الوحيد هو رابط فتح ملف المشروع (`ProjectsTimeline.php:157,211`) بشرط `projects.edit` ← حسب Q1.
5. مراجعة القوائم المنسدلة/البحث العام (global search) وروابط relation managers لعدم كشف موارد خارج الصلاحية.
6. الأسعار: أدوار المستودع لا تملك `inventory.view_pricing` (مقصود حسب الـPDF القديم) — يبقى كما هو ما لم يُطلب غير ذلك.

---

## 4. مراحل التنفيذ

### المرحلة 0 — تشغيل المشروع محلياً بالكامل
- MySQL محلي (`electrotech` @127.0.0.1) + `composer install` + `npm ci && npm run build`.
- `php artisan migrate` ثم أفضل: نسخة من قاعدة الإنتاج (dump عبر `ssh electrotech`) لاختبار السيدر على أدوار حقيقية معدّلة من الواجهة — بموافقتك.
- `php artisan serve --port=8001` + التأكد أن الـ suite الحالي أخضر قبل أي تعديل (`php artisan test`).

### المرحلة 1 — الصلاحيات والأدوار (السيدر)
- إضافة `active_operations.view` (+ أي صلاحية من Q1) إلى `getPermissions()`.
- إضافة الأدوار العشرة في `getDefaultRoleDefinitions()`.
- migration بيانات: منح `active_operations.view` لكل دور يملك `projects.view` حالياً.
- ترجمات أسماء الأدوار والصلاحية (ar/en) — `RolePermissionLabelsTest` يفرض ذلك.

### المرحلة 2 — سدّ الفجوات (القسم 3)
- `ActiveProjectResource` ← policy/`canViewAny` على `active_operations.view`.
- أكشن رفع المرفقات على العملية النشطة.
- ضبط `StatsOverview` لكل كارت.
- تعديل شرط الخط الزمني حسب Q1.

### المرحلة 3 — إنشاء المستخدمين
- أمر Artisan يدوي idempotent: `php artisan staff:provision` (مثل نمط استيراد المخزن — **لا يُضاف لـ deploy.sh**).
- يُنشئ/يحدّث المستخدمين التسعة بأسمائهم العربية + البريد + كلمة مرور مؤقتة (Q2) ويعمل `syncRoles`.
- يطبع جدول بيانات الدخول في النهاية لتسليمه.

### المرحلة 4 — الاختبارات
- `tests/Feature/StaffAccessMatrixTest.php`: لكل مستخدم من التسعة:
  - الشاشات المسموحة ← 200 + تظهر في القائمة الجانبية.
  - كل الشاشات الأخرى ← 403 + غير ظاهرة في القائمة.
  - الأكشنات: رغدة لا ترى "اعتماد المدير"، دبور يرى أوامر الشراء بلا إنشاء/تعديل، أسماء ترفع مرفقات ولا تعدّل المشروع، زياد/ياسين بلا أي أكشن على العمليات النشطة.
- `php artisan test` كامل أخضر.
- تجربة يدوية في المتصفح (Playwright) بالدخول بكل مستخدم والتقاط screenshot للقائمة الجانبية لكل واحد — عربي + فاتح/داكن.

### المرحلة 5 — Commit / Push / Deploy (بعد موافقتك الصريحة فقط)
- commits على `main`:
  1. `feat(rbac): job-function roles and active-operations permission`
  2. `feat(projects): add attachments to an active operation without editing it`
  3. `fix(dashboard): show each stats card only to who may see its section`
  4. `feat(users): staff provisioning command for the first nine accounts`
- `git push` ← GitHub Actions ← `deploy.sh` (يشغّل السيدر ⇒ تُنشأ الأدوار الجديدة).
- على السيرفر يدوياً: `ssh electrotech` ← `php artisan staff:provision` ← تسليم بيانات الدخول.
- smoke test على app.electrotech.findosystem.com بدخول 2–3 مستخدمين.

---

## 5. أسئلة مطلوب إجابتها قبل التنفيذ

- **Q1 — "تعديل" الخط الزمني لأسماء**: الخط الزمني محسوب تلقائياً، والتعديل الوحيد منه هو فتح ملف المشروع وتعديله. هل المقصود: (أ) تعديل ملف المشروع كاملاً (`projects.edit`) — وهذا يتعارض مع "مرفقات فقط" في العمليات النشطة؛ أم (ب) فتح الملف من الخط الزمني لإضافة الأوراق/المرفقات فقط؟ **أرشّح (ب).**
- **Q2 — بيانات الدخول**: البريد الإلكتروني لكل موظف؟ أم نولّد `firstname@electrotech.com` بكلمة مرور مؤقتة يغيّرها لاحقاً؟
- **Q3 — ياسين**: هل له اعتماد أوامر الشراء (`purchase_orders.approve`) أم الاعتماد لمكتب المشروعات فقط؟
- **Q4 — محمد عبد الرحيم**: هل له "اعتماد" ورقة الجودة أم الاعتماد النهائي يبقى لمدير المصنع؟
- **Q5 — سامح**: الشاشات المالية الأربع عرض فقط (أرشّح ذلك)، أم إضافة/تعديل حسابات في الشجرة؟
- **Q6 — المستودع**: سامح ودبور يرون أسعار المخزون أم لا (حالياً أدوار المستودع بلا أسعار)؟
- **Q7 — قاعدة البيانات المحلية**: موافق على سحب dump من الإنتاج للاختبار المحلي؟
