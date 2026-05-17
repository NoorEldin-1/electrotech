# Documentation & Mapping Guide: Electrotech MVP v1

## 📌 Introduction

This guide serves as the bridge between the **Strategic Vision** documented in the project file (Electrotech.pdf) and the **Actual Implementation** of the digital system (MVP - Minimum Viable Product) that has been built.
The goal of this document is to explain how theoretical concepts and diagrams were transformed into real, interconnected screens and workflows, using simple and direct language suitable for all departments and teams.

---

## 🏢 1. General Vision & Executive Dashboard

- **In the PDF (Pages 2-5):** Comprehensive digital transformation vision, hybrid cloud architecture, and a smart dashboard for top management displaying alerts, work orders, and inventory status.
- **In the Actual System (Software):**
    - **Location:** The main Dashboard built using advanced dashboard technology (Filament PHP).
    - **How it works:** Upon logging in, management sees vital metrics instantly. The system is built for high performance with hybrid cloud scalability, ensuring fast data access from anywhere.

## 🤝 2. Sales & CRM (Project Management)

- **In the PDF (Pages 6-7):** The workflow begins with creating and managing operations (Under Study, In Progress) and defining the client and specifications.
- **In the Actual System (Software):**
    - **Interface:** **Projects** Screen.
    - **How it works:** A fully integrated system to create a project (`ProjectResource`), where the sales team can enter the operation name, define the client, set its current status, and link it automatically to other company departments so the Technical Office can start working paperlessly.

## 📐 3. Technical Office & Production Engineering

- **In the PDF (Page 9):** The Technical Office is the "Mastermind," defining components and creating an accurate Bill of Materials (BOM) to link design to production and specify raw materials.
- **In the Actual System (Software):**
    - **Interfaces:** **Items** and **BOMs (Bill of Materials)** Screens.
    - **How it works:** The engineer defines all raw materials and products in the Items screen, then uses the BOM screen to build the exact engineering recipe for each product. This mapping ensures accurate calculation of required quantities and proactively prevents waste.

## 🛒 4. Procurement Management

- **In the PDF (Page 8):** Securing required raw materials for projects based on actual shortages and avoiding haphazard purchasing.
- **In the Actual System (Software):**
    - **Interface:** **Purchase Orders** Screen.
    - **How it works:** Allows the procurement department to create detailed purchase orders containing the exact raw materials and quantities required from suppliers, which facilitates tracking and tightens control over budgets and deadlines.

## 🏭 5. Manufacturing & Work Orders

- **In the PDF (Page 10):** Tracking production lines and the status of each work order to minimize scrap and monitor actual performance.
- **In the Actual System (Software):**
    - **Interface:** **Work Orders** Screen.
    - **How it works:** The production manager uses this screen to issue manufacturing orders and link them to the BOM and the respective project. They can update the order status (Scheduled, In Progress, Completed) to reflect actual progress on the executive dashboard.

## 📦 6. Warehouse Management System (WMS)

- **In the PDF (Page 11):** Strict management of inventory transactions (Issues, Receipts) and hiding purchase prices from storekeepers to protect data confidentiality.
- **In the Actual System (Software):**
    - **Interface:** **Inventory Transactions** Screens.
    - **How it works:** The system is programmed to record every item entering or leaving, specifying the transaction type (e.g., Purchase Receipt, Issued to Project). The system automatically calculates balances and enforces price-hiding for warehouse staff to protect financial costs.

## 🔐 7. Security, Permissions & Digital Workflows

- **In the PDF (Page 13):** Electronic approvals, digital document lifecycles, and preventing overlap of responsibilities.
- **In the Actual System (Software):**
    - **Interface:** **Users (Users and Role Management)** Screen.
    - **How it works:** A strict Role-Based Access Control (RBAC) system is applied. Each employee only sees screens designated for their department (e.g., storekeeper doesn't see accounting, etc.). No transaction can occur without being logged with the user's name and execution timestamp (Audit Log).

## 💰 8. Financial Control & Integration

- **In the PDF (Pages 12, 14):** Translating operations into financial entries to control profitability and costs.
- **In the Actual System (Software):**
    - **How it works:** In this MVP stage, the system acts as an "Automated Pricer" for projects and purchase orders. Raw material prices are linked to consumed quantities in work orders and inventory transactions, providing accurate data for finance to easily determine the actual cost of any project, paving the way for full GL accounting integration in the future.
