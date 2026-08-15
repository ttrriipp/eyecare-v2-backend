# EyeCare Android App — Project Context

## What This Is

Customer-facing Android app for Padilla Optical Clinic (POCMS). Consumes a Laravel 13 REST API. Lets patients browse frames, book appointments, place orders, view prescriptions/billings, and chat with clinic staff.

## Tech Stack

| Layer | Technology |
|---|---|
| Language | Kotlin 2.3.0 (AGP 9.2.1 built-in — no `kotlin.android` plugin) |
| UI | Jetpack Compose + Material 3 (BOM 2026.05.01) |
| DI | Hilt 2.59.2 |
| Network | Retrofit 2.11 + OkHttp 4.12 + Kotlinx Serialization 1.8.1 |
| Local DB | Room 2.7.1 (products cache only) |
| Navigation | Navigation Compose 2.9.0 (type-safe routes via `@Serializable`) |
| Images | Coil 3.1.0 |
| Camera/AR | CameraX 1.5.0 + MediaPipe 0.10.35 |
| Tests | JUnit 5 + MockK + Turbine + coroutines-test |

## Commands

```
./gradlew assembleDebug          # Build
./gradlew testDebugUnitTest      # Unit tests
./gradlew lintDebug              # Lint
./gradlew ktlintFormat           # Format
./gradlew ktlintCheck            # Format check
```

## Architecture

MVVM + Clean Architecture: `data/` → `domain/` → `presentation/`

```
com.eyecare.app/
├── data/
│   ├── remote/
│   │   ├── api/           # Retrofit service interfaces
│   │   ├── dto/           # Serializable DTOs (map to domain at repo boundary)
│   │   └── interceptor/   # Auth interceptor + 401 event bus
│   ├── local/
│   │   ├── dao/           # Room DAOs (ProductDao only)
│   │   ├── entity/        # Room entities
│   │   ├── EyecareDatabase.kt
│   │   └── TokenManager.kt
│   └── repository/        # Repository implementations
├── domain/
│   ├── model/             # Domain data classes + enums
│   └── repository/        # Repository interfaces
├── presentation/
│   ├── auth/              # Login, Register
│   ├── home/              # Dashboard
│   ├── catalog/           # Product list + detail
│   ├── ar/                # AR try-on (CameraX + MediaPipe)
│   ├── appointments/      # List, detail, booking wizard
│   ├── orders/            # List, detail, order request
│   ├── prescriptions/     # List + detail
│   ├── billing/           # Billing detail
│   ├── messaging/         # Chat screen
│   ├── profile/           # User profile
│   ├── navigation/        # NavGraph, Routes, BottomNavBar
│   └── common/            # Shared components + helpers
├── di/                    # Hilt modules (Network, Auth, Feature modules)
└── ui/theme/              # Color, Type, Shape, Theme
```

## Key Conventions

- **DTOs:** Live in `data/remote/dto/`, use `@Serializable` + `@SerialName`. Never leak into domain/presentation.
- **Domain models:** Plain Kotlin data classes. No serialization annotations.
- **Mapping:** Always at repository boundary (`dto.toDomain()` extension functions).
- **ViewModels:** `@HiltViewModel` + `@Inject constructor`. State as `sealed interface` via `StateFlow`.
- **Assisted inject:** Used for ViewModels needing runtime params (`@AssistedFactory`).
- **Error handling:** Repositories return `Result<T>`. ViewModels fold into UI state.
- **HTTP errors:** Catch `HttpException`, parse error body for 422/429. 401 triggers `AuthEventBus.Logout`.
- **Images:** `buildImageUrl(path)` prepends storage base URL. Prefer variant images → fallback to product images.
- **Pagination:** `PaginationMeta` (currentPage, lastPage). ViewModel tracks `hasMorePages` + `loadMore()`.
- **Navigation:** Type-safe routes via `@Serializable` objects/data classes. Auth/Main graph split.
- **Bottom-nav tab switches:** use `popUpTo<MainGraph> { saveState = true; inclusive = false }` + `launchSingleTop = true` + `restoreState = true` so switching tabs doesn't grow the back-stack or lose scroll position.
- **Wizard flows (e.g. booking):** on terminal success, pop the wizard's own route off the stack with `popUpTo(WizardRoute) { inclusive = true }` before navigating to the result screen.
- **Backend alias fields:** when the backend returns two field names for the same value (e.g. `lens_category_id` canonical + `lens_type_id` backward-compat alias), the DTO parses both but the domain model exposes only the canonical name. Repository mapping prefers canonical, falls back to alias: `lensCategoryId = lensCategoryId ?: lensTypeId`. Request bodies (e.g. `OrderItemRequest`) may keep using the alias name if the backend documents it as accepted — no need to migrate outbound fields the backend still supports.

## Booking Wizard — Date & Time Selection

`presentation/appointments/booking/BookAppointmentScreen.kt`, Step2/Step3:

- **Step2 (date):** Material3 `DatePicker` with a `SelectableDates` override that blocks past dates and Sundays (clinic closed). Selected date parsed/formatted via UTC epoch millis.
- **Step3 (time):** digital HH:MM picker — up/down arrows per segment, AM/PM tap-toggle on the right. No analog clock, no seconds.
  - Clinic hours: 9:00 AM – 5:00 PM in 15-minute increments, enforced by `isValidClinicTime(hour12, minute, isPm)`.
  - Booking and rescheduling encode the selected clinic-local time with the explicit `Asia/Manila` offset (`+08:00`) before submitting it to the API. API response timestamps, including UTC (`Z`) values, are converted to `Asia/Manila` before display and date grouping.
  - Hour steps wrap correctly across the AM/PM boundary (`11 AM → 12 PM`, `12 PM → 1 PM → … → 5 PM`); the PM cycle must include the `12 -> 1` case or the hour overflows to 13 and silently invalidates every minute value.
  - Minute steps are in **15-minute increments** (0, 15, 30, 45), guarded by `isValidClinicTime` so the picker cannot be pushed past 5:00 PM.
- **Step1 (visit reason):** compact navigation rows keep the reason name primary, show duration as a trailing clock value, and use a chevron to communicate immediate progression. The four-step indicator uses a primary active fill with a subdued surface track.
- Android system Back and the app-bar Back button share the same wizard behavior: Steps 2–4 return to the previous step, while Step 1 exits to the appointment list.
- All four steps use the same 16dp top and horizontal content grid. Steps 2–4 also share the same bottom navigation inset for stable primary-action placement, with contextual labels. Booking dates require at least one remaining slot for the selected visit duration; today's time starts at the next 15-minute slot, and past or clinic-overrun selections cannot advance. Review shows clinic-local Date and Time as separate icon rows and uses a compact optional notes field.

## Appointment Reschedule — Bottom Sheet

`presentation/appointments/RescheduleBottomSheet.kt`, invoked from `AppointmentDetailScreen.kt`:

- Rescheduling an **existing** appointment calls `POST /appointments/{id}/reschedule` — it does NOT create a new appointment. Never route the "Reschedule" action through the booking wizard (`BookAppointmentScreen`).
- UI is a `ModalBottomSheet` with a `SecondaryTabRow` (Date / Time tabs) rather than a multi-step wizard — reschedule only needs date + time, no visit reason or notes re-entry. It skips the partially expanded anchor so the calendar and confirmation controls open at a usable height on compact screens. Date and time controls update the draft selection directly, with one contextual bottom action: **Continue to time** on Date and **Review reschedule** on Time.
- The draft starts at the appointment's current clinic-local date/time. Same-day past times advance to the next 15-minute slot; past and unchanged selections are blocked locally with concise guidance before any API request.
- Reimplements the same `SelectableDates` (no past dates, no Sundays) and digital HH:MM clinic-hours picker (`isValidClinicTime`, 9:00 AM–5:00 PM, 15-minute steps) as the booking wizard, but as private composables local to this file — intentionally not shared/extracted, since `BookAppointmentScreen`'s versions are `private`.
- Reschedule and Cancel are available for `PENDING` and `CONFIRMED` statuses. Both the appointment list and detail screen use this same status set.
- The list screen's "Reschedule" button navigates to `AppointmentDetailScreen` (not the booking wizard) — actual reschedule happens via the sheet on the detail screen.
- **Confirm-before-submit:** tapping "Confirm Reschedule" in the sheet does not submit immediately — it opens an `AlertDialog` ("Reschedule this appointment to [date] at [time]?") with **Reschedule** / **Keep Current Time** actions. Only the "Reschedule" action calls `onConfirm(scheduledAt)`. Uses local `formatPickedDate`/`formatPickedTime` helpers (operate on the raw picker strings, not on a combined `scheduled_at` string — separate from `formatAppointmentDate`/`formatAppointmentTime` in `AppointmentListScreen.kt`).
- **On success:** `AppointmentDetailViewModel.rescheduleAppointment` uses the `Appointment` returned directly by the `POST /appointments/{id}/reschedule` response — it does **not** call `load()` to re-fetch. This avoids an extra network round trip and any risk of transiently showing stale data from a second GET. `showRescheduleSheet` is set to `false` and `showRescheduleSuccessDialog` to `true` in the same state update, which dismisses the bottom sheet and immediately shows a confirmation `AlertDialog` ("Appointment Rescheduled — Your appointment is now set for [date] at [time]"), dismissed via `dismissRescheduleSuccessDialog()`.
- The detail screen's "Reschedule" button uses the same filled, theme-tinted `Button` style (primary color at 12% alpha background, primary content color, no elevation) as the list screen's card action buttons, rather than the plain `OutlinedButton` used elsewhere.
- **Scheduling timezone:** picker values represent Philippine clinic-local time. Booking and rescheduling submit an ISO-8601 timestamp with the explicit `Asia/Manila` offset; response timestamps are converted by instant to `Asia/Manila` for display, filtering, and date grouping.

## Themed Confirmation Dialogs

`presentation/common/components/AppConfirmationDialog.kt`:

- Shared composable used in place of the stock Material3 `AlertDialog` wherever the app needs a confirmation or acknowledgement prompt (reschedule confirm, reschedule success, cancel-appointment confirm in `AppointmentDetailScreen.kt`, reschedule confirm in `RescheduleBottomSheet.kt`). The stock `AlertDialog` doesn't match the app's visual language (pill buttons, tinted icon badges, `CardBorder`-outlined surfaces), so this wraps a raw `Dialog` with a rounded `Surface` (24dp), a circular icon badge tinted at 12% alpha, and pill-shaped (`RoundedCornerShape(50)`) action buttons.
- Takes `confirmLabel` only for a single acknowledgement, or both `confirmLabel` + `dismissLabel` for a yes/no confirmation. `isDestructive = true` swaps the confirm button to error-colored (used for the Cancel Appointment flow).
- **Button sizing:** the action-button `Row` uses `height(IntrinsicSize.Min)` with both buttons set to `fillMaxHeight()`, rather than a fixed height — this lets both buttons grow together if a longer label (e.g. "Keep Current Time") needs to wrap to two lines, instead of the text clipping/overflowing. Button text uses `labelMedium` (not the default larger button text style) with tighter `contentPadding` to give long labels more room to fit on one line where possible.

## Bottom Navigation Shape

`presentation/navigation/SplitBottomNavBar.kt`:

- The nav-tab container and the chat FAB share the same `RoundedCornerShape(16.dp)` squircle shape (previously the nav-tab container was a full pill at 40dp) so the two bottom-bar elements read as one consistent shape language rather than two different corner treatments sitting side by side.
- The selected-tab inner highlight is `RoundedCornerShape(12.dp)` (reduced from 32dp) to stay proportional inside the smaller-radius outer container.

## Product Catalog — Filters

`presentation/catalog/ProductListScreen.kt`, `FilterRow`:

- All filter/sort controls (All, categories, Brand dropdown, Sort dropdown, Clear) live in a **single horizontally-scrollable `LazyRow`**, not a stacked multi-row layout.
- Brand and Sort are `FilterChip`s that open a `DropdownMenu` on tap.
- "Clear" only renders when a filter or non-default sort is active.

## Backend API (base: `/api`)

Key endpoints the app consumes:
```
POST   /login, /register, /logout
GET    /user                          → {data: {id, name, email, phone, role}}
PATCH  /user                          → update profile
GET    /appointments, /appointments/{id}
POST   /appointments                  → book (pending)
POST   /appointments/{id}/cancel
POST   /appointments/{id}/reschedule  → reschedule own appointment (pending/confirmed/rescheduled only)
GET    /visit-reasons                 → [{id, name, duration_minutes}]
GET    /products, /products/{id}      → frame-only, paginated (supports search/brand/category/min_price/max_price/in_stock/sort)
GET    /orders, /orders/{id}          → paginated, includes billing_id
POST   /orders                        → submit (requested)
POST   /orders/{id}/cancel
GET    /billing/{id}                  → with items[] + payments[]
GET    /prescriptions, /prescriptions/{id}
GET    /conversation                 → includes unread_count
GET    /conversation/messages        → cursor-paginated (newest-first, 50/page, next_cursor/has_more)
GET    /conversation/messages/search → ?q= term, same cursor shape
POST   /conversation/messages        → send (throttle: 10/min)
POST   /conversation/messages/read   → mark-read (returns marked_count)
GET    /notifications                → paginated notification feed
GET    /notifications/unread-count   → unread count
PATCH  /notifications/{id}/read      → mark one read
PATCH  /notifications/read-all       → mark all read
```

Auth: Sanctum token in `Authorization: Bearer {token}`. Stored via `TokenManager` (SharedPreferences). 401 → auto-logout via `AuthEventBus`.

## Branding

| Element | Value |
|---|---|
| Primary color | `#29B6F6` (logo cyan) |
| Text / on-surface | `#3D3535` (logo charcoal) |
| Background | `#F8F9FA` (warm off-white) |
| App name | EyeCare |
| Font | Instrument Sans (Google Fonts, downloaded at runtime) |

Color tokens live in `ui/theme/Color.kt` and are wired into `MaterialTheme.colorScheme` via `ui/theme/Theme.kt` (light scheme only — no dark theme defined yet). Cards use pure white (`CardSurface`) with a subtle 8%-black border (`CardBorder`, mapped to `outlineVariant`) so they float above the warm background.

## Active Specs

- `docs/specs/backend-alignment-v2-spec.md` — Complete: fixed initial API misalignments (11 tasks)
- `docs/specs/backend-alignment-v3-spec.md` — Complete: reschedule endpoint, or_number removal, lens_category_id fields, price filter params (6 tasks)
- `docs/specs/implementation-plan-v2.md` — Task breakdown for alignment v2
- `docs/BACKEND_CONTEXT.md` — Full backend documentation (source of truth for API shapes)

## Boundaries

- **Never** use Gson — only Kotlinx Serialization
- **Never** store tokens or health data in Room (only product cache)
- **Never** apply `org.jetbrains.kotlin.android` plugin (AGP 9 built-in)
- **Never** add `android.disallowKotlinSourceSets=false`
- **Always** run `./gradlew assembleDebug` after changes
- **Always** map DTOs to domain models at repository boundary
- **Always** use `sealed interface` for UI state
- **Ask first** before adding new dependencies
- **Ask first** before changing navigation graph structure
