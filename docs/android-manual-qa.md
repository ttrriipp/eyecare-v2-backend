# Android Manual QA Checklist

> Test against: `http://localhost/api` (or your dev server URL)
> Demo customer account: `customer@eyecare.test` / `password`

---

## Auth

- [ ] Register new account → lands on home screen, token persisted
- [ ] Login with `customer@eyecare.test` → success
- [ ] Login with wrong password → error shown
- [ ] Logout → redirected to login, token cleared
- [ ] Restart app after login → stays logged in (token persists)
- [ ] Make any authenticated request after logout → 401, redirected to login

---

## Appointments

- [ ] Appointment list loads and shows status, date, visit reason
- [ ] Tap appointment → detail screen shows all fields
- [ ] Book new appointment → appears in list with status `pending`
- [ ] Book appointment at conflicting time (within 30 min of existing) → 422 error shown
- [ ] Appointment with status `confirmed` shows correct label
- [ ] Appointment with status `cancelled` shows correct label, no booking actions
- [ ] Cancel own pending/confirmed appointment → status updates to `cancelled`
- [ ] Cancel completed/cancelled appointment → error shown

---

## Products (Frames)

- [ ] Product list loads with pagination (scroll to load more)
- [ ] Product card shows name, image (or placeholder if no image)
- [ ] Tap product → detail screen shows variants
- [ ] Variant with images shows variant image; variant without images falls back to product image
- [ ] Variant with `ar_eligible: true` shows AR option
- [ ] AR asset loads from `{APP_URL}/storage/{ar_asset_reference}`
- [ ] Selecting a variant updates displayed price and attributes

---

## Prescriptions

- [ ] Prescription list loads
- [ ] Tap prescription → detail shows OD, OS, PD fields, prescribed date, expiry date
- [ ] Upload prescription image → status shows `pending`
- [ ] Upload prescription PDF → status shows `pending`
- [ ] Upload file > 5MB → error shown
- [ ] Prescription upload list loads with status (pending/approved/rejected)

---

## Profile

- [ ] View profile → shows name, email, phone
- [ ] Update name → saved and displayed
- [ ] Update email to already-taken address → validation error shown
- [ ] Update own email to same value → succeeds
- [ ] Submit empty PATCH /user → validation error shown

---

## Orders

- [ ] Order list loads with pagination
- [ ] Tap order → detail shows items, status, totals
- [ ] Submit new order → appears in list with status `requested`
- [ ] Order item with lens type shows lens type name
- [ ] Order with `is_non_prescription: true` submits without prescription
- [ ] Cancel own `requested` order → status updates to `cancelled`
- [ ] Cancel `confirmed` order → 422 error shown (customer cannot cancel)
- [ ] Order status `cancelled` shows correct label

---

## Billing

- [ ] Open billing from an order → billing detail loads
- [ ] Billing shows: billing number, status, total, amount paid, balance due
- [ ] Line items show: type (product/service), description, qty, unit price, amount
- [ ] Payments section shows each payment with method, amount, date
- [ ] Billing with status `paid` shows zero balance due
- [ ] Attempting to load another customer's billing → 403 error handled gracefully

---

## Messaging

- [ ] Conversation screen loads message history
- [ ] Unread message count shown on conversation (from staff messages)
- [ ] Opening conversation marks messages as read → unread count drops to 0
- [ ] Send a text message → appears immediately in chat
- [ ] Send message with image attachment → attachment visible in chat
- [ ] Tap attachment → opens/downloads correctly
- [ ] Messages from staff visible with staff name
- [ ] Context link to an appointment/order shown as tappable reference

---

## Feedback

- [ ] Submit feedback on a completed appointment (rating + comment) → success
- [ ] Submit feedback on a completed order → success
- [ ] Submit feedback on a non-completed appointment → 422 error shown
- [ ] Submit feedback without rating → validation error shown
- [ ] Feedback history list loads
- [ ] Tap feedback → detail shows rating, comment, linked appointment/order

---

## Edge Cases

- [ ] No internet → error message shown, not a crash
- [ ] Empty states: no appointments, no orders, no prescriptions → empty state UI shown
- [ ] Very long product name / comment → text truncates or wraps correctly, no overflow crash
- [ ] Session expired (token invalid) → redirected to login gracefully
