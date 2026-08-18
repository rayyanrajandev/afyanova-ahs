# Test Users (local dev environment)

Login: `http://127.0.0.1:8000/login`
Facility (all accounts below): **Dar Main Hospital**

| Email | Password | Role | Notes |
| --- | --- | --- | --- |
| `admin@local.test` | `DevPass!2026` | `PLATFORM.SUPER.ADMIN` | Bypasses every permission check (`isPlatformSuperAdmin()` short-circuits `hasPermissionTo()`). Has access to all hospital workspaces. |
| `receptionist@local.test` | `DevPass!2026` | `ADMIN.REGISTRATION` ("Registration Clerk") | Role-scoped account for Reception workspace (`patients.read`, `appointment.check-in`, etc.). |
| `nurse@local.test` | `DevPass!2026` | `CLINICAL.NURSE` ("Nurse") | Role-scoped account for Nursing workspace (`nursing.access`, `patient.vitals.record`, etc.). |
| `clinician@local.test` | `DevPass!2026` | `CLINICAL.PHYSICIAN` ("Medical Officer / Doctor") | Role-scoped account for Clinician workspace (`clinician.access`, `medication.prescribe`, etc.). |
| `lab@local.test` | `DevPass!2026` | `LAB.STAFF` ("Lab Technician") | Role-scoped account for Laboratory workspace (`laboratory.access`, `lab.result.enter`, etc.). |
| `radiology@local.test` | `DevPass!2026` | `RADIOLOGY.STAFF` ("Radiographer") | Role-scoped account for Radiology workspace (`radiology.access`, `imaging.perform`, etc.). |
| `radiology.supervisor@local.test` | `DevPass!2026` | `RADIOLOGY.SUPERVISOR` ("Radiologist / Supervisor") | Role-scoped account for Radiology authorization & verification (`radiology.access`, `imaging.result.verify`, `radiology.orders.audit-logs.view`). |
| `pharmacy@local.test` | `DevPass!2026` | `PHARMACY.STAFF` ("Dispenser / Pharmacist") | Role-scoped account for Pharmacy workspace (`pharmacy.access`, `medication.dispense`, etc.). |
| `cashier@local.test` | `DevPass!2026` | `FINANCE.CASHIER` ("Cashier") | Role-scoped account for Cashier / Billing workspace (`cashier.access`, `billing.payments.record`, etc.). |

Password drift note: this dev DB's user passwords occasionally get reset by a
migration/seed re-run. If a login stops working, reset it directly:

```bash
php artisan tinker --execute="
\$u = \App\Models\User::where('email','admin@local.test')->first();
\$u->password = bcrypt('DevPass!2026');
\$u->save();
"
```

## Adding another role-scoped test user

Follow this pattern (used to create `receptionist@local.test`) — swap the
role code and email. See `app/Support/Auth/RoleCodes.php` for the full list
of role codes (e.g. `CLINICAL.PHYSICIAN`, `CLINICAL.NURSE`, `LAB.STAFF`,
`PHARMACY.STAFF`, `FINANCE.CASHIER`).

```bash
php artisan tinker --execute="
\$facilityId = '019fe7cb-aca2-7158-9fb4-3be8a0abf8f2'; // Dar Main Hospital
\$tenantId = '019fe7cb-ac9b-72e4-a4f7-b76e02c7fbfa';
\$role = \App\Modules\Platform\Infrastructure\Models\RoleModel::where('code','ADMIN.REGISTRATION')->firstOrFail();

\$user = \App\Models\User::create([
  'tenant_id' => \$tenantId,
  'name' => 'Receptionist Test',
  'email' => 'receptionist@local.test',
  'password' => bcrypt('DevPass!2026'),
  'status' => 'active',
  'is_platform_admin' => false,
]);
\$user->email_verified_at = now();
\$user->save();
\$user->roles()->attach(\$role->id);

\DB::table('facility_user')->insert([
  'facility_id' => \$facilityId,
  'user_id' => \$user->id,
  'role' => 'registration_clerk',
  'is_primary' => 1,
  'is_active' => 1,
  'created_at' => now(),
  'updated_at' => now(),
]);
"
```

The `facility_user` row is required — without it, `platform.scope.facility.id`
(the Inertia-shared prop every facility-scoped workspace, including
Reception's real-time sync, reads) resolves to `null` and the workspace
either shows nothing or breaks in ways that look unrelated to the missing
row.

## Not a usable test account

`cristopher.armstrong@example.net` (user id 2) — a factory-generated row
with no role and no facility assignment. Leftover from generic dev seeding,
not set up for testing any workspace.
