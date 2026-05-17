# Electrotech ERP

## 🏷️ V1 — النسخة الأولى

نظام إدارة صناعية متكامل لإدارة عمليات التصنيع من البداية للنهاية.

---

## Tech Stack

- **Backend:** Laravel, PHP
- **Frontend:** TALL Stack (Tailwind CSS, Alpine.js, Livewire)
- **Admin Panel:** Filament PHP
- **Database:** MySQL
- **Caching & Queues:** Redis
- **RBAC:** Spatie Laravel Permission
- **Audit Log:** Spatie Activity Log

---

## أقسام النظام

### 1. Sales & CRM
- إنشاء المشاريع وتتبع حالتها
- بيانات العملاء والاستشاريين
- الميزانية التقديرية والتكلفة الفعلية
- مرفقات المشروع (رسومات، مواصفات، BOQ)

### 2. Technical Office
- كتالوج المواد والخامات (Items)
- إنشاء قوائم المواد BOM مع نسب الهالك
- إصدارات متعددة للـ BOM مع نظام اعتماد

### 3. Warehouse (WMS)
- تتبع المخزون (On Hand / On Hold)
- نظام الحجز والإفراج (Hold/Release)
- سجل حركات المخزون (Stock Movements)
- إخفاء الأسعار عن أمناء المخازن

### 4. Procurement
- إنشاء أوامر الشراء (Purchase Orders)
- استلام جزئي وكلي للمواد
- تحديث المخزون تلقائياً عند الاستلام
- تتبع حالة أمر الشراء

### 5. Manufacturing
- أوامر التشغيل (Work Orders)
- صرف المواد من المخزن حسب الـ BOM
- بوابة الجودة (QA Gate) — إلزامية قبل إغلاق أمر التشغيل
- تتبع الكميات المنتجة والهالك

---

## رحلة العمل

```
مشروع جديد (Sales)
    ↓
إعداد قائمة المواد BOM (Technical Office)
    ↓
اعتماد الـ BOM
    ↓
إنشاء أوامر شراء للمواد الناقصة (Procurement)
    ↓
استلام المواد وتحديث المخزون (Warehouse)
    ↓
إنشاء أمر تشغيل (Manufacturing)
    ↓
صرف المواد من المخزن
    ↓
الإنتاج → فحص الجودة (QA Gate)
    ↓
إغلاق أمر التشغيل
```

---

## الصلاحيات

| الدور | الوصف |
|-------|-------|
| Admin | صلاحيات كاملة على النظام |
| Sales | إدارة المشاريع والعملاء |
| Technical Office | إدارة المواد وقوائم الـ BOM |
| Procurement | أوامر الشراء والاستلام |
| Factory Manager | أوامر التشغيل واعتماد الجودة |
| Warehouse Manager | إدارة المخزون بدون رؤية الأسعار |

---

## التشغيل

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
