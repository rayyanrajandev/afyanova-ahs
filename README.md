# Afyanova AHS (Advanced Hospital System)

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.5-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Inertia.js](https://img.shields.io/badge/Inertia.js-3.x-9553E9?style=flat-square&logo=inertia&logoColor=white)](https://inertiajs.com/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4.x-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=flat-square&logo=typescript&logoColor=white)](https://www.typescriptlang.org/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16+-4169E1?style=flat-square&logo=postgresql&logoColor=white)](https://www.postgresql.org/)

**Afyanova AHS** is a modern, modular hospital management and clinical information system engineered for healthcare facilities and private hospitals. Built with a domain-driven modular monolith architecture, Afyanova provides real-time clinical workflows, multi-tenant isolation, strict data governance, and high-performance user workspaces.

---

## 🌟 Key Workspaces & Modules

- **Reception & Registration**
  - Instant patient search, demographic capture, MRN generation, and queue triage dispatch.
  - Multi-language support (English & Swahili / Kiswahili).
- **Nursing & Triage**
  - Fast vital sign recording (BP, Pulse, SpO2, Temp, BMI calculation, Pain scores).
  - Patient flow tracking and seamless handover between departments.
- **Clinician & Consultation**
  - Structured encounter documentation (SOAP notes, diagnoses, allergies, problem lists).
  - Direct clinical ordering (lab tests, radiology, prescriptions, and procedures).
- **Laboratory & Radiology**
  - Service request fulfillment, specimen tracking, and diagnostic reporting workflows.
- **Pharmacy & Inventory**
  - Prescription processing, dosage calculators, stock adjustments, and dispensary queues.
- **Billing & Claims**
  - Integrated charge capture, invoice management, cash/M-Pesa reconciliation, and insurance/NHIF processing.
- **Patient Flow Management**
  - Live facility-wide journey tracking from arrival to discharge with WebSocket real-time updates.

---

## 🏗️ Architecture & Engineering Standards

The codebase follows a clean, modular architecture enforcing strict dependency directions:

```
app/Modules/
  ├── Admission/
  ├── Appointment/
  ├── Authentication/
  ├── Billing/
  ├── ClaimsInsurance/
  ├── ClinicalProcedure/
  ├── Department/
  ├── EmergencyTriage/
  ├── Encounter/
  ├── InpatientWard/
  ├── InventoryProcurement/
  ├── Laboratory/
  ├── MedicalRecord/
  ├── Notifications/
  ├── Patient/
  ├── PatientFlow/
  ├── PatientVitals/
  ├── Pharmacy/
  ├── Platform/
  ├── Pos/
  ├── Radiology/
  ├── Reception/
  ├── Staff/
  └── TheatreProcedure/
```

- **Domain-Driven Design (DDD)**: Each module contains distinct Presentation, Application, Domain, and Infrastructure layers.
- **Multi-Tenant Data Isolation**: Robust tenant scoping backed by PostgreSQL Row-Level Security (RLS) policies.
- **4-Tier Engineering Codex**: Principles before pixels, design tokens before components, platform primitives before domain workspaces.

---

## 💻 Technology Stack

### Backend
- **Framework**: Laravel 13 (PHP 8.3+)
- **Database**: PostgreSQL 16+ (with RLS support)
- **Real-Time**: Laravel Echo & WebSockets (Pusher / Reverb)
- **Security**: Sanctum, Multi-Factor Authentication (2FA), Role-Based Access Control (RBAC)

### Frontend
- **Framework**: Vue 3 (Composition API & `<script setup>`) with TypeScript
- **Integration**: Inertia.js v3
- **Styling**: Tailwind CSS v4 + Design Tokens
- **UI Components**: Reka UI (Radix Vue primitives), Lucide Icons, TipTap rich text
- **State & Data**: Pinia, TanStack Vue Query, TanStack Virtual

### Testing & QA
- **Unit & Component Testing**: Vitest, `@vue/test-utils`, Testing Library
- **End-to-End Testing**: Playwright (smoke, security, registration, and clinical suites)
- **Component Workbench**: Histoire
- **Linting & Formatting**: ESLint 9, Prettier, TypeScript strict checking

---

## 🚀 Getting Started

### Prerequisites
- **PHP** >= 8.3 (with `pdo_pgsql`, `mbstring`, `openssl`, `bcmath`, `curl` extensions)
- **Composer** >= 2.7
- **Node.js** >= 20.x and **npm** >= 10.x
- **PostgreSQL** >= 15

### Local Installation

1. **Clone the repository**:
   ```bash
   git clone git@github.com:rayyanrajandev/afyanova-ahs.git
   cd afyanova-ahs
   ```

2. **Install backend dependencies**:
   ```bash
   composer install
   ```

3. **Install frontend dependencies**:
   ```bash
   npm install
   ```

4. **Configure environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   *Update your database credentials in `.env` (`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).*

5. **Run migrations and seeders**:
   ```bash
   php artisan migrate --seed
   ```

6. **Start the development servers**:
   ```bash
   # In terminal 1 (Vite frontend):
   npm run dev

   # In terminal 2 (Laravel backend):
   php artisan serve
   ```

7. Open [http://localhost:8000](http://localhost:8000) in your browser.

---

## 🧪 Testing & Code Quality

```bash
# Run unit & component tests
npm run test:unit

# Run backend PHP tests
php artisan test

# Run Playwright E2E tests
npm run test:e2e

# Run Histoire component showcase
npm run story:dev

# Run linting and style checks
npm run lint
npm run format:check
```

---

## 🔒 Security & Compliance

This platform handles clinical and sensitive personal health data. Follow these operational guidelines:
- Maintain strict branch protection on `main`.
- Keep `.env` files protected and never commit secrets or API tokens.
- Ensure `APP_DEBUG=false` in all staging and production environments.
- Enforce HTTPS and secure session cookies across all deployment environments.
- See [`documents/AfyaNova_Security_Architecture_2027.md`](./documents/AfyaNova_Security_Architecture_2027.md) for full security controls.

---

## 📚 Documentation & Architecture Guides

- **Engineering Codex**: [`documents/codex/README.md`](./documents/codex/README.md)
- **UX & Design Foundations**: [`documents/codex/00-foundations/01-philosophy-and-2027-ux-principles.md`](./documents/codex/00-foundations/01-philosophy-and-2027-ux-principles.md)
- **Architecture Decision Records (ADRs)**: [`documents/codex/adr/`](./documents/codex/adr/)
- **Multi-Tenant Isolation Architecture**: [`documents/Afyanova_Multi_Tenant_Isolation_2027_Plan.md`](./documents/Afyanova_Multi_Tenant_Isolation_2027_Plan.md)

---

## 📄 License

Proprietary — All rights reserved.
