# Spec: Filament Notifications & Global Search

## Objective

Add two standard Filament panel features that make the admin panel feel complete and operational:

1. **Database Notifications** — the bell icon in the topbar shows real-time alerts when key events occur (new order, appointment booked, low stock). Staff/admin see relevant notifications without needing to poll individual resource pages.
2. **Global Search** — the search bar in the topbar lets staff find patients, orders, appointments, and products instantly by typing, without navigating to each resource list.

**Users:** Admin and Staff using the Filament panel.
**Success:** Bell icon shows unread count; new orders/appointments/low-stock trigger notifications; typing in the search bar finds records across resources by key attributes.

---

## Tech Stack

- PHP 8.5 / Laravel 13 / Filament 5
- Pest 4 for tests
- MySQL via Sail
- Laravel's `notifications` table (Filament database notifications)
- No new dependencies (Filament ships with notification + global search built-in)

## Commands

```
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=TestName
Lint:    vendor/bin/sail bin pint --dirty --format agent
Dev:     vendor/bin/sail up -d
```

## Project Structure

```
app/Providers/Filament/AdminPanelProvider.php  → enable databaseNotifications()
app/Filament/Resources/*/                       → add getGloballySearchableAttributes()
app/Actions/                                    → fire notifications from existing actions
database/migrations/                            → notifications table (if not exists)
tests/Feature/Filament/                         → notification + search tests
```

## Code Style

Follow existing conventions. Notifications fired from action classes (same pattern as SMS + audit log creation):

```php
// Inside an action class, after the main operation
use Filament\Notifications\Notification;
use App\Models\User;

$staff = User::query()->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))->get();

Notification::make()
    ->title('New order placed')
    ->body("Order {$order->order_number} by {$order->customer->name}")
    ->info()
    ->sendToDatabase($staff);
```

## Testing Strategy

- Feature tests verify notifications are created in the database after actions fire
- Feature tests verify global search returns expected records
- Use `RefreshDatabase` + factories
- Run filtered: `--filter=Notification` and `--filter=GlobalSearch`

## Boundaries

- **Always:** Run tests, format with Pint, update BACKEND_CONTEXT.md
- **Ask first:** New dependencies, schema changes beyond the notifications table
- **Never:** Change API response shapes, add broadcast/websockets (out of scope)

---

## Feature 1: Database Notifications

### Setup

- Run `php artisan make:notifications-table` if migration doesn't exist (creates the `notifications` table)
- Add `->databaseNotifications()` to the panel provider
- Ensure User model uses `Notifiable` trait (likely already does)

### Notification Events

| Trigger | Notification Title | Recipients | Color |
|---------|-------------------|------------|-------|
| Customer books appointment (API `POST /appointments`) | "New appointment booked" | All staff + admin | info |
| Customer places order (API `POST /orders`) | "New order placed" | All staff + admin | info |
| Order confirmed (stock deducted) | "Order confirmed" | All staff + admin | success |
| Low stock threshold hit (after inventory deduction) | "Low stock alert" | All staff + admin | danger |
| Customer cancels appointment | "Appointment cancelled by customer" | All staff + admin | warning |
| Customer cancels order | "Order cancelled by customer" | All staff + admin | warning |

### Acceptance Criteria

- [ ] Bell icon visible in the Filament topbar with unread count badge
- [ ] Clicking the bell opens a modal listing notifications (newest first)
- [ ] Each notification shows title, body, time ago, and appropriate color
- [ ] "Mark all as read" clears the unread count
- [ ] Notifications created automatically when the 6 events above fire
- [ ] Notification body includes relevant context (order number, customer name, variant name for low stock)
- [ ] Only staff/admin receive notifications (not customers)

---

## Feature 2: Global Search

### Resources to Enable

| Resource | Searchable Attributes | Result Title | Result Details |
|----------|----------------------|--------------|----------------|
| Patients | `name`, `phone`, `email` | Patient name | Phone |
| Orders | `order_number`, `customer.name` | Order number | Customer name + status |
| Appointments | `customer.name`, `customer.phone` | Customer name | Visit reason + date |
| Products | `name`, `product_variants.sku` | Product name | Brand + type |

### Acceptance Criteria

- [ ] Search bar visible in the Filament topbar
- [ ] Typing "Juan" returns matching patients, appointments for Juan, orders by Juan
- [ ] Typing "ORD-2026" returns matching orders
- [ ] Typing a SKU returns the product
- [ ] Results link to the correct edit/view page
- [ ] Resources without `getGloballySearchableAttributes()` are excluded
- [ ] Search is fast (no full-table scan — uses existing indexes)

---

## Implementation Plan

### Order

```
1. Notifications table migration (if needed)    → foundation
2. Enable databaseNotifications() in panel      → bell icon appears
3. Global search attributes on 4 resources      → search bar works
4. Fire notifications from existing actions     → bell populates
5. Tests for both features                      → verification
```

### Architecture Decisions

- **Notifications from actions, not observers:** Keeps the pattern consistent with how SMS and audit logs are already fired. Explicit, testable, no hidden side effects.
- **sendToDatabase to all staff:** Simple query at notification time. For a 2-5 person clinic, sending to all staff/admin is fine. No per-user preferences needed.
- **Global search opt-in:** I'll set `->globalSearchResourceOptIn()` on the panel and explicitly enable only the 4 high-value resources. Prevents clutter from settings/lookup resources appearing in search.
- **No broadcast/websockets:** Notifications appear on next page load. Real-time (Pusher/Reverb) is out of scope — the panel auto-refreshes on navigation anyway.

### Risks

| Risk | Mitigation |
|------|------------|
| Notifications flood staff (too many per day) | Only 6 event types; single-clinic volume is low |
| Global search slow on large patient lists | `name` and `phone` on users are already indexed; limit results to 20 |
| Duplicate notifications if action is called multiple times | Actions are already idempotent (status gates prevent re-firing) |

---

## Tasks

### Task 1: Notifications table + panel setup

- Acceptance: `notifications` table exists; bell icon visible in topbar
- Verify: `vendor/bin/sail artisan test --compact --filter=Notification`
- Files: migration (new), `AdminPanelProvider.php`, `User.php` (confirm `Notifiable`)

### Task 2: Global search on 4 resources

- Acceptance: typing in search bar finds patients/orders/appointments/products
- Verify: `vendor/bin/sail artisan test --compact --filter=GlobalSearch`
- Files: `PatientResource.php`, `OrderResource.php`, `AppointmentResource.php`, `ProductResource.php`, `AdminPanelProvider.php`

### Task 3: Fire notifications from actions (orders + appointments)

- Acceptance: placing an order via API creates a database notification for all staff; booking appointment does the same; cancellations trigger warning notifications
- Verify: `vendor/bin/sail artisan test --compact --filter=Notification`
- Files: `app/Http/Controllers/Api/OrderController.php`, `app/Http/Controllers/Api/AppointmentController.php`, `UpdateOrderStatus.php`, `RecordInventoryMovement.php`

### Task 4: Tests for both features

- Acceptance: all notification creation + global search assertions pass
- Verify: full suite green
- Files: `tests/Feature/Filament/NotificationTest.php` (new), `tests/Feature/Filament/GlobalSearchTest.php` (new)

---

## Success Criteria

1. Bell icon in topbar with unread badge — clicking shows notification list
2. Placing an order via API creates a "New order placed" notification visible to staff in the bell
3. Low stock after inventory deduction creates a "Low stock alert" notification
4. Typing a patient name in the global search bar shows matching records with link to edit page
5. Typing an order number shows the order with link
6. All tests pass, BACKEND_CONTEXT.md updated

---

## Open Questions

None — all decisions resolved above.
