# Spec: Android Accessory-Only Ordering with AR Frame Browsing

**Status:** Implemented and verified

## Objective

Restrict customer orders submitted through the Android API to active accessory products while continuing to let Android customers browse active frames that are ready for augmented-reality try-on.

This separates two product capabilities:

- **Visible in the Android catalog:** active accessories and active AR-capable frames.
- **Orderable by an Android customer:** active accessories only.

Filament staff/admin ordering and product management remain unchanged. Existing orders and their historical item snapshots remain readable regardless of product type.

### Definitions

- An **AR-ready variant** is active, has `ar_eligible = true`, and has a non-null `ar_asset_reference`.
- An **AR-capable frame** is an active `frame` product with at least one AR-ready variant.
- A **customer-orderable accessory** is an active `accessory` product with an active selected variant.

### API Contract

#### `GET /api/products`

- Returns active accessories with all active variants.
- Returns active AR-capable frames with only their active AR-ready variants.
- Excludes non-AR frames, contact lenses, optical lenses, legacy `general` products, and inactive products.
- Existing search, brand, category, price, stock, sorting, and pagination parameters continue to operate within this restricted catalog.
- The response shape remains unchanged.

#### `GET /api/products/{id}`

- Returns HTTP 200 for an active accessory.
- Returns HTTP 200 for an active AR-capable frame.
- For an AR-capable frame, returns only active AR-ready variants.
- Returns HTTP 404 for non-AR frames, contact lenses, optical lenses, legacy `general` products, and inactive products.
- The response shape remains unchanged.

#### `POST /api/orders`

- Accepts only active variants belonging to active `accessory` products.
- Requires `is_non_prescription` to be `true`.
- Accepts an omitted or null `appointment_id`, rejects any non-null customer-supplied value, and always creates the customer order without an appointment link.
- Rejects `items.*.lens_category_id` and the legacy `items.*.lens_type_id` alias when present.
- Uses the existing Laravel validation response format and returns HTTP 422 for violations.
- Rejects frame, contact-lens, optical-lens, legacy `general`, inactive-product, and inactive-variant line items through `items.*.product_variant_id`.
- Existing quantity, item count, authentication, and authorization rules remain unchanged.

#### Existing Orders

- `GET /api/orders` and `GET /api/orders/{id}` continue returning all of the customer's historical orders.
- Order responses retain nullable `appointment_id` for historical and staff-created appointment-linked orders.
- Existing frame and contact-lens order items are not modified, cancelled, hidden, or rejected when read.

## Tech Stack

- PHP 8.5
- Laravel 13
- Laravel Sanctum 4
- Pest 4
- MySQL through Laravel Sail

No dependency or database-schema changes are required.

## Commands

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan test --compact tests/Feature/Api/ProductCatalogTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/OrderRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php
vendor/bin/sail bin pint --dirty --format agent
```

## Project Structure

```text
app/Models/Product.php
    Product capability constants or query scopes.

app/Models/ProductVariant.php
    Reusable Android catalog variant eligibility scope.

app/Http/Controllers/Api/ProductController.php
    Android catalog list/detail visibility and variant loading.

app/Http/Requests/Api/StoreOrderRequest.php
    Customer accessory-only order validation.

tests/Feature/Api/ProductCatalogTest.php
    Catalog visibility, AR readiness, response variants, and detail access.

tests/Feature/Api/OrderRequestTest.php
    Accessory acceptance and non-accessory/lens-field rejection.

tests/Feature/ProductTypeTest.php
    Product capability definitions that are shared across application surfaces.

docs/BACKEND_CONTEXT.md
    Canonical Android API contract.

docs/specs/android-accessory-ordering-spec.md
    Feature requirements, implementation plan, and task checklist.
```

## Code Style

Use named product capabilities rather than changing the existing staff-facing allowlist or duplicating string arrays across controllers and requests.

```php
/** @var list<string> */
public const array CUSTOMER_ORDERABLE_TYPES = [
    'accessory',
];
```

Queries must express the business rule through Eloquent and use explicit relationship constraints. Request validation remains at the API boundary. All methods and closures use explicit parameter and return types where project conventions support them.

## Testing Strategy

Use Pest feature tests with `RefreshDatabase`.

### Catalog tests

- List includes an active accessory.
- List includes an active frame with an active AR-ready variant.
- List excludes:
  - a frame with no AR-eligible variant;
  - a frame whose AR variant is inactive;
  - a frame whose AR-eligible variant has no asset reference;
  - contact-lens and optical-lens products;
  - inactive products.
- AR frame responses contain only active AR-ready variants.
- Accessory responses continue containing all active variants.
- Detail returns 200 for an accessory and AR-capable frame.
- Detail returns 404 for every excluded product case.
- Existing filters, sorting, and pagination remain constrained to visible catalog products.

### Order tests

- An active accessory variant creates an order with HTTP 201.
- Frame, contact-lens, optical-lens, and legacy `general` variants return HTTP 422.
- Inactive accessory products and inactive accessory variants return HTTP 422.
- `is_non_prescription = false` returns HTTP 422.
- Either lens category field on an accessory item returns HTTP 422.
- Historical non-accessory orders remain visible through list and detail endpoints.

Tests must be written or updated before implementation and must demonstrate the previous behavior before the production code changes.

## Boundaries

### Always

- Keep Filament staff/admin product selectors and order creation behavior unchanged.
- Enforce accessory-only ordering on the server, independently of Android UI behavior.
- Preserve existing response shapes and historical order access.
- Use active-product and active-variant checks.
- Update `docs/BACKEND_CONTEXT.md` with the approved contract.
- Run focused tests and Pint through Sail.

### Ask First

- Any database migration or dependency change.
- Adding a new API field or endpoint.
- Changing Filament ordering behavior.
- Removing backward-compatible response fields from historical orders.
- Changing existing search, filter, sorting, or pagination parameter shapes.

### Never

- Change `Product::DIRECTLY_ORDERABLE_TYPES` to accessories only when that would also restrict Filament staff/admin ordering.
- Trust the Android client as the sole enforcement mechanism.
- Delete or rewrite historical orders because their product type is no longer customer-orderable.
- Expose AR frames whose qualifying variant is inactive or lacks an AR asset.
- Silently accept lens category pricing on accessory orders.

## Success Criteria

- Android catalog list and detail endpoints expose only active accessories and active AR-capable frames.
- Every frame variant returned to Android is active and AR-ready.
- Android customers can successfully order active accessories.
- Android customers cannot order any frame, contact lens, optical lens, legacy `general` product, inactive product, or inactive variant.
- Accessory orders require `is_non_prescription = true` and reject lens category fields.
- Customer orders reject non-null appointment IDs, persist no appointment link, and continue accepting omitted or null `appointment_id`.
- Filament staff/admin ordering continues supporting the existing directly-orderable product types.
- Existing order history, including `appointment_id`, remains readable without alteration.
- Focused Pest suites and Pint pass.
- The canonical backend API documentation matches the implemented behavior.

## Technical Implementation Plan

### Architecture Decisions

1. **Separate staff and customer orderability.**
   Keep `Product::DIRECTLY_ORDERABLE_TYPES` unchanged for Filament and introduce `Product::CUSTOMER_ORDERABLE_TYPES = ['accessory']` for the customer API. This avoids coupling an Android policy change to staff workflows.

2. **Model catalog eligibility as reusable Eloquent scopes.**
   Add a product scope for the mobile catalog and a product-variant scope for variants eligible to appear in that catalog. The variant scope will include:
   - every active variant of an active accessory; and
   - active AR-ready variants of active frames.

   The product scope will include:
   - active accessories; and
   - active frames having at least one eligible AR-ready variant.

   Controllers and request classes will consume these named capabilities instead of repeating product-type arrays and AR conditions.

3. **Use the same eligible-variant scope throughout catalog queries.**
   Apply it consistently to:
   - eager-loaded response variants;
   - `min_price` and `max_price` filters;
   - the `in_stock` filter; and
   - minimum-price sorting.

   This ensures a hidden, inactive, or non-AR frame variant cannot make a product match a filter or determine its sort position.

4. **Keep response schemas stable.**
   Do not add an orderability flag or change resource field names. Android can distinguish browse-only frames from accessories through the existing `product_type` field. Server validation remains authoritative even if a client submits a frame variant directly.

5. **Validate accessory ordering at the request boundary.**
   Update `StoreOrderRequest` so the selected variant must belong to an active accessory product, `is_non_prescription` must be true, both lens category fields are prohibited, and a non-null customer-supplied `appointment_id` is prohibited. The controller explicitly persists `appointment_id = null` for customer-created orders.

6. **Preserve historical reads and staff workflows.**
   Do not change order resources, existing order queries, Filament selectors, database rows, or migrations. Add regression coverage proving staff-facing type capabilities remain unchanged and historical non-accessory or appointment-linked orders remain readable.

### Dependency Graph

```text
Approved API contract
    |
    +-- Product customer-orderable capability
    |
    +-- Product/variant mobile catalog scopes
            |
            +-- Catalog list/detail visibility
            +-- Catalog variant loading
            +-- Catalog price/stock filters and sorting
    |
    +-- Customer order request validation
            |
            +-- Accessory order creation
            +-- Non-accessory and lens-field rejection
    |
    +-- Canonical API documentation and final verification
```

### Implementation Sequence

#### Phase A: Regression Contract

1. Update product capability tests to define staff-orderable types separately from customer-orderable types.
2. Update catalog feature tests to cover accessory visibility, AR-frame eligibility, mixed frame variants, excluded product types, detail access, and eligible-variant filtering/sorting.
3. Update order request tests so accessories succeed while every non-accessory type, `is_non_prescription = false`, and either lens category field fail.
4. Add historical-order read coverage for an existing non-accessory order.

**Checkpoint A:** Run the focused tests before production changes and confirm they fail only for the newly specified behavior.

#### Phase B: Product Capabilities and Catalog

1. Add the customer-orderable product constant without modifying the existing staff-facing constant.
2. Add reusable product and product-variant mobile catalog scopes.
3. Update product list and detail queries to use the product visibility scope.
4. Use the eligible-variant scope for response loading, price/stock filters, and price sorting.

**Checkpoint B:** Product type and catalog suites pass. Review generated SQL behavior through tests rather than adding a verification script.

#### Phase C: Customer Order Enforcement

1. Restrict `product_variant_id` validation to the customer-orderable accessory capability.
2. Require a true non-prescription flag.
3. Prohibit both current and legacy lens category fields.

**Checkpoint C:** Order request tests pass, including accessory creation, validation errors, and historical order reads.

#### Phase D: Contract Synchronization and Quality Gate

1. Update `docs/BACKEND_CONTEXT.md` to describe accessories as customer-orderable and AR frames as browse-only.
2. Remove outdated statements and examples that say Android can order frames or contact lenses.
3. Run Pint and the focused product/order suites.
4. Review the final diff for correctness, simplicity, security, query performance, and unintended Filament changes.

**Checkpoint D:** All success criteria are satisfied and the change is ready for final review.

### Verification Checkpoints

```bash
# Product capability and catalog checkpoint
vendor/bin/sail artisan test --compact \
  tests/Feature/ProductTypeTest.php \
  tests/Feature/Api/ProductCatalogTest.php

# Customer order checkpoint
vendor/bin/sail artisan test --compact \
  tests/Feature/Api/OrderRequestTest.php

# Formatting
vendor/bin/sail bin pint --dirty --format agent

# Final focused regression set
vendor/bin/sail artisan test --compact \
  tests/Feature/ProductTypeTest.php \
  tests/Feature/Api/ProductCatalogTest.php \
  tests/Feature/Api/OrderRequestTest.php \
  tests/Feature/Filament/OrderResourceTest.php
```

### Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Reusing the staff allowlist for Android restrictions | High — staff could lose frame/contact-lens ordering | Introduce a separate customer constant and retain Filament regression coverage |
| Non-AR or inactive frame variants leak into responses | High — Android AR flow receives unusable assets | Centralize eligible-variant conditions and use them for eager loading |
| Hidden variants affect price/stock filters or sorting | Medium — catalog results contradict returned variants | Reuse the eligible-variant scope in every variant-based catalog operation |
| Boolean validation accepts a false-like value | Medium — accessory order violates non-prescription policy | Use Laravel's strictest project-compatible true-value rule and test JSON `false` and `true` |
| Lens pricing remains attachable to accessories | Medium — incorrect order totals | Prohibit both lens category field names at request validation |
| New catalog predicates add unnecessary query cost | Low/Medium — slower product browsing | Preserve pagination/eager loading, use `whereHas`/subqueries without N+1 queries, and inspect query structure during review |
| Existing non-accessory orders become inaccessible | High — historical data regression | Leave order read queries/resources untouched and add explicit regression tests |

### Parallelization

The change is small and shares the same model/query contracts, so sequential implementation is safer. Documentation can be updated independently after the API behavior is green, but no multi-agent split is necessary.

## Task Checklist

### Task 1: Define Customer Product Capabilities

**Description:** Establish model-level vocabulary for customer orderability and mobile catalog eligibility without changing the existing staff-facing directly-orderable types. Add the tests first, confirm the new expectations fail, and then implement the minimum constants and scopes required.

**Acceptance criteria:**

- [x] `Product::DIRECTLY_ORDERABLE_TYPES` remains `frame`, `contact_lens`, and `accessory`, while the customer-orderable capability contains only `accessory`.
- [x] The product catalog scope includes active accessories and active frames with an AR-ready variant, while excluding all other products.
- [x] The variant catalog scope includes active accessory variants and active AR-ready frame variants only.

**Verification:**

- [x] Confirm the new capability/scope tests fail before production changes.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php`.

**Dependencies:** None.

**Files likely touched:**

- `app/Models/Product.php`
- `app/Models/ProductVariant.php`
- `tests/Feature/ProductTypeTest.php`

**Estimated scope:** Medium — 3 files.

### Task 2: Apply the Restricted Android Catalog Contract

**Description:** Update product list and detail queries to use the mobile catalog scopes consistently for visibility, returned variants, price filters, stock filtering, and price sorting. Write the catalog regressions first and preserve the existing response schema.

**Acceptance criteria:**

- [x] List and detail return active accessories and active AR-capable frames, with 404/exclusion behavior for every other product case.
- [x] Accessories return all active variants, while frames return only active AR-ready variants.
- [x] Price, stock, and sorting behavior considers only variants eligible to appear in the Android response.

**Verification:**

- [x] Confirm the new catalog tests fail before controller changes.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php tests/Feature/Api/ProductCatalogTest.php`.

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Http/Controllers/Api/ProductController.php`
- `tests/Feature/Api/ProductCatalogTest.php`

**Estimated scope:** Small — 2 files.

### Checkpoint: Catalog Contract

- [x] Product capability and catalog suites pass.
- [x] No response fields or pagination metadata changed.
- [x] No inactive, non-AR frame variant, contact lens, or optical lens leaks into catalog responses.

### Task 3: Enforce Accessory-Only Customer Orders

**Description:** Restrict customer order submissions at the form-request boundary. Update existing order-request fixtures that currently create customer frame/contact-lens orders, add rejection coverage first, and leave staff creation and historical reads unchanged.

**Acceptance criteria:**

- [x] An active accessory variant with `is_non_prescription = true` can create a requested order.
- [x] Every non-accessory, inactive-product, or inactive-variant selection and `is_non_prescription = false` returns HTTP 422.
- [x] `lens_category_id` and `lens_type_id` are prohibited, while historical non-accessory orders remain readable.
- [x] A non-null customer-supplied `appointment_id` returns HTTP 422; omitted or null values create an unlinked order.
- [x] Historical/staff-created appointment links remain present in order responses.

**Verification:**

- [x] Confirm the new validation tests fail before request-rule changes.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/OrderRequestTest.php`.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/OrderResourceTest.php`.

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Http/Requests/Api/StoreOrderRequest.php`
- `tests/Feature/Api/OrderRequestTest.php`

**Estimated scope:** Small — 2 files.

### Task 4: Synchronize the Canonical API Contract

**Description:** Update the backend context after behavior is green so Android developers receive an accurate catalog and ordering contract. Remove contradictory claims and examples without rewriting unrelated historical specifications.

**Acceptance criteria:**

- [x] `docs/BACKEND_CONTEXT.md` states that Android can browse accessories and AR-ready frames but can order accessories only.
- [x] Product and order examples no longer imply that customers can submit frame, contact-lens, or lens-category line items.
- [x] The specification status and checklist reflect completed implementation and verification.

**Verification:**

- [x] Search canonical documentation for contradictory mobile orderability statements.
- [x] Run the final focused regression checkpoint below.

**Dependencies:** Tasks 2 and 3.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/android-accessory-ordering-spec.md`

**Estimated scope:** Small — 2 files.

### Checkpoint: Complete

- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php tests/Feature/Api/ProductCatalogTest.php tests/Feature/Api/OrderRequestTest.php tests/Feature/Filament/OrderResourceTest.php`.
- [x] Review tests first, then implementation, across correctness, readability, architecture, security, and performance.
- [x] Confirm the unrelated pre-existing worktree changes remain intact.
- [x] Confirm every success criterion in this specification is satisfied.

## Open Questions

None. Changes to the approved rules above require updating this specification before implementation.
