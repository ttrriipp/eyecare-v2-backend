# Spec: Optical Clinic Journey — Android MVP

Status: Draft — awaiting review
Phase: Specify

## Assumptions

1. This is a separate Android repository; this spec file will be moved there.
2. Native Kotlin with Jetpack Compose for UI.
3. Minimum SDK 26 (Android 8.0+), target SDK 35.
4. Architecture: MVVM with Clean Architecture layers (data → domain → presentation).
5. Networking: Retrofit + OkHttp; auth via Sanctum bearer token stored in EncryptedSharedPreferences.
6. DI: Hilt.
7. Navigation: Jetpack Navigation Compose with type-safe routes.
8. Image loading: Coil.
9. AR: ARCore with MediaPipe Face Mesh for frame overlay positioning.
10. The backend base URL is a BuildConfig field, configurable per build variant.
11. The app is offline-tolerant for product browsing (Room cache), but write operations require connectivity.
12. Rate limiting exists on `/register` and `/login` (the app must handle HTTP 429 gracefully).

## Objective

Build the customer-facing Android app for the Optical Clinic Journey capstone. The app enables customers to:

- Register, log in, and manage their session.
- Book eye exam appointments and track status.
- Browse the product catalog with images and pricing.
- Try on AR-eligible frames using the device camera and face tracking.
- Submit order requests (frame + lens type selection) after AR try-on or catalog browsing.
- View prescriptions recorded by staff.
- Track order status and billing/payment history.
- Message clinic staff with optional file attachments.
- Submit feedback and ratings for completed appointments/orders.

The primary success metric is completing the capstone defense demo in under 10 minutes:
1. Open AR try-on → show live frame tracking (the "wow" moment).
2. Select frame → choose lens type → book appointment → submit order request.
3. Switch to admin panel (separate) → show processing.
4. Back on mobile → show SMS received, order status updated, billing visible.

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Language | Kotlin | 2.0+ |
| UI | Jetpack Compose + Material 3 | BOM 2025.01+ |
| Architecture | MVVM + Clean Architecture | — |
| DI | Hilt | 2.52+ |
| Navigation | Navigation Compose (type-safe) | 2.8+ |
| Networking | Retrofit 2 + OkHttp 4 + Kotlinx Serialization | — |
| Local storage | Room (catalog cache), EncryptedSharedPreferences (token) | — |
| Image loading | Coil 3 | — |
| AR | ARCore + MediaPipe Face Mesh | — |
| Camera | CameraX | 1.4+ |
| Testing | JUnit 5 + Compose UI tests + MockK + Turbine | — |
| Build | Gradle KTS, version catalogs | 8.x |

## Commands

```
# Build debug APK
./gradlew assembleDebug

# Run unit tests
./gradlew testDebugUnitTest

# Run instrumented tests
./gradlew connectedDebugAndroidTest

# Lint
./gradlew lintDebug

# Format (ktlint)
./gradlew ktlintFormat

# Check formatting
./gradlew ktlintCheck

# Clean
./gradlew clean
```

## Project Structure

```
app/
├── src/main/
│   ├── java/com/eyecare/app/
│   │   ├── di/                          → Hilt modules
│   │   ├── data/
│   │   │   ├── remote/
│   │   │   │   ├── api/                 → Retrofit service interfaces
│   │   │   │   ├── dto/                 → API request/response DTOs
│   │   │   │   └── interceptor/         → Auth token interceptor
│   │   │   ├── local/
│   │   │   │   ├── dao/                 → Room DAOs
│   │   │   │   ├── entity/              → Room entities
│   │   │   │   └── EyecareDatabase.kt
│   │   │   └── repository/             → Repository implementations
│   │   ├── domain/
│   │   │   ├── model/                   → Domain models
│   │   │   ├── repository/             → Repository interfaces
│   │   │   └── usecase/                → Use cases (when logic spans repos)
│   │   ├── presentation/
│   │   │   ├── navigation/             → NavGraph, routes, bottom nav
│   │   │   ├── auth/                   → Login, register screens
│   │   │   ├── home/                   → Dashboard / landing
│   │   │   ├── appointments/           → Booking, list, detail
│   │   │   ├── catalog/                → Product list, detail
│   │   │   ├── ar/                     → AR try-on screen + renderer
│   │   │   ├── orders/                 → Order request, list, detail
│   │   │   ├── prescriptions/          → Prescription history
│   │   │   ├── billing/                → Billing detail, payment history
│   │   │   ├── messaging/              → Conversations, chat
│   │   │   ├── feedback/               → Rating submission
│   │   │   └── common/                 → Shared composables, theme
│   │   └── EyecareApp.kt              → Application class
│   ├── res/                            → Drawables, strings, themes
│   └── AndroidManifest.xml
├── src/test/                           → Unit tests (JVM)
├── src/androidTest/                    → Instrumented tests
└── build.gradle.kts
```

## Code Style

Kotlin with Compose, concise and idiomatic:

```kotlin
@HiltViewModel
class AppointmentListViewModel @Inject constructor(
    private val appointmentRepository: AppointmentRepository,
) : ViewModel() {

    private val _uiState = MutableStateFlow<AppointmentListUiState>(AppointmentListUiState.Loading)
    val uiState: StateFlow<AppointmentListUiState> = _uiState.asStateFlow()

    init {
        loadAppointments()
    }

    private fun loadAppointments() {
        viewModelScope.launch {
            _uiState.value = appointmentRepository.getMyAppointments()
                .fold(
                    onSuccess = { AppointmentListUiState.Success(it) },
                    onFailure = { AppointmentListUiState.Error(it.message ?: "Failed to load") },
                )
        }
    }
}

sealed interface AppointmentListUiState {
    data object Loading : AppointmentListUiState
    data class Success(val appointments: List<Appointment>) : AppointmentListUiState
    data class Error(val message: String) : AppointmentListUiState
}
```

Conventions:

- Use `sealed interface` for UI states.
- Use `StateFlow` for state, not `LiveData`.
- Use `Result<T>` or a custom `ApiResult` sealed class from repositories.
- Suffix ViewModels with `ViewModel`, screens with `Screen`, composables stay lowercase.
- DTOs map to domain models at the repository boundary.
- One file per screen composable, one file per ViewModel.
- Use `@Preview` annotations on composables.
- Keep composables stateless; hoist state to ViewModel.
- Use Kotlin serialization annotations on DTOs, not Gson.

Naming:

- Packages: lowercase, no underscores (`com.eyecare.app.presentation.appointments`).
- Classes: PascalCase (`AppointmentListScreen`, `OrderRepository`).
- Functions: camelCase. Composable functions are PascalCase.
- Constants: SCREAMING_SNAKE_CASE.
- Files: match the primary class name.

## API Contract

Base URL: `{BASE_URL}/api` (configurable via `BuildConfig.API_BASE_URL`).

Auth header: `Authorization: Bearer {token}` on all protected routes.

### Authentication

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| POST | `/register` | `{name, email, phone?, password, password_confirmation}` | `{data: {token, user: {id, name, email, role}}}` 201 |
| POST | `/login` | `{email, password}` | `{data: {token, user: {id, name, email, role}}}` 200 |
| POST | `/logout` | — | 200 (empty) |
| GET | `/user` | — | `{data: {id, name, email, role}}` |

Rate limited: 10/min per IP, 5/min per email. Handle 429 with retry-after display.

### Appointments

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/appointments` | — | `{data: [{id, visit_reason, status, scheduled_at, contact_notes, staff_notes}]}` |
| POST | `/appointments` | `{visit_reason_id, scheduled_at, contact_notes?}` | `{data: {...}}` 201 |
| GET | `/appointments/{id}` | — | `{data: {...}}` |

Visit reasons (seeded): `eye_exam`, `follow_up`, `prescription_check`.
Statuses: `pending`, `confirmed`, `rescheduled`, `cancelled`, `completed`.

### Products

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/products` | — | `{data: [{id, name, slug, description, price, dimensions, brand, category, variants: [...], images: [...]}]}` |
| GET | `/products/{id}` | — | `{data: {...}}` |

Variant shape: `{id, name, sku, price, dimensions, ar_eligible, ar_asset_reference}`.
Image shape: `{id, path, is_primary, sort_order}`.

No auth required for product browsing (currently behind `auth:sanctum` — confirm with backend if public access needed for guest browsing, or just keep authenticated).

### Orders

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/orders` | — | `{data: [{id, order_number, appointment_id, is_non_prescription, status, subtotal, total_amount, items: [...], created_at}]}` |
| POST | `/orders` | `{appointment_id?, is_non_prescription, items: [{product_variant_id, lens_type_id, quantity}]}` | `{data: {...}}` 201 |
| GET | `/orders/{id}` | — | `{data: {...}}` |

Order item shape: `{id, product_variant_id, lens_type_id, product_id, product_name, variant_name, variant_sku, lens_type_name, unit_price, quantity, subtotal}`.
Statuses: `requested`, `under_review`, `confirmed`, `preparing`, `ready_for_pickup`, `completed`, `cancelled`.

### Prescriptions

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/prescriptions` | — | `{data: [{id, appointment_id, od_*, os_*, pd, prescribed_at, expires_at, notes}]}` |
| GET | `/prescriptions/{id}` | — | `{data: {...}}` |

### Billing

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/billing/{id}` | — | `{data: {id, order_id, status, total_amount, amount_paid, balance_due, issued_at, created_at, payments: [...]}}` |

Payment shape: `{id, amount, status, method, reference_number, paid_at}`.
Billing statuses: `draft`, `issued`, `partially_paid`, `paid`, `voided`.

### Conversations & Messages

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/conversations` | — | `{data: [{id, customer_id, staff_id, appointment_id, order_id, subject, created_at}]}` |
| POST | `/conversations` | `{subject?, body, appointment_id?, order_id?}` | `{data: {...}}` 201 |
| GET | `/conversations/{id}/messages` | — | `{data: [{id, conversation_id, sender_id, body, read_at, created_at, attachments: [...]}]}` |
| POST | `/conversations/{id}/messages` | multipart: `body` + optional `attachment` file | `{data: {...}}` 201 |

Attachment shape: `{id, original_name, mime_type, file_size}`.
Allowed file types: jpg, jpeg, png, gif, pdf, doc, docx. Max 10MB.

### Feedback

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| POST | `/feedback` | `{appointment_id? or order_id?, rating (1-5), comment?}` | `{data: {id, appointment_id, order_id, rating, comment, staff_reply, replied_at}}` 201 |

Requires a completed appointment or completed order.

### Error Responses

Standard Laravel validation errors:
```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

HTTP status codes to handle: 401 (redirect to login), 403 (show forbidden), 404 (show not found), 422 (show field errors), 429 (show rate limit message with retry-after), 500 (generic error).

## Screens & Navigation

### Navigation Structure

```
AuthGraph (unauthenticated):
  ├── LoginScreen
  └── RegisterScreen

MainGraph (authenticated, bottom nav — 4 tabs):
  ├── HomeTab
  │   └── HomeScreen (upcoming appointment, latest order status, feedback prompts)
  ├── CatalogTab
  │   ├── ProductListScreen
  │   ├── ProductDetailScreen
  │   └── ArTryOnScreen
  ├── AppointmentsTab
  │   ├── AppointmentListScreen
  │   ├── BookAppointmentWizard (step 1: reason → step 2: date/time → step 3: notes + confirm)
  │   └── AppointmentDetailScreen
  └── MoreTab
      ├── OrderListScreen
      ├── OrderRequestScreen (frame + lens selection + submit)
      ├── OrderDetailScreen (includes billing link)
      ├── BillingDetailScreen
      ├── PrescriptionListScreen
      ├── PrescriptionDetailScreen
      ├── ChatScreen (single persistent conversation with contextual cards)
      ├── FeedbackHistoryScreen
      ├── FeedbackScreen (star rating + comment — navigated from completed appointment/order detail)
      └── ProfileScreen (logout)
```

### Screen Descriptions

| Screen | Purpose | Key interactions |
|--------|---------|-----------------|
| LoginScreen | Email + password login | Navigate to Register; handle 429 |
| RegisterScreen | Name, email, phone, password, confirm | Validate locally before submit |
| HomeScreen | Quick status: next appointment, latest order, feedback prompts, services shortcuts | Service tiles navigate to booking/catalog/chat; cards link to detail screens |
| ProductListScreen | Grid of active products with images | Search/filter by category/brand; tap → detail |
| ProductDetailScreen | Images carousel, price, variants, dimensions | "Try AR" button (if AR-eligible), "Order" button |
| ArTryOnScreen | Camera feed + AR frame overlay on face | Switch variants, capture screenshot, "Order this frame" CTA |
| AppointmentListScreen | List with status chips | Pull to refresh, tap → detail |
| BookAppointmentWizard | Multi-step: reason → date/time → notes + confirm | Step indicator, back/next, submit on final step |
| AppointmentDetailScreen | Full appointment info + status | Shows staff notes; "Leave Feedback" button when completed |
| OrderRequestScreen | Selected variant + lens type picker + quantity | Links optional appointment; submit |
| OrderListScreen | Orders with status chips | Tap → detail |
| OrderDetailScreen | Items, totals, status timeline | Link to billing; "Leave Feedback" button when completed |
| PrescriptionListScreen | Chronological prescriptions | Tap → detail |
| PrescriptionDetailScreen | Full OD/OS/PD values | Read-only |
| BillingDetailScreen | Totals, balance due, payment history | Read-only |
| ChatScreen | Single persistent conversation with contextual cards | Send text or file; attach appointment/order context as inline cards |
| FeedbackScreen | Star rating + comment | Only reachable from completed appointments/orders |
| FeedbackHistoryScreen | Past feedback with staff replies | Read-only list |
| ProfileScreen | User info + logout | — |

## AR Implementation Approach

### Architecture

```
ArTryOnScreen (Compose)
  └── AndroidView (GLSurfaceView or SurfaceView)
      ├── CameraX Preview (camera feed)
      ├── ARCore Session (depth/tracking) OR MediaPipe FaceMesh
      └── Frame Renderer (OpenGL ES overlay)
```

### Strategy: MediaPipe Face Mesh + 2D/3D Frame Overlay

Given the capstone timeline, the recommended approach:

1. **MediaPipe Face Landmarker** — detect 468+ face landmarks in real-time from CameraX feed.
2. **Key landmarks for frame placement:**
   - Nose bridge (landmarks 6, 168) → frame center Y position.
   - Left temple (landmark 234) + Right temple (landmark 454) → frame width scaling.
   - Face rotation from landmark geometry → tilt the frame overlay.
3. **Frame rendering:** Load a PNG/SVG frame asset (referenced by `ar_asset_reference` from API) and draw it as a transformed 2D overlay anchored to the computed face landmarks. This is simpler than full 3D mesh rendering and sufficient for the demo.
4. **Fallback:** If no face detected, show a message prompting the user to position their face in the frame guide.

### Why MediaPipe over ARCore Face Mesh

- ARCore face mesh requires a front-facing camera with depth (limited device support).
- MediaPipe works on any device with a front camera and is lighter weight.
- For a 2D frame overlay demo, face landmark positioning is sufficient — we don't need full 3D mesh attachment.

### AR Asset Format

The `ar_asset_reference` from the API points to a frame overlay image (PNG with transparency). The app will:
1. Fetch the asset URL from variant data.
2. Cache locally using Coil's disk cache.
3. Render as a bitmap overlay scaled and positioned based on face landmarks.

### Performance Targets

- 30+ FPS on mid-range devices.
- Frame detection latency < 50ms.
- Memory: < 150MB during AR session.

## Testing Strategy

### Unit Tests (JVM — `src/test/`)

- ViewModel state transitions (use Turbine for Flow testing).
- Repository mapping (DTO → domain model).
- Use case logic.
- Token management (store/clear/retrieve).

### Instrumented Tests (`src/androidTest/`)

- Compose UI tests for critical screens (login, booking, order request).
- Navigation tests (auth gate, bottom nav routing).
- Room DAO tests for cached products.

### Coverage Expectations

- Every ViewModel: at least one test per UI state transition.
- Every repository: test success and error mapping.
- Critical flows (auth, booking, order): end-to-end Compose tests with fake API.
- AR: manual testing only — no automated tests for camera/ML rendering.

### Test Tooling

| Tool | Purpose |
|------|---------|
| JUnit 5 | Test runner |
| MockK | Mocking |
| Turbine | StateFlow/Flow testing |
| Compose Testing | UI assertions |
| OkHttp MockWebServer | Fake API in instrumented tests |
| Hilt Testing | DI in tests |

## Boundaries

### Always

- Use Hilt for all dependency injection.
- Store auth token in EncryptedSharedPreferences, never in plain SharedPreferences or logs.
- Map DTOs to domain models at the repository layer.
- Handle 401 globally by clearing token and navigating to login.
- Handle 429 by showing a user-friendly "too many attempts" message.
- Use `StateFlow` for UI state, not `LiveData`.
- Run `ktlintFormat` before commits.
- Use version catalog for dependency management.
- Request camera permission before AR — handle denial gracefully.
- Cache product catalog in Room for offline browsing.

### Ask First

- Adding new Gradle dependencies.
- Changing minimum SDK version.
- Adding new permissions to AndroidManifest.
- Changing the navigation graph structure.
- Adding offline write support (queue-and-sync).
- Changing AR approach (e.g., switching from MediaPipe to ARCore).
- Adding push notifications (FCM).

### Never

- Store face geometry, landmarks, biometric data, or AR analytics persistently.
- Log auth tokens or user passwords.
- Hard-code API base URLs (use BuildConfig).
- Ship with debug logging enabled in release builds.
- Access backend admin/staff endpoints from the mobile app.
- Store full prescription data in Room (sensitive medical data stays server-side, only cache IDs for navigation).
- Use Gson — use Kotlinx Serialization.
- Commit signing keys or keystore files.

## Success Criteria

### Foundation

- Customer can register, log in, and persist session across app restarts.
- Token is stored securely; 401 responses redirect to login.
- Bottom navigation shows all five tabs.
- Product catalog loads and displays with images.

### Core Journey

- Customer can book an appointment with date/time picker and visit reason selection.
- Customer can browse products, see variants, and identify AR-eligible frames.
- AR try-on launches from product detail, tracks face landmarks, and overlays selected frame.
- Customer can submit an order request with frame variant + lens type after AR or catalog browsing.
- Customer can view appointment list with status updates from staff actions.
- Customer can view order status and linked billing information.
- Customer can view prescription history.

### Communication & Feedback

- Customer can start a conversation and send messages.
- Customer can attach files (images/documents) to messages.
- Customer can submit feedback/rating for completed appointments or orders.

### Defense Demo

- The full demo script (AR → order → appointment → review on admin → SMS → status update) completes in under 10 minutes using seeded demo data.
- AR try-on is the opening hook — works reliably on the demo device.
- No crashes or ANRs during the demo path.

## UI Design System

### Color Palette

Derived from the clinic's logo (sky blue eye with dark charcoal outline) and the reference medical app aesthetic.

| Token | Hex | Usage |
|-------|-----|-------|
| `primary` | `#2E9AFF` | Buttons, active nav, links, selected states |
| `primaryContainer` | `#E8F4FF` | Light blue surface cards (hero areas, highlighted sections) |
| `onPrimary` | `#FFFFFF` | Text/icons on primary buttons |
| `surface` | `#FFFFFF` | Screen backgrounds, cards |
| `surfaceVariant` | `#F5F7FA` | Subtle background differentiation (list backgrounds, input fields) |
| `onSurface` | `#1A1C1E` | Primary text (headings, body) |
| `onSurfaceVariant` | `#6B7280` | Secondary text (labels, captions, timestamps) |
| `outline` | `#E0E4E8` | Borders, dividers, card strokes |
| `charcoal` | `#3D3D3D` | Derived from logo outline — used for emphasis icons, toolbar titles |
| `statusPending` | `#F59E0B` | Pending appointment/order chips |
| `statusConfirmed` | `#10B981` | Confirmed/completed chips |
| `statusCancelled` | `#EF4444` | Cancelled/error chips |
| `statusInfo` | `#6366F1` | Under review, rescheduled, informational chips |
| `error` | `#DC2626` | Validation errors, destructive actions |

Material 3 dynamic color: **Off.** Use the fixed palette above for consistency with the clinic brand. Set `primary` as the color seed only as a fallback.

### Typography

Use the default Material 3 type scale (Roboto) — no custom fonts. Medical apps need to be readable, not stylish. Personality comes from layout and color, not typeface experimentation.

| Role | Style | Weight | Size |
|------|-------|--------|------|
| Display | Screen titles (e.g., "My Appointments") | SemiBold (600) | 22sp |
| Headline | Section headers (e.g., "Upcoming", "Products") | Medium (500) | 18sp |
| Title | Card titles, list item names | Medium (500) | 16sp |
| Body | General content, descriptions | Regular (400) | 14sp |
| Label | Chips, buttons, captions | Medium (500) | 12sp |
| Caption | Timestamps, helper text | Regular (400) | 12sp |

Text color: Use `onSurface` for primary text, `onSurfaceVariant` for secondary/helper text. Never use grey lighter than `#9CA3AF` on white — accessibility floor.

### Component Conventions

**Cards:** White background, 12dp corner radius, 1dp `outline` border (no drop shadow — keeps it clean and flat). Use `surfaceVariant` background only for nested sections inside cards.

**Buttons:**
- Primary: Filled, `primary` background, white text, 24dp corner radius (pill shape), 48dp minimum height.
- Secondary: Outlined, `primary` border and text, transparent background.
- Text buttons: For tertiary actions ("See All", "Skip").

**Chips (Status):**
- Small pill shape (8dp radius), tinted background matching the status color at 15% opacity, full-color text.
- Example: Pending → `#F59E0B` text on `#FEF3C7` background.

**Input fields:** Outlined style, 12dp radius, `surfaceVariant` fill when idle, `primary` border on focus. Label above (not floating inside).

**Bottom navigation:** 4 items (Home, Catalog, Appointments, More), filled circle behind active icon (using `primaryContainer`), `primary` tint on active icon, `onSurfaceVariant` for inactive.

**Lists:** No heavy dividers — use 16dp vertical spacing between items instead. Cards for distinct entities (appointments, orders), simple rows for flat lists (prescriptions, messages).

**Image handling:** Product images in 8dp rounded-corner containers. Use a light grey `#F0F2F5` placeholder while loading.

### Layout Principles

- **16dp horizontal padding** on all screens (consistent with Material 3 default).
- **Card-based structure**: Each appointment, order, product is a card. Screens are vertical scrolls of cards and sections.
- **Section pattern**: Bold headline → content → optional "See All" text button aligned right. Matches the reference apps.
- **Spacing rhythm**: 8dp / 12dp / 16dp / 24dp. No arbitrary values.
- **Bottom nav height**: 80dp. Content scrolls behind it with edge-to-edge.

### Screen-Specific Design Notes

**Home:** Greeting at top ("Hello, [Name]"). Services row below — 4 icon tiles in a horizontal row (Eye Exam, Check-up, Try Frames, Message), each a navigation shortcut hardcoded in the app. Below that: `primaryContainer` card for next appointment, then latest order status card. Feedback prompt card appears when applicable. Clean, not busy.

**Product catalog:** 2-column grid, product image top (square, fills card width), name + price below. AR-eligible badge: small blue pill overlaid on image bottom-right corner.

**AR try-on:** Full-bleed camera — no padding, no cards. The UI shrinks to floating controls (variant selector as a horizontal chip row at the bottom, close button top-left, "Order" FAB bottom-right). This is the immersive moment — everything else disappears.

**Appointment booking:** Multi-step wizard with a step indicator (3 dots/bar at the top). Step 1: Large tappable cards for visit reason (eye exam, follow-up, prescription check). Step 2: Calendar date picker + time slot selection. Step 3: Optional contact notes + review summary → submit button. Each step is a full-screen panel that slides horizontally. "Back" returns to previous step, "Next" advances. Final step shows "Confirm Booking."

**Chat:** Single persistent conversation — no conversation list. The screen opens directly into the message thread with the clinic. Contextual cards appear inline when the user links an appointment or order (shown as a compact card bubble with entity type, ID, and status). A "+" or attachment button offers: attach file, link appointment, link order. Staff replies appear as grey bubbles. Read receipts shown subtly under own messages.

**Feedback:** Not a standalone destination — triggered from completed appointment/order detail screens via a "Leave Feedback" button. Opens a bottom sheet or dedicated screen with star picker (1–5) and comment field. After submission, the button changes to "View Feedback" showing their rating + any staff reply. A "My Feedback" list in More shows all past submissions.

### Signature Element

The **AR try-on transition**. The app goes from a clinical, card-based tool to a full-screen, immersive camera experience. This contrast is intentional — it makes the AR moment feel like a "wow" not because of decoration, but because of the shift from structured medical UI to an open, frameless view. The only UI elements visible during AR are:
- The frame overlay on the face.
- A bottom sheet-style variant selector (translucent).
- A floating "Order this frame" button.

Everything else — nav bar, headers, status bar content — fades away. This is the one place the app takes a visual risk, and it's justified because AR try-on is the capstone's hook.

### Accessibility Baseline

- All text meets WCAG AA contrast (4.5:1 for body, 3:1 for large text).
- Touch targets minimum 48dp × 48dp.
- Status chips never communicate state by color alone — always include text label.
- Screen reader content descriptions on product images, AR controls, and status indicators.

## Decisions

1. Bottom navigation has 4 tabs (Home, Catalog, Appointments, More). Orders, prescriptions, billing, messaging, and feedback live under More.
2. Appointment booking uses a 3-step wizard (reason → date/time → notes + confirm) instead of a single long form.
3. Messaging uses a single persistent conversation per customer. The app creates one conversation on first message and always reuses it. Contextual appointment/order references appear as inline cards in the message stream. **Backend note:** The mobile app will always fetch the customer's first conversation (`GET /conversations` and pick the first result) or create one if empty. No conversation list UI is needed.
4. Feedback is not a standalone screen — it's triggered from completed appointment/order detail screens via a "Leave Feedback" button. A separate "My Feedback" history is accessible from More.
5. AR try-on is the immersive signature moment — full-bleed camera with minimal floating UI. All other screens stay clinical and card-based to create contrast.

## Open Questions

None — assumptions confirmed. Ready for plan review.

## Implementation Plan

### Major Components

1. **Project setup & auth** — Gradle, Hilt, Retrofit, navigation shell, login/register, token management.
2. **Home & appointments** — Dashboard, appointment list, booking flow, detail screen.
3. **Product catalog** — List with images, detail, Room cache, category/brand filtering.
4. **AR try-on** — CameraX + MediaPipe setup, frame overlay rendering, variant switching.
5. **Order request** — Order form (variant + lens type), submission, order list/detail.
6. **Prescriptions & billing** — Prescription history, billing detail with payments.
7. **Messaging** — Conversation list, chat screen, file attachments.
8. **Feedback & polish** — Feedback submission, error states, loading states, demo hardening.

### Implementation Order

1. Project scaffolding, Hilt, Retrofit, auth (login/register/token).
2. Navigation shell with bottom tabs and auth gate.
3. Home screen with placeholder content.
4. Appointment list and booking flow.
5. Product catalog (list + detail + Room cache).
6. AR try-on (camera + MediaPipe + frame overlay).
7. Order request flow from product/AR.
8. Order list/detail + billing detail.
9. Prescription history.
10. Messaging (conversations + chat + attachments).
11. Feedback submission.
12. Polish, error handling, demo data integration, defense rehearsal.

### Sequential Dependencies

- Auth must exist before any authenticated screen.
- Navigation shell must exist before individual screens.
- Product catalog must exist before AR try-on and order request.
- AR try-on depends on CameraX + MediaPipe setup + product variants loaded.
- Order request depends on product catalog (variant selection) and optionally appointments (appointment linking).
- Billing detail depends on order detail (linked from order).
- Messaging and feedback are independent of each other but require auth.

### Parallel Work Opportunities

- Room database setup can happen alongside Retrofit setup.
- Messaging UI can be built in parallel with AR development.
- Feedback screen is independent and can be built anytime after auth.
- Unit tests for ViewModels can be written alongside screen development.

### Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| MediaPipe face detection performance on target device | Test on actual demo device early (Task 10); have fallback "static frame preview" if FPS is unacceptable |
| AR asset format mismatch | Define asset spec early (PNG with transparency, standard dimensions); test with actual backend-seeded references |
| CameraX + Compose interop complexity | Use `AndroidView` wrapper; prototype in Task 9 before full AR logic |
| Network errors during demo | Cache products in Room; pre-load catalog before defense; handle offline gracefully |
| Rate limiting on login during demo setup | Use pre-authenticated seeded account; login once before demo starts |
| Large APK from ML model bundling | Use MediaPipe's download-on-first-use model approach or bundle only the face landmarker model (~4MB) |

### Verification Checkpoints

1. **Auth checkpoint** — Login, register, token persistence, 401 handling all work.
2. **Navigation checkpoint** — All tabs navigate correctly, auth gate redirects unauthenticated users.
3. **Appointment checkpoint** — Book appointment, see it in list with pending status.
4. **Catalog checkpoint** — Products load with images, detail shows variants, AR-eligible is indicated.
5. **AR checkpoint** — Camera opens, face detected, frame overlay renders and tracks head movement.
6. **Order checkpoint** — Submit order from product/AR, see in order list with requested status.
7. **Full journey checkpoint** — Complete demo script end-to-end with seeded backend data.

## Task Breakdown

### Phase 1: Project Foundation

#### Task 1: Project Scaffolding

**Description:** Create the Android project with Gradle KTS, version catalog, Hilt, Retrofit, Kotlinx Serialization, and base project structure.

**Acceptance criteria:**
- [ ] Project compiles and runs on emulator showing a blank screen.
- [ ] Hilt is configured and injecting a placeholder ViewModel.
- [ ] Retrofit is configured with `BuildConfig.API_BASE_URL`.
- [ ] Kotlinx Serialization is set up for JSON parsing.
- [ ] ktlint is configured and passing.
- [ ] Version catalog manages all dependency versions.

**Verify:** `./gradlew assembleDebug` succeeds. `./gradlew ktlintCheck` passes.

**Files:** `build.gradle.kts`, `gradle/libs.versions.toml`, `app/build.gradle.kts`, `di/NetworkModule.kt`, `EyecareApp.kt`

**Scope:** M

#### Task 2: Auth — Token Management & API Service

**Description:** Implement secure token storage, auth API service, and OkHttp interceptor that attaches Bearer token to requests.

**Acceptance criteria:**
- [ ] Token is stored in EncryptedSharedPreferences.
- [ ] OkHttp interceptor attaches `Authorization: Bearer {token}` to all API calls.
- [ ] 401 responses trigger token clearing and navigation to login.
- [ ] AuthApiService defines `register`, `login`, `logout`, `getUser` endpoints.

**Verify:** Unit test for token store (save/retrieve/clear). Unit test for interceptor behavior.

**Files:** `data/remote/api/AuthApiService.kt`, `data/remote/interceptor/AuthInterceptor.kt`, `data/local/TokenStore.kt`, `di/NetworkModule.kt`

**Scope:** M

#### Task 3: Auth — Login & Register Screens

**Description:** Build login and register screens with form validation, loading states, and error display.

**Acceptance criteria:**
- [ ] Login screen: email + password fields, login button, link to register.
- [ ] Register screen: name + email + phone + password + confirm fields, register button.
- [ ] Client-side validation (email format, password match, required fields) before API call.
- [ ] Shows API validation errors per field (422 response).
- [ ] Shows rate limit message on 429.
- [ ] Successful auth navigates to main graph and persists token.

**Verify:** Compose UI test for login validation. Unit test for AuthViewModel states.

**Files:** `presentation/auth/LoginScreen.kt`, `presentation/auth/RegisterScreen.kt`, `presentation/auth/AuthViewModel.kt`, `domain/repository/AuthRepository.kt`, `data/repository/AuthRepositoryImpl.kt`

**Scope:** M

#### Task 4: Navigation Shell & Auth Gate

**Description:** Set up Jetpack Navigation Compose with auth/main graphs and bottom navigation bar.

**Acceptance criteria:**
- [ ] Unauthenticated users see only auth screens.
- [ ] Authenticated users see bottom nav with 4 tabs (Home, Catalog, Appointments, More).
- [ ] Back navigation works correctly between and within tabs.
- [ ] Logout clears token and returns to login.

**Verify:** App launches, shows login, after auth shows bottom nav. Compose navigation test.

**Files:** `presentation/navigation/NavGraph.kt`, `presentation/navigation/BottomNavBar.kt`, `presentation/navigation/Routes.kt`

**Scope:** M

### Phase 1 Checkpoint

- [ ] `./gradlew assembleDebug` succeeds.
- [ ] Login → see bottom nav. Logout → back to login.
- [ ] Token persists across process death.

### Phase 2: Appointments & Home

#### Task 5: Home Screen

**Description:** Build the home/dashboard screen with greeting, services row, upcoming appointment, and latest order status.

**Acceptance criteria:**
- [ ] Greeting with user name at top.
- [ ] Services row: 4 hardcoded shortcut tiles (Eye Exam → book with pre-selected reason, Check-up → book, Try Frames → catalog AR filter, Message → chat). Not fetched from API.
- [ ] Shows next upcoming appointment (status, date, visit reason) or "No upcoming appointments" placeholder.
- [ ] Shows latest order status card or "No orders" placeholder.
- [ ] Feedback prompt card when a completed appointment/order has no feedback yet.
- [ ] Cards are tappable and navigate to detail screens.
- [ ] Pull-to-refresh reloads data.

**Verify:** Unit test for HomeViewModel. Compose preview renders both states.

**Files:** `presentation/home/HomeScreen.kt`, `presentation/home/HomeViewModel.kt`, `presentation/home/ServiceRow.kt`

**Scope:** S

#### Task 6: Appointment List & Detail

**Description:** Build appointment list with status chips and detail screen.

**Acceptance criteria:**
- [ ] List shows appointments sorted by most recent scheduled date.
- [ ] Status chips use distinct colors (pending=yellow, confirmed=green, cancelled=red, etc.).
- [ ] Detail screen shows all appointment fields including staff notes.
- [ ] Pull-to-refresh on list.

**Verify:** Unit test for ViewModel. Compose test for list rendering.

**Files:** `presentation/appointments/AppointmentListScreen.kt`, `presentation/appointments/AppointmentDetailScreen.kt`, `presentation/appointments/AppointmentListViewModel.kt`, `data/remote/api/AppointmentApiService.kt`, `data/repository/AppointmentRepositoryImpl.kt`

**Scope:** M

#### Task 7: Book Appointment Wizard

**Description:** Build the multi-step appointment booking wizard with visit reason selection, date/time picker, and notes/confirmation.

**Acceptance criteria:**
- [ ] Step 1: Large tappable cards for visit reasons (eye_exam, follow_up, prescription_check).
- [ ] Step 2: Date picker (future dates only) and time selection.
- [ ] Step 3: Optional contact notes (max 1000 chars) + summary review → submit.
- [ ] Step indicator shows progress (3 steps).
- [ ] Back navigation returns to previous step without losing state.
- [ ] Submit creates appointment and navigates to list on success.
- [ ] Shows validation errors from API.

**Verify:** Unit test for wizard state management. Compose UI test for step transitions.

**Files:** `presentation/appointments/BookAppointmentWizard.kt`, `presentation/appointments/BookAppointmentViewModel.kt`, `presentation/appointments/steps/VisitReasonStep.kt`, `presentation/appointments/steps/DateTimeStep.kt`, `presentation/appointments/steps/ConfirmStep.kt`

**Scope:** M

### Phase 2 Checkpoint

- [ ] Book an appointment → appears in list as "pending".
- [ ] Home screen shows upcoming appointment.

### Phase 3: Product Catalog

#### Task 8: Product Catalog API & Room Cache

**Description:** Set up product API service, Room database for offline caching, and repository that serves from cache with network refresh.

**Acceptance criteria:**
- [ ] Room database stores products, variants, and images.
- [ ] Repository fetches from network and updates Room cache.
- [ ] If offline, serves cached data.
- [ ] Domain models are clean (no Room/Retrofit annotations).

**Verify:** Unit test for repository caching logic. Room DAO test.

**Files:** `data/remote/api/ProductApiService.kt`, `data/local/EyecareDatabase.kt`, `data/local/dao/ProductDao.kt`, `data/local/entity/ProductEntity.kt`, `data/repository/ProductRepositoryImpl.kt`, `domain/model/Product.kt`

**Scope:** M

#### Task 9: Product List Screen

**Description:** Grid display of products with images, prices, and category/brand filtering.

**Acceptance criteria:**
- [ ] Products displayed in a 2-column grid with primary image, name, price.
- [ ] Filter chips for categories and brands.
- [ ] AR-eligible badge shown on products with AR variants.
- [ ] Pull-to-refresh updates from API.
- [ ] Tap navigates to product detail.

**Verify:** Compose test for grid rendering. Unit test for filter logic in ViewModel.

**Files:** `presentation/catalog/ProductListScreen.kt`, `presentation/catalog/ProductListViewModel.kt`

**Scope:** M

#### Task 10: Product Detail Screen

**Description:** Product detail with image carousel, variant selector, pricing, and action buttons.

**Acceptance criteria:**
- [ ] Image carousel/pager with product images.
- [ ] Variant selector (shows name, price, SKU).
- [ ] "Try with AR" button visible only for AR-eligible variants.
- [ ] "Order this frame" button navigates to order request with pre-selected variant.
- [ ] Product dimensions and description displayed.

**Verify:** Compose test for variant selection UI. Preview with sample data.

**Files:** `presentation/catalog/ProductDetailScreen.kt`, `presentation/catalog/ProductDetailViewModel.kt`

**Scope:** M

### Phase 3 Checkpoint

- [ ] Products load in grid with images.
- [ ] Filter by category works.
- [ ] Detail shows variants; AR button appears for eligible variants.

### Phase 4: AR Try-On

#### Task 11: CameraX + MediaPipe Setup

**Description:** Set up CameraX preview with MediaPipe Face Landmarker for real-time face detection.

**Acceptance criteria:**
- [ ] Camera permission requested and handled (show rationale, handle denial).
- [ ] Front-facing camera preview displayed in a Compose `AndroidView`.
- [ ] MediaPipe Face Landmarker detects face and returns landmarks at 30+ FPS.
- [ ] No face detected → show guide message overlay.

**Verify:** Manual test on device. Unit test for permission state handling in ViewModel.

**Files:** `presentation/ar/ArTryOnScreen.kt`, `presentation/ar/ArViewModel.kt`, `presentation/ar/FaceLandmarkerHelper.kt`

**Scope:** L

#### Task 12: Frame Overlay Renderer

**Description:** Render the selected frame's AR asset as a positioned overlay on the detected face.

**Acceptance criteria:**
- [ ] Frame PNG loaded from `ar_asset_reference` URL (cached by Coil).
- [ ] Frame positioned using nose bridge and temple landmarks.
- [ ] Frame scales with face width (temple-to-temple distance).
- [ ] Frame tilts with face rotation.
- [ ] Variant switching updates the overlay immediately.
- [ ] "Order this frame" CTA button below camera view.

**Verify:** Manual test on device with demo frame assets. Verify frame tracks head movement.

**Files:** `presentation/ar/FrameOverlayRenderer.kt`, `presentation/ar/ArTryOnScreen.kt` (update)

**Scope:** L

### Phase 4 Checkpoint

- [ ] AR screen opens with camera, detects face, overlays frame.
- [ ] Switching variants changes the frame overlay.
- [ ] Works at 30+ FPS on demo device.

### Phase 5: Orders & Billing

#### Task 13: Order Request Screen

**Description:** Build the order submission form with pre-selected variant, lens type picker, and optional appointment link.

**Acceptance criteria:**
- [ ] Shows selected frame variant (from product detail or AR).
- [ ] Lens type dropdown (single_vision, bifocal, progressive).
- [ ] Quantity selector.
- [ ] Optional appointment linker (dropdown of user's appointments).
- [ ] Non-prescription toggle.
- [ ] Submit creates order → navigates to order list.

**Verify:** Unit test for ViewModel validation and submission. Compose test for form.

**Files:** `presentation/orders/OrderRequestScreen.kt`, `presentation/orders/OrderRequestViewModel.kt`, `data/remote/api/OrderApiService.kt`, `data/repository/OrderRepositoryImpl.kt`

**Scope:** M

#### Task 14: Order List & Detail

**Description:** Build order list with status timeline and detail screen linking to billing.

**Acceptance criteria:**
- [ ] Order list shows order number, status chip, total amount, date.
- [ ] Detail shows all items with product name, variant, lens type, prices.
- [ ] Status timeline/stepper visualization.
- [ ] "View Billing" button when billing exists (order is confirmed+).
- [ ] Pull-to-refresh.

**Verify:** Unit test for ViewModel. Compose test for list.

**Files:** `presentation/orders/OrderListScreen.kt`, `presentation/orders/OrderDetailScreen.kt`, `presentation/orders/OrderListViewModel.kt`, `presentation/orders/OrderDetailViewModel.kt`

**Scope:** M

#### Task 15: Billing Detail Screen

**Description:** Show billing totals, balance due, and payment history.

**Acceptance criteria:**
- [ ] Shows total amount, amount paid, balance due.
- [ ] Status chip (issued, partially_paid, paid, etc.).
- [ ] Payment list with amount, method, reference, date, status.
- [ ] Read-only — no payment actions from mobile.

**Verify:** Unit test for BillingViewModel. Compose preview.

**Files:** `presentation/billing/BillingDetailScreen.kt`, `presentation/billing/BillingDetailViewModel.kt`, `data/remote/api/BillingApiService.kt`, `data/repository/BillingRepositoryImpl.kt`

**Scope:** S

### Phase 5 Checkpoint

- [ ] Submit order from product detail → appears in order list as "requested".
- [ ] Order detail shows items and links to billing.

### Phase 6: Prescriptions, Messaging & Feedback

#### Task 16: Prescription History

**Description:** Build prescription list and detail screens.

**Acceptance criteria:**
- [ ] List shows prescriptions by date.
- [ ] Detail shows full OD/OS/PD values in a structured layout.
- [ ] Shows linked appointment ID if available.
- [ ] Expiration date highlighted if expired.

**Verify:** Unit test for ViewModel. Compose preview.

**Files:** `presentation/prescriptions/PrescriptionListScreen.kt`, `presentation/prescriptions/PrescriptionDetailScreen.kt`, `presentation/prescriptions/PrescriptionListViewModel.kt`

**Scope:** S

#### Task 17: Chat Screen (Single Conversation)

**Description:** Build the single persistent conversation chat screen with contextual appointment/order cards inline.

**Acceptance criteria:**
- [ ] Opens directly into the customer's single conversation (creates one on first message if none exists).
- [ ] Messages displayed as bubbles (own = right/blue, staff = left/grey).
- [ ] Text input with send button.
- [ ] "+" button offers: attach file, link appointment, link order.
- [ ] Linked appointments/orders appear as compact inline context cards in the message stream.
- [ ] Read status shown on messages.

**Verify:** Unit test for message sending and conversation creation. Compose test for bubble layout.

**Files:** `presentation/messaging/ChatScreen.kt`, `presentation/messaging/ChatViewModel.kt`, `presentation/messaging/MessageBubble.kt`, `presentation/messaging/ContextCard.kt`

**Scope:** M

#### Task 18: Chat Attachments & Context Linking

**Description:** Add file attachment support and appointment/order context linking to messages.

**Acceptance criteria:**
- [ ] Attachment button opens file picker (image/document).
- [ ] Attachment preview shown before send.
- [ ] Attachment metadata displayed inline (filename, size, icon).
- [ ] "Link appointment" shows a picker of user's appointments → inserts context card.
- [ ] "Link order" shows a picker of user's orders → inserts context card.
- [ ] Context cards display entity type, identifier, and current status.

**Verify:** Unit test for attachment upload. Compose test for context card rendering.

**Files:** `presentation/messaging/ChatScreen.kt` (update), `presentation/messaging/AttachmentPicker.kt`, `presentation/messaging/ContextCard.kt` (update), `presentation/messaging/ContextLinkSheet.kt`

**Scope:** M

#### Task 19: Feedback Submission & History

**Description:** Build the feedback flow triggered from completed appointment/order detail screens, plus a history list.

**Acceptance criteria:**
- [ ] "Leave Feedback" button visible on completed appointment and order detail screens.
- [ ] Star rating picker (1–5) + comment text field (max 2000 chars).
- [ ] Must specify either appointment_id or order_id (passed via navigation args).
- [ ] Submit → success message → button changes to "View Feedback."
- [ ] "My Feedback" list in More tab shows past submissions with staff replies.
- [ ] Staff reply displayed below the customer's feedback when present.

**Verify:** Unit test for ViewModel. Compose test for star rating interaction.

**Files:** `presentation/feedback/FeedbackScreen.kt`, `presentation/feedback/FeedbackViewModel.kt`, `presentation/feedback/FeedbackHistoryScreen.kt`, `presentation/feedback/FeedbackHistoryViewModel.kt`, `data/remote/api/FeedbackApiService.kt`

**Scope:** S

### Phase 6 Checkpoint

- [ ] Prescription list loads from API.
- [ ] Can send a message and see it in thread.
- [ ] Feedback submission works for completed appointments.

### Phase 7: Polish & Defense Prep

#### Task 20: Error Handling & Loading States

**Description:** Add consistent error handling, loading indicators, empty states, and connectivity awareness across all screens.

**Acceptance criteria:**
- [ ] All screens show a loading indicator during API calls.
- [ ] All screens show meaningful error messages with retry button on failure.
- [ ] Empty states ("No appointments yet", "No orders yet") with relevant action hints.
- [ ] Snackbar for transient errors (network timeout, generic 500).
- [ ] 401 globally redirects to login with a "Session expired" message.

**Verify:** Manual walkthrough of error scenarios. Airplane mode test.

**Files:** `presentation/common/ErrorState.kt`, `presentation/common/LoadingState.kt`, `presentation/common/EmptyState.kt`, all ViewModels (minor updates)

**Scope:** M

#### Task 21: Demo Hardening & Integration Test

**Description:** Run the full defense demo path end-to-end against the seeded backend, fix any issues, and ensure smooth transitions.

**Acceptance criteria:**
- [ ] Login with seeded customer account works.
- [ ] AR try-on launches and tracks in < 3 seconds.
- [ ] Order submitted from AR → visible in order list immediately.
- [ ] After admin confirms (separate), refresh shows updated status.
- [ ] Billing loads when available.
- [ ] Full demo path completes in < 10 minutes.
- [ ] No crashes or ANRs during the path.
- [ ] `./gradlew testDebugUnitTest` passes.

**Verify:** Full demo rehearsal recorded. Test suite green.

**Files:** Various bug fixes as needed.

**Scope:** M

### Final Checkpoint

- [ ] `./gradlew assembleDebug` succeeds.
- [ ] `./gradlew testDebugUnitTest` passes.
- [ ] `./gradlew ktlintCheck` passes.
- [ ] Full defense demo script completes without crashes.
- [ ] AR try-on is the opening "wow" moment and works reliably.

## Review Gate

This spec is ready for review. Approve before creating the Android repository and starting Task 1.
