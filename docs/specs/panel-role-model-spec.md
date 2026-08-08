# Spec: Panel Role Model

Status: Approved — implementation not started
Phase: Tasks approved — implementation not started
Approved: 2026-08-08

## Objective

Replace the panel's two roles plus `is_optometrist` capability flag with three
explicit, fixed panel roles:

- `admin` — administrative authority;
- `optometrist` — clinical authority; and
- `staff` — non-clinical operational authority.

The mobile-only `patient` role remains part of the system role catalog but is
not a panel role. The change must make administrative and clinical authority
independent: an administrator is not allowed to perform clinical work merely
because they are an administrator.

A user may hold both `admin` and `optometrist` when the same person genuinely
performs both duties, such as a clinic owner who also practices as an
optometrist. This is a normal combination of two independently authorized
roles, not an owner-specific exception.

Success means every protected panel page and workflow uses the same explicit
role contract, owner-optometrists retain one auditable identity, and the
legacy `users.role_id` plus `users.is_optometrist` representation is removed
without changing patient API behavior or losing historical attribution.

## Approved Decisions and Assumptions

1. The three **panel roles** are `admin`, `optometrist`, and `staff`.
2. `patient` remains an exclusive, mobile-only system role.
3. `admin` alone has no clinical authority.
4. `optometrist` grants clinical authority and includes ordinary clinic
   operations needed to complete a patient visit.
5. `staff` grants ordinary non-clinical clinic operations.
6. A dual-duty user may hold `admin` and `optometrist`; the system will not
   hardcode a singular owner or limit this combination to one account.
7. Roles and their permission meanings are fixed in code. There is no dynamic
   permission builder or third-party role/permission package.
8. Professional credential capture, including PRC license details and
   verification, is a separate future feature.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Laravel Sanctum 4
- Pest 4 and PHPUnit 12
- MySQL through Laravel Sail
- No new dependencies

## Commands

```text
Start services: vendor/bin/sail up -d
Inspect migration command: vendor/bin/sail artisan make:migration --help
Create role pivot migration: vendor/bin/sail artisan make:migration migrate_users_to_multi_role_assignments --no-interaction
Run migrations: vendor/bin/sail artisan migrate --no-interaction
Focused tests: vendor/bin/sail artisan test --compact tests/Feature/RoleCatalogTest.php tests/Feature/Filament/UserResourceTest.php tests/Feature/Security/AccountLifecycleTest.php tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/PrescriptionResourceTest.php tests/Feature/Filament/Availability
Format PHP: vendor/bin/sail bin pint --dirty --format agent
Full tests: vendor/bin/sail artisan test --compact
Frontend build when required: vendor/bin/sail npm run build
```

## Project Structure

```text
app/Models/User.php                         -> Role relationships and role helpers
app/Models/Role.php                         -> Fixed role names and inverse relationship
app/Policies/                               -> Resource and workflow authorization
app/Actions/                                -> Server-side clinical and role invariants
app/Filament/Resources/Users/               -> Team account and role assignment UI
app/Filament/Clusters/Availability/          -> Clinic/provider schedule authorization
database/migrations/                        -> Forward role-model migration
database/factories/                         -> Explicit admin/optometrist/staff/patient states
database/seeders/                           -> Fixed roles and representative demo accounts
tests/Feature/                              -> Role, policy, workflow, UI, and migration coverage
docs/BACKEND_CONTEXT.md                     -> Canonical implemented-system context
docs/specs/panel-role-model-spec.md         -> This specification
```

## Role Contract

### Fixed Role Catalog

The complete system role catalog is:

| Role | Account surface | Meaning |
|---|---|---|
| `admin` | Filament panel | Administrative and privileged authority; never implicitly clinical |
| `optometrist` | Filament panel | Clinical authority plus shared clinic operations |
| `staff` | Filament panel | Shared non-clinical clinic operations |
| `patient` | Mobile API | Patient account behavior; never panel access |

The application continues to use fixed role records rather than user-defined
roles or permissions.

### Valid Role Sets

Every user must have one of these role sets:

| Assigned roles | Valid | Intended user |
|---|---:|---|
| `admin` | Yes | Non-clinical administrator |
| `optometrist` | Yes | Practicing optometrist |
| `staff` | Yes | Receptionist, cashier, or other non-clinical operator |
| `admin` + `optometrist` | Yes | Owner or other person performing both duties |
| `patient` | Yes | Mobile or walk-in patient account |
| `admin` + `staff` | No | Redundant; admin already receives shared operations |
| `optometrist` + `staff` | No | Redundant; optometrist already receives shared operations |
| Any panel role + `patient` | No | Crosses the panel/mobile trust boundary |
| No roles | No | Would leave account behavior undefined |

The application must validate these sets at every role-assignment boundary.
The database pivot's uniqueness constraint prevents duplicate assignments;
application-level validation enforces valid combinations.

### Permission Matrix

`Admin + Optometrist` receives the union of the `Admin` and `Optometrist`
columns. A plain administrator receives no action from the Optometrist-only
column.

| Area | Staff | Optometrist | Admin |
|---|---|---|---|
| Panel login and own profile/MFA | Yes | Yes | Yes |
| Appointments: view, create, check in, reschedule, cancel, no-show | Yes | Yes | Yes |
| Appointments: bulk cancellation | No | No | Yes |
| Encounters: view | Yes | Yes | Yes |
| Encounters: start and complete | No | Yes | No |
| Prescriptions: view | Yes | Yes | Yes |
| Prescriptions: create, finalize, and amend | No | Yes | No |
| Quotations: create, revise, present, decide, confirm sale | Yes | Yes | Yes |
| Optical Orders: create and advance operational workflow | Yes | Yes | Yes |
| Frame Reservations: operational workflow | Yes | Yes | Yes |
| Billing: view and record payment | Yes | Yes | Yes |
| Billing: void/correct payment | No | No | Yes |
| Patients: create and edit | Yes | Yes | Yes |
| Patients/products: archive and restore | No | No | Yes |
| Catalog: create, edit, and manage variants | Yes | Yes | Yes |
| Team accounts and role assignments | No | No | Yes |
| Audit logs and privacy administration | No | No | Yes |
| Clinic-wide availability configuration | No | No | Yes |
| Own provider hours and own provider absences | No | Yes | No |
| Any provider's hours/absence overrides | No | No | Yes |

Read access to clinical records does not grant clinical authorship. Workflow
actions that create or finalize clinical records must independently authorize
the actor as an optometrist on the server, even if the UI already hides the
action.

### Panel Access

An active user may access the Filament admin panel when they have at least one
of `admin`, `optometrist`, or `staff`. A user with only `patient`, no roles, or
an inactive account cannot access the panel.

Role checks must be centralized behind descriptive `User` methods and scopes,
including equivalents of:

- `hasRole(string $role): bool`;
- `isAdmin(): bool`;
- `isOptometrist(): bool`;
- `isStaff(): bool`;
- `hasPanelRole(): bool`; and
- `scopeOptometrists(Builder $query): Builder`.

Policies, actions, Filament resources, and queries must not reproduce raw role
arrays or read a removed boolean flag.

## Data Model and Migration

### Target Schema

- Keep the `roles` table with unique role names.
- Add a `role_user` pivot table containing `role_id` and `user_id` foreign
  keys, a unique composite constraint, and timestamps only if existing project
  conventions require them.
- Change `User::role()` from `BelongsTo` to `User::roles()` as
  `BelongsToMany`.
- Change `Role::users()` from `HasMany` to `BelongsToMany`.
- Remove `users.role_id` after all assignments are copied successfully.
- Remove `users.is_optometrist` after all optometrist assignments are copied
  successfully.

### Existing-Account Mapping

The forward migration must preserve users using this deterministic map:

| Existing state | New role set |
|---|---|
| `patient` | `patient` |
| `staff`, `is_optometrist = false` | `staff` |
| `staff`, `is_optometrist = true` | `optometrist` |
| `admin`, `is_optometrist = false` | `admin` |
| `admin`, `is_optometrist = true` | `admin` + `optometrist` |

The migration must fail clearly rather than guess if it encounters an unknown
role or an impossible legacy state. It must verify that every user received a
valid target role set before removing legacy columns.

The reverse migration, if implemented, maps `admin + optometrist` back to
`role_id = admin` plus `is_optometrist = true`, and maps a sole optometrist
back to `role_id = staff` plus `is_optometrist = true`.

All historical foreign keys that identify users—such as encounter
optometrists, prescription authors, appointment providers, audit actors, and
provider hours—remain unchanged because user IDs do not change.

## Account Management

- Rename the Filament navigation/resource label from **Staff Accounts** to
  **Team Accounts**, because it manages all three panel roles.
- Only administrators may list, create, edit, activate, or deactivate team
  accounts and change role assignments.
- The role field must expose only the valid role sets. A generic multi-select
  must not permit invalid combinations.
- An administrator cannot alter their own role assignments.
- The last active administrator cannot lose `admin` and cannot be
  deactivated.
- Role additions and removals must produce an audit event recording the actor,
  target user, old role set, and new role set.
- Deactivation removes panel access and provider eligibility without deleting
  assignments or historical records.
- Removing `optometrist` prevents new clinical/provider actions but does not
  rewrite historical clinical attribution.
- Password ownership, forced password change, profile editing, MFA, and login
  audit behavior remain unchanged for all panel roles.

## Demo and Factory Contract

- `UserFactory::admin()` assigns only `admin`.
- `UserFactory::optometrist()` assigns only `optometrist` and retains useful
  provider-hour setup.
- `UserFactory::staff()` assigns only `staff`.
- `UserFactory::patient()` assigns only `patient`.
- Add an explicit factory state for the valid `admin + optometrist`
  combination.
- Demo data must contain a plain staff member, a plain optometrist, and an
  owner represented by `admin + optometrist`, in addition to patient data.
- Seeder execution remains idempotent and never creates unsupported role
  combinations.

## Code Style

Follow existing Laravel and Filament conventions: explicit parameter and
return types, descriptive authorization methods, Eloquent relationships,
policies for resource authorization, and action-level guards for workflow
invariants.

Centralize fixed names and relationship checks rather than repeating string
arrays:

```php
class Role extends Model
{
    public const Admin = 'admin';

    public const Optometrist = 'optometrist';

    public const Staff = 'staff';

    public const Patient = 'patient';
}

public function isOptometrist(): bool
{
    return $this->hasRole(Role::Optometrist);
}
```

Authorization must be checked again inside sensitive actions:

```php
if (! $actor->isOptometrist()) {
    throw ValidationException::withMessages([
        'actor' => ['Only an optometrist can complete this clinical workflow.'],
    ]);
}
```

## Testing Strategy

- Use Pest feature tests with `RefreshDatabase`, factories, and existing
  Filament/Livewire test conventions.
- Add migration coverage for every legacy-to-target mapping, unknown-role
  failure, complete assignment verification, and rollback mapping if the
  migration is reversible.
- Update role catalog and relationship tests for four fixed system roles and
  many-to-many user assignments.
- Test every valid role set and reject every invalid role set through the
  account-management boundary.
- Add a matrix test proving staff, optometrist, admin, and dual-role behavior
  for representative common, clinical, and administrative actions.
- Test that plain administrators cannot start/complete encounters or
  create/finalize/amend prescriptions.
- Test that `admin + optometrist` can perform both administrative and clinical
  workflows under one user ID.
- Test that optometrist selectors and scopes include active optometrists,
  including dual-role users, and exclude inactive users and all other roles.
- Test panel access for each role set and inactive state.
- Test Team Accounts visibility, role-set validation, self-role protection,
  last-active-admin protection, activation/deactivation, and role-change audit
  metadata.
- Test clinic-wide versus own-provider availability boundaries.
- Update affected factory, seeder, navigation, encounter, prescription,
  appointment, and account-lifecycle tests.
- Run the focused suite first, Pint after PHP changes, and then the full Pest
  suite.
- This specification phase changes documentation only, so application tests
  are not required until implementation begins.

## Boundaries

### Always

- Authorize clinical actions by the explicit `optometrist` role.
- Authorize administrative actions by the explicit `admin` role.
- Enforce authorization on the server, not only through hidden UI controls.
- Preserve one user identity and historical attribution for dual-duty users.
- Preserve patient authentication, linking, contacts, tokens, and mobile API
  behavior.
- Audit every role-set change.
- Keep the last-active-admin and account-deactivation safeguards.
- Update `docs/BACKEND_CONTEXT.md` only when implementation changes the
  runtime contract.
- Preserve unrelated worktree changes.

### Ask First

- Adding PRC license or other professional credential fields.
- Adding dynamic permissions, user-defined roles, or a role/permission
  dependency.
- Allowing any role combination beyond `admin + optometrist`.
- Changing the patient API or allowing patient accounts into Filament.
- Creating an owner-specific role, flag, or one-owner database constraint.
- Changing the existing operational permissions beyond the matrix in this
  specification.

### Never

- Treat `admin` as implicit clinical authority.
- Grant clinical authority through a boolean capability after migration.
- Combine `patient` with a panel role.
- Hardcode a particular email, user ID, or a single account as the owner.
- Create separate accounts merely because one person has both approved roles.
- Rely exclusively on Filament visibility to protect sensitive actions.
- Delete or rewrite historical user attribution when roles change.
- Delete tests to accommodate the new role model.

## Success Criteria

- The role catalog contains exactly `admin`, `optometrist`, `staff`, and
  `patient`.
- The three panel roles are clearly displayed and documented as distinct
  responsibilities.
- Users support the approved valid role sets, including
  `admin + optometrist`, through a many-to-many relationship.
- `users.role_id` and `users.is_optometrist` are removed after verified data
  migration.
- Existing admin-optometrists migrate to `admin + optometrist`; existing
  staff-optometrists migrate to `optometrist`; all other accounts retain their
  intended access.
- A plain administrator is denied all optometrist-only clinical actions.
- A dual-role administrator-optometrist can perform both categories of work
  with one account and one audit identity.
- Staff cannot perform clinical or administrative-only actions.
- Patient-only and inactive users cannot access Filament.
- Team Accounts permits only valid role sets and preserves self-role and
  last-active-admin safeguards.
- Role changes are auditable and never alter historical clinical attribution.
- Patient mobile API behavior and contracts remain unchanged.
- Focused tests and the full Pest suite pass.
- Modified PHP files pass Pint formatting.
- `docs/BACKEND_CONTEXT.md` matches the implemented role contract when the
  feature ships.

## Open Questions

None are blocking. Approval of this specification confirms the permission
matrix, the **Team Accounts** label, the deterministic legacy mapping, and the
decision to defer professional credential fields.
