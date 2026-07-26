# Post-Implementation Test Recovery Map

## Status

Frozen by reconciliation Task 1 on 2026-07-26. This map compares deleted test
files between baseline commit `a0cf085` and the approved reconciliation branch.
The current 400-test, zero-failure run is a baseline only; it is not release
evidence until every retained row below has passing equal-or-stronger coverage.

## Classification Rules

- **Obsolete:** every assertion belongs exclusively to a removed capability or
  an endpoint explicitly excluded by the approved specification.
- **Replaced:** retained assertions already have an equal-or-stronger named
  canonical test; obsolete assertions from the old file are not restored.
- **Restore required:** at least one retained assertion is absent, weaker, or
  not yet proven by a named replacement. Mixed legacy/canonical files use this
  classification and restore only their retained assertions.

## Inventory

| Deleted test file | Classification | Replacement or recovery target |
|---|---|---|
| `tests/Feature/AddOrderItemsToBillingTest.php` | Obsolete | Legacy Billing/Order item generation is removed. |
| `tests/Feature/Api/AppointmentAvailabilityTest.php` | Restore required | Appointment Type duration, capacity, closures, early closing, overlap, query-bound, and reschedule-exclusion coverage in Tasks 8–12. |
| `tests/Feature/Api/AppointmentBookingTest.php` | Restore required | Patient-owned booking, validation, concurrency, and cancellation-safe slot coverage in Tasks 9–12. |
| `tests/Feature/Api/AppointmentCancelTest.php` | Restore required | Owned lifecycle, terminal-state, isolation, and notification coverage in Tasks 3 and 12. |
| `tests/Feature/Api/AppointmentContactNoteTest.php` | Obsolete | The patient contact-note mutation is excluded from the approved API. |
| `tests/Feature/Api/AppointmentRescheduleTest.php` | Restore required | Duration snapshot, reason, concurrency, isolation, audit, and notification coverage in Tasks 9–12. |
| `tests/Feature/Api/AuthTest.php` | Restore required | Extend `tests/Feature/Api/V1/AuthContractTest.php` for logout, invalid credentials, duplicate email, and complete `/me` behavior in Task 23. |
| `tests/Feature/Api/FeedbackTest.php` | Restore required | Restore private appointment Feedback validation/isolation in Tasks 7 and 26; Order and feedback list/show assertions are obsolete. |
| `tests/Feature/Api/MessageAttachmentTest.php` | Restore required | Restore private storage, upload validation, membership, download, and isolation in Tasks 6 and 25. |
| `tests/Feature/Api/MessagingTest.php` | Restore required | Restore single Conversation ownership, messaging, unread, and canonical contexts in Tasks 6 and 25; Order context and old mark-read routes are obsolete. |
| `tests/Feature/Api/NotificationApiTest.php` | Obsolete | A mobile notification inbox is explicitly deferred. |
| `tests/Feature/Api/OrderCancelTest.php` | Obsolete | Patients do not create or cancel Orders. |
| `tests/Feature/Api/OrderProcessingTest.php` | Obsolete | Legacy Order processing is replaced by clinic-created Job Orders. |
| `tests/Feature/Api/OrderRequestTest.php` | Obsolete | Direct accessory/frame ordering and Order reads are removed. |
| `tests/Feature/Api/PatientIntakeTest.php` | Restore required | Appointment-nested draft, resume, submit, verification, immutability, and isolation coverage in Tasks 14 and 24. |
| `tests/Feature/Api/PatientProfileTest.php` | Restore required | Registration transaction and linked Patient representation move to `/me` in Task 23. |
| `tests/Feature/Api/PrescriptionTest.php` | Restore required | Extend `tests/Feature/Api/V1/WorkflowReadsTest.php` for own detail and authentication in Tasks 23 and 28. |
| `tests/Feature/Api/ProductCatalogTest.php` | Replaced | Frame-only behavior is covered by `FrameCatalogTest.php` and `CatalogTaxonomyTest.php`; accessory/direct-order assertions are obsolete. |
| `tests/Feature/Api/ProfileUpdateTest.php` | Restore required | Move name, email, phone, address, uniqueness, and empty-payload assertions to `/api/v1/me` in Task 23. |
| `tests/Feature/Api/RateLimitTest.php` | Restore required | Restore login, patient read, and mutation throttling against the exact V1 routes in Task 28. |
| `tests/Feature/Api/StaffAppointmentTest.php` | Restore required | Restore retained lifecycle/SMS behavior through Filament/action tests in Tasks 3 and 13; the patient-group staff route is obsolete. |
| `tests/Feature/Api/TokenExpirationTest.php` | Restore required | Restore expired/fresh Sanctum token assertions in Task 23. |
| `tests/Feature/AppointmentReminderTest.php` | Restore required | Restore reminder scheduling, selection, idempotency, and account-resolved recipient coverage in Task 3. |
| `tests/Feature/AppointmentSmsMessageTest.php` | Restore required | Restore Appointment message content, event exclusions, recipient fallback, and HTTP isolation in Task 3. |
| `tests/Feature/AppointmentStaffAuditTest.php` | Restore required | Restore booking/check-in actors, source, optometrist presentation, and hidden internal IDs in Tasks 2 and 13. |
| `tests/Feature/AuditLogRecordingTest.php` | Restore required | Restore retained Appointment, inventory, Feedback, product, and user audit assertions; Order/Billing/Payment assertions are obsolete. |
| `tests/Feature/Billing/BillingGenerationTest.php` | Obsolete | Legacy Billing generation is removed; canonical finance is covered independently by Invoice tests. |
| `tests/Feature/Billing/PaymentTest.php` | Obsolete | Legacy Payment is removed; canonical payment behavior belongs to `PaymentLifecycleTest.php`. |
| `tests/Feature/BillingReceiptTest.php` | Obsolete | Billing receipts are replaced by canonical Invoice printing in Task 4. |
| `tests/Feature/BulkActionsTest.php` | Restore required | Restore Appointment and SMS bulk behavior where still supported; Order bulk behavior is obsolete. |
| `tests/Feature/CatalogSchemaTest.php` | Restore required | Restore retained product/variant/lens schema and factory assertions; Order item and mobile accessory assertions are obsolete. |
| `tests/Feature/DailySummaryTest.php` | Restore required | Rebuild summary assertions around Job Orders and posted Invoice Payments in Task 5. |
| `tests/Feature/DemoAccountsSeedTest.php` | Restore required | Prove two optometrist-capable users, receptionist, linked Patient, and panel access in Tasks 5 and 37. |
| `tests/Feature/DemoWorkflowSeedTest.php` | Restore required | Replace Order/Billing fixtures with canonical scheduled and walk-in journeys in Tasks 5 and 37. |
| `tests/Feature/DiscountTypeTest.php` | Obsolete | The legacy discount lookup domain is removed. |
| `tests/Feature/Filament/AppointmentResourceTest.php` | Restore required | Restore retained list/search/filter, lifecycle, scheduling, walk-in, SMS, and validation behavior in Task 13; Billing relations are obsolete. |
| `tests/Feature/Filament/BillingResourceTest.php` | Obsolete | The Billing resource is removed; Invoice UI has its own canonical coverage. |
| `tests/Feature/Filament/CalendarInteractivityTest.php` | Restore required | Restore duration-aware conflicts, safe rescheduling, and calendar actions using Appointment snapshots in Tasks 9, 11, and 13. |
| `tests/Feature/Filament/CatalogResourceTest.php` | Restore required | Restore retained product, variant, image, lens-category, and stock-target staff behavior. |
| `tests/Feature/Filament/ConversationResourceTest.php` | Restore required | Restore staff chat, private attachments, canonical context cards, and replies in Task 6; Order cards are obsolete. |
| `tests/Feature/Filament/CreateBillingTest.php` | Obsolete | Standalone Billing creation is removed. |
| `tests/Feature/Filament/DatabaseNotificationTest.php` | Restore required | Restore Appointment notification behavior; Order notification assertions are obsolete. |
| `tests/Feature/Filament/FeedbackResourceTest.php` | Restore required | Restore private Feedback list/view staff authorization in Task 7. |
| `tests/Feature/Filament/GlobalSearchTest.php` | Restore required | Restore Patient, Appointment, Product, and prescription/workflow search where supported; Order search is obsolete. |
| `tests/Feature/Filament/InlinePaymentTest.php` | Obsolete | Billing collection is removed; Invoice Payment actions use canonical tests. |
| `tests/Feature/Filament/OrderCreationTest.php` | Obsolete | Staff create Job Orders only from accepted Quotations. |
| `tests/Feature/Filament/OrderResourceTest.php` | Obsolete | The legacy Order resource and transitions are removed. |
| `tests/Feature/Filament/PatientResourceTest.php` | Restore required | Restore Patient CRUD, authorization, walk-in, no-visit filtering, and canonical visit summaries in Task 2. |
| `tests/Feature/Filament/PrescriptionPrintTest.php` | Restore required | Restore private Patient-owned prescription PDF and encrypted/CX output in Task 4. |
| `tests/Feature/Filament/PrescriptionResourceTest.php` | Restore required | Restore Patient/Encounter ownership, walk-in creation, editing, validation, and authorization. |
| `tests/Feature/Filament/ReportsTest.php` | Restore required | Restore retained appointment/reorder reports and rebuild finance reporting from canonical Invoices/Payments in Task 5. |
| `tests/Feature/FilamentSoftDeleteActionVisibilityTest.php` | Restore required | Restore retained Appointment, Product, Prescription, Patient, Job Order, and Invoice archive/restore UI; Order assertions are obsolete. |
| `tests/Feature/GetOrCreateBillingTest.php` | Obsolete | The Billing aggregate and reuse rules are removed. |
| `tests/Feature/Inventory/InventoryMovementTest.php` | Replaced | Canonical attribution/idempotency is covered by `InventoryLedgerTest.php` and `JobOrderInventoryAtomicTest.php`; Order-trigger assertions are obsolete. |
| `tests/Feature/InventoryManagementTest.php` | Restore required | Restore restock, threshold warning, low-stock notification, actor history, and archive/restore behavior. |
| `tests/Feature/LensCategoryPricingTest.php` | Restore required | Retain Lens Category pricing/schema behavior; all customer Order request assertions are obsolete. |
| `tests/Feature/Notifications/CustomerNotificationTest.php` | Restore required | Restore patient Appointment notifications and replace Order/Billing events with approved Job Order/Invoice events. |
| `tests/Feature/Notifications/PatientNotificationTest.php` | Restore required | Retain safe delivery payload/authentication assertions; notification-inbox listing is obsolete. |
| `tests/Feature/Notifications/StaffNotificationTest.php` | Restore required | Restore Appointment and Conversation staff delivery; customer Order submission is obsolete. |
| `tests/Feature/Patients/PatientAuthorizationTest.php` | Restore required | Restore `/me` ownership, update validation, authentication, and cross-patient isolation in Task 23. |
| `tests/Feature/PrescriptionExpiryAlertTest.php` | Restore required | Restore expiry selection, idempotency, notification, and timestamp behavior. |
| `tests/Feature/ProductSearchTest.php` | Replaced | Frame search/pagination is covered by `FrameCatalogTest.php`; `/products` and its accessory filters are excluded. |
| `tests/Feature/ProductTypeTest.php` | Restore required | Retain staff catalog type/factory and legacy-general guard assertions; direct/mobile ordering constants are obsolete. |
| `tests/Feature/RolePermissionsTest.php` | Restore required | Restore admin/staff/optometrist capability boundaries against canonical resources; Order/Billing permissions are obsolete. |
| `tests/Feature/ServiceBillingActionsTest.php` | Obsolete | Services, Service Records, and Billing actions are removed. |
| `tests/Feature/StatusCatalogTest.php` | Restore required | Retain Appointment and SMS statuses; Order, Billing, and legacy Payment status assertions are obsolete. |
| `tests/Feature/UpdateOrderStatusTest.php` | Obsolete | Legacy Order status transitions, SMS, inventory, and Billing generation are removed. |

## Counts and Gate

| Classification | Files |
|---|---:|
| Obsolete | 18 |
| Replaced | 3 |
| Restore required | 46 |
| **Total deleted files** | **67** |

- [x] Every file returned by
      `git diff --diff-filter=D --name-only a0cf085..HEAD -- tests` appears
      exactly once.
- [x] Mixed files identify which legacy assertions remain obsolete.
- [ ] Every restore-required row points to passing equal-or-stronger coverage.
- [ ] The full retained-behavior recovery gate is complete.

The last two gates remain open for reconciliation Tasks 2–39.
