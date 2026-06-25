# Role Permissions: Admin vs Staff

## Problem Statement

How might we define clear permission boundaries between admin and staff roles so that staff can do their day-to-day clinical work without modifying system-level configuration or performing destructive actions?

## Recommended Direction

**"Operations vs Configuration"** — Staff handles all operational work (appointments, orders, billings, products, inventory). Admin exclusively manages user accounts, audit logs, lookup/settings tables, and destructive actions.

Staff never sees "you can't do this" on pages they use daily. Config/admin pages are hidden from their nav entirely. On shared pages (billings, orders), restricted actions simply don't render for staff.

## Permission Matrix

| Area | Staff CAN | Admin Only |
|---|---|---|
| **Appointments** | Create, edit, change status, assign staff, bill service | — |
| **Orders** | Create, edit, advance status, assign lenses | Cancel confirmed orders (inventory reversal) |
| **Billings** | View, record payment, add service | Void billing, apply/change discount |
| **Products** | Create, edit, manage variants, adjust stock | Delete/restore products |
| **Patients** | Create, edit, bill service | Delete patients |
| **Prescriptions** | Create, edit | Delete |
| **Inventory** | Adjust stock (restock, manual) | — |
| **Conversations** | Read, reply | — |
| **Feedback** | View, reply | — |
| **Users** | ❌ Hidden entirely | Full CRUD |
| **Audit Logs** | ❌ Hidden entirely | Read-only access |
| **Settings** (categories, brands, lens types, visit reasons, services) | ❌ Hidden entirely | Full CRUD |

## Key Assumptions to Validate

- [ ] 1 admin (owner) + 3-5 staff is the target ratio — no need for granular per-user permissions
- [ ] Staff will rarely need to void or apply discounts — if they do, admin is available
- [ ] Hiding Settings nav from staff won't cause confusion (staff don't need to add new lens types or visit reasons)

## MVP Scope

- `User::isAdmin()` helper method
- `canViewAny()` / `canCreate()` / `canDelete()` on resources that need full hiding (Users, Audit Logs, Settings group)
- `->visible(fn () => auth()->user()->isAdmin())` on restricted actions (void billing, apply discount, cancel confirmed order, delete/restore)
- No policies or Spatie permissions package — just simple role checks on the User model

## Not Doing (and Why)

- **Spatie laravel-permission** — overkill for 3 fixed roles with no dynamic assignment
- **Per-resource policies** — too much boilerplate for a simple admin/staff split
- **"Request Admin Action" workflow** — over-engineered for a small clinic
- **Restricting read access** — staff should see all data to do their job; restriction is on mutation only
- **Restricting payments** — staff needs to record payments at the counter without waiting for admin

## Resolved Questions

- ✅ Staff CAN cancel orders in `requested` status (no inventory impact). Only cancelling from `confirmed` onward is admin-only (triggers inventory reversal + billing void).
