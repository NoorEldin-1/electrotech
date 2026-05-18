# ElectroTech Orwa ERP

## 🏷️ V1 — First Release

An integrated industrial management system for end-to-end manufacturing operations.

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

## System Modules

### 1. Sales & CRM
- Create and track project status
- Client and consultant information
- Estimated budget and actual cost
- Project attachments (drawings, specs, BOQ)

### 2. Technical Office
- Items and raw materials catalog
- Bill of Materials (BOM) with waste percentages
- Multi-version BOMs with approval workflow

### 3. Warehouse (WMS)
- Inventory tracking (On Hand / On Hold)
- Hold/Release reservation system
- Stock movement audit trail
- Pricing hidden from warehouse keepers

### 4. Procurement
- Purchase Order creation and management
- Partial and full item receiving
- Automatic inventory update on receiving
- PO status tracking

### 5. Manufacturing
- Work Order management
- Material issuance from warehouse based on BOM
- QA Gate — mandatory approval before closing a Work Order
- Produced quantity and waste tracking

---

## Workflow

```
New Project (Sales)
    ↓
Prepare Bill of Materials (Technical Office)
    ↓
BOM Approval
    ↓
Create Purchase Orders for missing materials (Procurement)
    ↓
Receive materials and update inventory (Warehouse)
    ↓
Create Work Order (Manufacturing)
    ↓
Issue materials from warehouse
    ↓
Production → Quality Inspection (QA Gate)
    ↓
Close Work Order
```

---

## Roles

| Role | Description |
|------|-------------|
| Admin | Full system access |
| Sales | Project and client management |
| Technical Office | Items and BOM management |
| Procurement | Purchase orders and receiving |
| Factory Manager | Work orders and QA approval |
| Warehouse Manager | Inventory management without pricing visibility |

---

## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
