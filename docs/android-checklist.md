# Android Implementation Checklist

> Cross-reference with `docs/BACKEND_CONTEXT.md`. All API base: `/api`

---

## Auth

- [ ] `POST /register` — register with name, email, password → store token + user
- [ ] `POST /login` — login → store token + user
- [ ] `POST /logout` — clear token + local state
- [ ] `GET /user` — fetch authenticated user profile
- [ ] Token stored securely (e.g. EncryptedSharedPreferences)
- [ ] Unauthenticated 401 responses redirect to login screen

---

## Appointments

- [ ] `GET /appointments` — list customer's appointments (status, date, visit reason, source, assigned optometrist)
- [ ] `POST /appointments` — book appointment with `visit_reason_id`, `scheduled_at`, optional `contact_notes`
- [ ] `GET /appointments/{id}` — view single appointment detail
- [ ] Visit reasons loaded dynamically from `GET /visit-reasons` *(not yet implemented — hardcode or skip until available)*
- [ ] Status displayed as readable label (pending, confirmed, rescheduled, cancelled, completed)
- [ ] Appointment with status `completed` or `cancelled` shows as terminal (no actions)

---

## Products (Frames only)

- [ ] `GET /products` — paginated frame catalog (`?per_page=N`, default 15)
- [ ] `GET /products/{id}` — product detail with variants and AR metadata
- [ ] Only `product_type = 'frame'` products exist in API (backend 404s other types)
- [ ] Display variant images if present, fall back to product images
- [ ] AR: `ar_eligible` flag on variant — if true, `ar_asset_reference` is the storage path
- [ ] AR asset URL: `{APP_URL}/storage/{ar_asset_reference}`
- [ ] Pagination: use `meta.current_page`, `meta.last_page`, `links.next` for infinite scroll / load more

---

## Prescriptions

- [ ] `GET /prescriptions` — customer's prescription history
- [ ] `GET /prescriptions/{id}` — single prescription detail
- [ ] Display OD/OS fields (sphere, cylinder, axis, add), PD, prescribed_at, expires_at

---

## Orders

- [ ] `POST /orders` — submit order request
  - [ ] `is_non_prescription` (bool)
  - [ ] Optional `appointment_id`
  - [ ] `items[]` with `product_variant_id`, `quantity`, nullable `lens_type_id`
- [ ] `GET /orders` — paginated customer orders (default 15, `?per_page=N`)
- [ ] `GET /orders/{id}` — order detail with items
- [ ] Display status: requested, confirmed, processing, ready_for_pickup, completed, cancelled
- [ ] Order items show: product name, variant name, unit price, quantity, subtotal
- [ ] Items with `lens_type_id` show lens type name and price — staff assigns lens variant later
- [ ] Link to billing from order if applicable (`GET /billing/{id}`)

---

## Billing

- [ ] `GET /billing/{id}` — customer's billing detail (only own billings — 403 otherwise)
- [ ] Display: billing number, status, total, amount paid, balance due, issued at
- [ ] Display line items: type (product/service), description, qty, unit price, amount
- [ ] Display payments: method, amount, date, status
- [ ] Billing statuses: issued, partially_paid, paid, voided

---

## Messaging (Conversations)

- [ ] `GET /conversations` — customer's single persistent conversation
- [ ] `GET /conversations/{id}/messages` — message list
- [ ] `POST /conversations/{id}/messages` — send message
  - [ ] Optional `contexts[]` array (type + id for Appointment, Order, or Product links)
  - [ ] Optional `attachments[]` (images or PDFs)
- [ ] `GET /attachments/{id}` — download attachment (authenticated)
- [ ] Messages show sender name and timestamp
- [ ] Context links displayed as tappable references to the linked entity

---

## Error Handling

- [ ] 401 — redirect to login
- [ ] 403 — show "not authorized" message
- [ ] 404 — show "not found" message
- [ ] 422 — display validation errors inline on forms
- [ ] Network errors — show retry option

---

## Notes

- No API versioning prefix — all routes are `/api/...`
- Sanctum token auth: `Authorization: Bearer {token}` header on all authenticated requests
- Walk-in customers (no email/password) cannot use the mobile app — login will fail
- Products endpoint only returns frames — do not attempt to fetch lens/contact_lens/accessory by ID
