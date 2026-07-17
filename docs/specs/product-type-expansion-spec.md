# Spec: Product Type Expansion

Status: Phases 1-2 approved - awaiting Phase 3 approval
Phase: Tasks

## Objective

Replace the broad `general` product type with two explicit product types so clinic staff and API consumers can distinguish contact lenses from accessories without relying on product category names.

The fixed product types will be:

- `frame` - eyewear frames; supports AR-specific variant fields
- `lens` - optical lenses assigned by staff to frame order items; never directly orderable
- `contact_lens` - directly orderable contact lenses
- `accessory` - directly orderable solutions, cases, cleaning kits, and other optical accessories

**Users:** Clinic staff and administrators managing products in Filament, and authenticated customers consuming the mobile catalog and order APIs.

**API contract change:** `general` is removed from newly returned and accepted product data. The mobile catalog and order APIs expose `frame`, `contact_lens`, and `accessory`; `lens` remains excluded from direct ordering.

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
Create migration: vendor/bin/sail artisan make:migration replace_general_product_type --no-interaction
Run migration: vendor/bin/sail artisan migrate --no-interaction
Focused tests: vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php tests/Feature/CatalogSchemaTest.php tests/Feature/Api/ProductCatalogTest.php tests/Feature/Api/OrderRequestTest.php tests/Feature/Filament/OrderResourceTest.php
Format PHP: vendor/bin/sail bin pint --dirty --format agent
Full tests: vendor/bin/sail artisan test --compact
```

## Project Structure

```text
app/Filament/Resources/Products/       -> Product type selection, badges, and filters
app/Filament/Resources/Brands/         -> Product type display in brand relationships
app/Filament/Resources/ProductCategories/ -> Product type display in category relationships
app/Filament/Resources/Orders/         -> Directly orderable product selectors
app/Http/Controllers/Api/              -> Mobile catalog visibility
app/Http/Requests/Api/                 -> Mobile order item validation
app/Models/Product.php                 -> Product model and type semantics
database/factories/                    -> Product factory states
database/migrations/                   -> Forward-only product data migration
database/seeders/                      -> Catalog demo data
tests/Feature/                         -> Schema, API, and Filament behavior tests
docs/BACKEND_CONTEXT.md                -> Canonical backend contract
docs/specs/                            -> Feature specifications and product model rationale
```

## Functional Requirements

### Product Management

- The Filament product create form offers exactly Frame, Lens, Contact Lens, and Accessory.
- Product type remains disabled after creation.
- Lens Category is visible and applicable only when the product type is `lens`.
- Frame-only AR fields remain visible only for `frame` products.
- Product tables, filters, brand relationships, and category relationships display the four types with readable labels.
- No UI offers `general` as a product type.

### Catalog API

- `GET /api/products` returns active `frame`, `contact_lens`, and `accessory` products.
- `GET /api/products` excludes `lens` products.
- `GET /api/products/{product}` returns active `frame`, `contact_lens`, and `accessory` products.
- Product detail requests for `lens` products return 404.
- API resources return the stored snake-case value in `product_type`.
- Existing brand, category, stock, price, search, sorting, and pagination behavior remains unchanged.

### Ordering

- Filament order selectors allow active variants belonging to `frame`, `contact_lens`, and `accessory` products.
- Mobile order validation accepts active variants belonging to `frame`, `contact_lens`, and `accessory` products.
- Direct order lines using a `lens` product variant remain rejected.
- Prescription and lens-assignment rules for frame orders remain unchanged.
- Contact lenses are ordinary direct order items and do not use `lens_category_id` or `lens_product_variant_id` assignment behavior.

### Existing Data

- The current inspected database contains no `general` products, so the expected migration performs no local data update.
- The previous consolidation migration destroyed the original contact-lens/accessory distinction. Therefore, an environment containing `general` rows must classify those rows explicitly before this change is deployed.
- The implementation must not infer product type from free-form names, descriptions, or attributes.
- The implementation must not silently map every `general` row to `accessory`.
- The database column remains a string; this feature adds no enum column or check constraint.

### Compatibility

- This is an intentional breaking API contract change for clients that only recognize `general`.
- Android implementation and release coordination are outside this backend specification.
- The backend will not emit a legacy `general` alias or duplicate compatibility field.

## Code Style

Follow existing Laravel and Filament conventions: explicit return types, descriptive method names, array validation rules, static `make()` component construction, and singular snake-case stored values.

Factory states should follow the existing state pattern:

```php
public function contactLens(): static
{
    return $this->state(fn (array $attributes): array => [
        'product_type' => 'contact_lens',
    ]);
}
```

Use exact allowlists wherever behavior is security- or workflow-sensitive:

```php
->whereIn('product_type', ['frame', 'contact_lens', 'accessory'])
```

Do not treat every non-`lens` value as orderable; explicit allowlists prevent malformed or legacy values from entering orders.

## Testing Strategy

- Use Pest feature tests and `RefreshDatabase`, following existing test conventions.
- Update factory coverage so `contactLens()` and `accessory()` produce the correct stored values and remove the `general()` state.
- Test catalog list and detail visibility for all four product types.
- Test mobile order validation accepts contact lenses and accessories while rejecting optical lenses.
- Test each Filament order selector includes contact lenses and accessories and excludes lenses.
- Test Filament product creation supports both new types and validation/display behavior remains correct.
- Update schema-level assertions that currently expect `general`.
- Run the focused test set first, then the full suite.
- Since this phase changes documentation only, no application test run is required until implementation begins.

## Boundaries

### Always

- Use a new forward migration; do not rewrite previously executed migrations.
- Keep `lens` staff-assigned and excluded from direct ordering.
- Preserve existing product variant pricing, stock, attributes, images, and AR behavior.
- Preserve unrelated worktree changes, including the existing modification to `ProductForm.php`.
- Update `docs/BACKEND_CONTEXT.md` and the existing product data structure specification during implementation.
- Add or update focused Pest tests for every changed behavior.
- Run Pint after modifying PHP files.

### Ask First

- Proceeding with deployment if any target environment contains `general` products that have not been explicitly classified.
- Adding a PHP enum, database constraint, dependency, or new API endpoint.
- Expanding the scope to Android client changes.
- Making product type editable after creation.

### Never

- Guess whether an existing `general` product is a contact lens or accessory.
- Return or accept `lens` as a directly orderable catalog item.
- Reuse `lens_category_id` for contact lenses.
- Delete tests to accommodate the new behavior.
- Revert unrelated user changes in the worktree.

## Success Criteria

- The backend recognizes exactly `frame`, `lens`, `contact_lens`, and `accessory` in all product-management UI.
- No application code, factory, seeder, active documentation, or test uses `general` as a supported product type, except historical migration commentary or an explicit legacy-data guard.
- Catalog endpoints return frames, contact lenses, and accessories and continue to hide optical lenses.
- Filament and mobile order creation accept frames, contact lenses, and accessories and reject optical lenses as direct line items.
- Lens-category and lens-assignment behavior applies only to optical `lens` products.
- Existing frame AR behavior remains unchanged.
- Existing `general` data is either absent or explicitly reclassified before deployment.
- Focused tests and the full Pest suite pass.
- Modified PHP files pass Pint formatting.
- `docs/BACKEND_CONTEXT.md` describes the new four-type contract and the breaking API change.

## Open Questions

None. The product taxonomy, ordering behavior, compatibility posture, and data-migration boundary were approved before this specification was written.

## Phase 2: Implementation Plan

### Architecture Decisions

1. **Keep `product_type` as a string column.** The database already stores product types in an unconstrained string, so the new values require no schema alteration, enum, or dependency.

2. **Add a forward-only legacy-data guard migration.** The migration checks for any remaining `general` products and throws a descriptive exception if it finds them. This stops deployment before ambiguous data is silently reclassified. Its `down()` method is a no-op because it changes no schema or data. Fresh installations pass because seed data is created after migrations; the currently inspected database also passes because it contains no `general` rows.

3. **Define behavior-oriented type constants on `Product`.** A single `DIRECTLY_ORDERABLE_TYPES` constant will contain `frame`, `contact_lens`, and `accessory`. Catalog queries, order selectors, and API validation will use this constant instead of repeating allowlists. A separate type-to-label constant will provide the four management labels without introducing a PHP enum.

4. **Continue using explicit allowlists.** Directly orderable products will not be defined as "anything except lens." Unknown or historical values must remain excluded by default.

5. **Preserve the existing lens distinction.** `lens` means an optical lens inventory product assigned to an order item by staff. `contact_lens` is a normal directly purchased product and does not participate in lens-category or lens-assignment behavior.

6. **Make the API contract break explicit rather than transitional.** The backend will return the new stored values immediately and will not emit `general` compatibility aliases. Client release coordination remains outside this work.

7. **Integrate with the dirty product form instead of replacing it.** Only the product type options in `ProductForm.php` will change. The existing RichEditor toolbar modification remains intact.

### Component Sequence

#### 1. Type Contract and Data Safety

- Write failing factory/model tests for `contact_lens`, `accessory`, the four management labels, and the directly orderable allowlist.
- Generate the forward migration through Sail.
- Implement the `Product` constants and replace the `general()` factory state with `contactLens()` and `accessory()` states.
- Add migration coverage proving it passes without legacy rows and rejects ambiguous `general` rows without modifying them.

**Dependency:** None.

**Checkpoint:** Product type contract tests pass, and the migration guard is proven non-destructive.

#### 2. Mobile Catalog and Ordering Slice

- Write failing API tests covering list visibility, detail visibility, returned `product_type`, and direct order validation for all four types.
- Update `ProductController` catalog allowlists and `StoreOrderRequest` order validation to use `Product::DIRECTLY_ORDERABLE_TYPES`.
- Confirm inactive-product and inactive-variant behavior remains unchanged.

**Dependency:** Type contract constants and factory states.

**Checkpoint:** Product catalog and mobile order request tests pass end to end.

#### 3. Filament Product Management Slice

- Write or update failing Filament tests for the four create-form options and product-type filtering/display.
- Replace General with Contact Lens and Accessory in the product form, products table, brand relation manager, and product-category relation manager.
- Use consistent labels and badges: Frame=`info`, Lens=`success`, Contact Lens=`warning`, Accessory=`gray`.
- Verify lens-category visibility remains exclusive to `lens`, while AR controls remain exclusive to `frame`.

**Dependency:** Type contract constants.

**Checkpoint:** Product resource tests pass and the pre-existing RichEditor toolbar diff is unchanged.

#### 4. Filament Ordering Slice

- Write or update failing tests proving all Filament order selectors include frame, contact-lens, and accessory variants and exclude lens variants.
- Update the create wizard, edit form, and order-items relation manager to use the directly orderable type constant.
- Replace general-product edit coverage with separate contact-lens and accessory coverage where behavior differs by item type.

**Dependency:** Type contract constants and factory states.

**Checkpoint:** Filament order resource tests pass, including existing prescription and lens-assignment tests.

#### 5. Documentation and Final Verification

- Update `docs/BACKEND_CONTEXT.md` product table, product model rules, Filament resource description, and completed-spec index.
- Update the existing product data structure specification so contact lenses and accessories are no longer described as `general`.
- Mark this specification complete only after implementation verification succeeds.
- Search active application code, tests, seeders, and current documentation for unsupported `general` references; retain only historical migration rationale and the new guard.
- Run focused tests, Pint, then the full Pest suite.

**Dependency:** All implementation slices.

**Checkpoint:** Every success criterion passes and documentation matches runtime behavior.

### Dependency Graph

```text
Type contract + migration guard
    |-- Mobile catalog and order API
    |-- Filament product management
    `-- Filament order selectors
             |
             `-- Documentation + full verification
```

### Verification Checkpoints

1. **Foundation:** Factory/model and migration-guard tests pass.
2. **API:** Catalog and order-request tests pass for all four product types.
3. **Filament:** Product and order resource tests pass without disturbing unrelated form changes.
4. **Complete:** Focused suite passes, Pint reports clean formatting, full Pest suite passes, and no active `general` support remains.

### Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A deployed environment contains ambiguous `general` rows | High: deployment could produce incorrect product behavior | Guard migration stops before mutation; classify records explicitly before retrying |
| Android only recognizes `general` | High: catalog parsing or filtering may fail | Treat as an announced breaking contract; coordinate Android separately before deployment |
| One of several duplicated order selectors retains the old allowlist | Medium: inconsistent staff ordering behavior | Use one model constant and test create, edit, and relation-manager paths |
| Contact lenses accidentally trigger optical-lens assignment | Medium: incorrect order workflow | Keep all special behavior keyed strictly to `lens` and add regression tests |
| Existing `ProductForm.php` work is overwritten | Medium: unrelated UI regression | Patch only the type options and compare the RichEditor diff before finalizing |
| Historical `general` text is removed from old migrations | Low: lost migration context | Retain historical migration comments; remove only active support and current documentation claims |

### Parallelization

The API and Filament slices are logically independent after the type contract exists, but they share factories, model constants, and test fixtures. Sequential implementation is preferred for this small change to avoid unnecessary merge coordination. Documentation remains last so it records verified behavior.

### Phase 2 Open Questions

None. The plan follows the approved Phase 1 boundaries.

## Phase 3: Task Checklist

### Task 1: Establish the Product Type Contract and Legacy Guard

**Description:** Introduce the four supported management types and the directly orderable allowlist, replace the general factory state, and add a non-destructive migration guard for ambiguous legacy data. Follow TDD by updating the focused tests before implementation.

**Acceptance criteria:**

- [ ] `Product` exposes labels for exactly `frame`, `lens`, `contact_lens`, and `accessory` and an allowlist containing exactly the three directly orderable types.
- [ ] `ProductFactory` provides `contactLens()` and `accessory()` states and no longer provides `general()`.
- [ ] A new forward migration passes when no `general` products exist and fails descriptively without mutating data when they do.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/ProductTypeTest.php tests/Feature/CatalogSchemaTest.php`.
- [ ] Run `vendor/bin/sail artisan migrate --no-interaction` against the inspected local database after the guard test passes.

**Dependencies:** None.

**Files:**

- `app/Models/Product.php`
- `database/factories/ProductFactory.php`
- `database/migrations/<timestamp>_guard_against_legacy_general_product_type.php`
- `tests/Feature/ProductTypeTest.php`
- `tests/Feature/CatalogSchemaTest.php`

**Estimated scope:** Medium, 5 files.

### Checkpoint 1: Foundation

- [ ] The type-contract and schema tests pass.
- [ ] The migration guard has been exercised for both safe and blocked states.
- [ ] No existing product data changed during the migration.

### Task 2: Expand the Mobile Catalog and Direct Ordering Contract

**Description:** Make contact lenses and accessories visible and directly orderable through the authenticated mobile API while continuing to exclude optical lens products. Add the four-type API cases before updating the implementation.

**Acceptance criteria:**

- [ ] Catalog list and detail endpoints return active frames, contact lenses, and accessories with their exact stored type values.
- [ ] Catalog list and detail endpoints continue to hide or reject optical lenses and inactive products.
- [ ] Mobile order requests accept active frame, contact-lens, and accessory variants and reject optical-lens variants.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/ProductCatalogTest.php tests/Feature/Api/OrderRequestTest.php`.

**Dependencies:** Task 1.

**Files:**

- `app/Http/Controllers/Api/ProductController.php`
- `app/Http/Requests/Api/StoreOrderRequest.php`
- `tests/Feature/Api/ProductCatalogTest.php`
- `tests/Feature/Api/OrderRequestTest.php`

**Estimated scope:** Medium, 4 files.

### Checkpoint 2: API

- [ ] Product catalog tests pass for all four types.
- [ ] Mobile order validation tests pass for all directly orderable types and optical-lens rejection.
- [ ] Existing search, sorting, pagination, stock, and authentication assertions remain green.

### Task 3: Expand Filament Product Management

**Description:** Replace General with Contact Lens and Accessory across product creation, product tables, filters, and related brand/category product lists. Preserve the unrelated RichEditor toolbar modification already present in the product form.

**Acceptance criteria:**

- [ ] Product creation offers exactly Frame, Lens, Contact Lens, and Accessory.
- [ ] Product list filtering and all product type badges show the four readable labels with the planned colors.
- [ ] Lens Category remains lens-only, AR fields remain frame-only, and the RichEditor toolbar change is untouched.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/CatalogResourceTest.php`.
- [ ] Compare `git diff -- app/Filament/Resources/Products/Schemas/ProductForm.php` and confirm the pre-existing RichEditor change remains present.

**Dependencies:** Task 1.

**Files:**

- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/Tables/ProductsTable.php`
- `app/Filament/Resources/Brands/RelationManagers/ProductsRelationManager.php`
- `app/Filament/Resources/ProductCategories/RelationManagers/ProductsRelationManager.php`
- `tests/Feature/Filament/CatalogResourceTest.php`

**Estimated scope:** Medium, 5 files.

### Task 4: Expand Filament Direct Order Selectors

**Description:** Update every staff order-item selector to use the shared directly orderable type contract, with tests covering create, edit, and relation-manager paths.

**Acceptance criteria:**

- [ ] Create-order and edit-order selectors include active frame, contact-lens, and accessory variants.
- [ ] The order-items relation manager uses the same allowlist for create and edit actions.
- [ ] Optical lens variants remain excluded, and existing frame lens-assignment behavior remains green.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/OrderResourceTest.php`.

**Dependencies:** Task 1.

**Files:**

- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Filament/Resources/Orders/RelationManagers/ItemsRelationManager.php`
- `tests/Feature/Filament/OrderResourceTest.php`

**Estimated scope:** Medium, 4 files.

### Checkpoint 3: Filament

- [ ] Catalog resource tests pass.
- [ ] Order resource tests pass.
- [ ] Product form type-specific visibility behaves as before for frames and optical lenses.
- [ ] The unrelated ProductForm RichEditor modification remains intact.

### Task 5: Align Documentation and Complete Verification

**Description:** Update the canonical backend context, record that the earlier three-type decision is superseded, then run formatting and the complete regression suite. Mark this specification complete only after every check succeeds.

**Acceptance criteria:**

- [ ] Current documentation describes the four types and the new catalog/order behavior.
- [ ] Historical specifications remain available and clearly point to this superseding specification where their taxonomy is outdated.
- [ ] Active code and tests contain no supported `general` behavior outside the historical consolidation migration and the new legacy-data guard.

**Verification:**

- [ ] Run `rg -n "general" app database/factories database/seeders tests docs/BACKEND_CONTEXT.md` and review every remaining match.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] Re-run the focused tests listed in Tasks 1-4.
- [ ] Run `vendor/bin/sail artisan test --compact`.

**Dependencies:** Tasks 1-4.

**Files:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/product-data-structure.md`
- `docs/specs/product-order-billing-rework-spec.md`
- `docs/specs/product-type-expansion-spec.md`

**Estimated scope:** Medium, 4 files.

### Final Checkpoint

- [ ] All specification success criteria are satisfied.
- [ ] The legacy-data guard passes on the inspected database.
- [ ] Focused and full Pest suites pass.
- [ ] Pint has formatted all modified PHP files.
- [ ] No unrelated worktree changes were reverted or overwritten.
- [ ] The specification status is `Complete` and its phase is `Done`.

### Phase 3 Open Questions

None. Every task is bounded to five files or fewer and includes an explicit verification step.
