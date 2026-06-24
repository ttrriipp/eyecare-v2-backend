# Spec: Patients Resource

## Objective

Add a dedicated "Patients" Filament resource for clinic staff to view and manage patient records. Patients are users with role `customer` — displayed as "Patient" in the UI. The resource provides a single place to look up a patient's full history: prescriptions, appointments, and orders.

**Not changing:** DB role name stays `customer`. Android app unaffected. User Management stays admin-only for staff/admin accounts.

## Success Criteria

- [ ] "Patients" nav item visible in the sidebar (ungrouped, after Prescriptions)
- [ ] List page shows: Name, Phone, Email, Last Visit date, Total Orders count
- [ ] Searchable by name and phone
- [ ] View/Edit page shows patient info + relation managers for Prescriptions, Appointments, Orders
- [ ] Staff and admin can access; customers cannot
- [ ] Existing tests still pass; new resource has basic list/view tests
- [ ] Label "Patient" used throughout (not "Customer") in the admin UI for this resource

## Tasks

### Task 1: Create PatientResource (list page)

**Description:** Create a new Filament resource scoped to `User` model with `role = customer`. List page with Name, Phone, Email, Last Visit, Total Orders columns. Searchable, no create action (patients register via app or walk-in quick-create on orders/appointments).

**Acceptance:**
- [ ] `/admin/patients` renders a table of customer-role users
- [ ] Columns: Name, Phone, Email (placeholder if null), Last Visit, Total Orders
- [ ] Searchable by name and phone
- [ ] No "New patient" button (walk-in created elsewhere)
- [ ] Navigation labeled "Patients", sorted after Prescriptions

**Files:**
- `app/Filament/Resources/Patients/PatientResource.php`
- `app/Filament/Resources/Patients/Pages/ListPatients.php`
- `app/Filament/Resources/Patients/Tables/PatientsTable.php`

### Task 2: Patient view/edit page with info section

**Description:** View/edit page showing patient info: name, phone, email, created date. Minimal editable fields (name, phone, email). No role or password fields.

**Acceptance:**
- [ ] `/admin/patients/{id}/edit` renders patient info form
- [ ] Fields: Name (required), Phone, Email (nullable)
- [ ] No password, no role selector
- [ ] Section header: "Patient Information"

**Files:**
- `app/Filament/Resources/Patients/Pages/EditPatient.php`
- `app/Filament/Resources/Patients/Schemas/PatientForm.php`

### Task 3: Relation managers — Prescriptions, Appointments, Orders

**Description:** Add three read-only relation managers on the patient edit page showing linked records. Each shows key columns and links to the full record.

**Acceptance:**
- [ ] Prescriptions table: prescribed_at, OD sphere, OS sphere, expires_at
- [ ] Appointments table: scheduled_at, visit reason, status (badge)
- [ ] Orders table: order_number, status (badge), total_amount, created_at
- [ ] All read-only (no create/edit actions on the relation managers)
- [ ] Click row navigates to the full record's edit page

**Files:**
- `app/Filament/Resources/Patients/RelationManagers/PrescriptionsRelationManager.php`
- `app/Filament/Resources/Patients/RelationManagers/AppointmentsRelationManager.php`
- `app/Filament/Resources/Patients/RelationManagers/OrdersRelationManager.php`

### Task 4: Tests + docs

**Acceptance:**
- [ ] Test: staff can list patients
- [ ] Test: staff can view a patient
- [ ] Test: patient list only shows customer-role users
- [ ] Full test suite passes
- [ ] `BACKEND_CONTEXT.md` updated

## Boundaries

- **Always:** Scope queries to `role = customer` only
- **Ask first:** Adding new columns to users table
- **Never:** Expose password hash, change role assignment, affect Android API

## Open Questions

None — scope is clear.
