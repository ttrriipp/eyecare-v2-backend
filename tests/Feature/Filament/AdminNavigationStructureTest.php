<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The sidebar's information architecture is a deliberate design decision, not an
 * accident of discovery order. These tests lock in the workflow-shaped grouping so
 * a new resource cannot silently reintroduce an orphaned or undeclared group.
 */

/** Declared group order, from AdminPanelProvider::panel(). */
const EXPECTED_GROUP_ORDER = [
    'Today',
    'Patients',
    'Clinical',
    'Optical',
    'Billing',
    'Catalog',
    'Admin',
];

/**
 * @return array<string, array<int, string>> group label => ordered item labels
 */
function adminNavigation(User $user): array
{
    test()->actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $navigation = [];

    foreach (Filament::getNavigation() as $group) {
        $label = $group->getLabel() ?? '';

        $navigation[$label] = collect($group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();
    }

    return $navigation;
}

/**
 * @return array<string, string> item label => icon name
 */
function adminNavigationIcons(User $user): array
{
    test()->actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $icons = [];

    foreach (Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            $icon = $item->getIcon();

            $icons[$item->getLabel()] = $icon instanceof BackedEnum ? $icon->value : (string) $icon;
        }
    }

    return $icons;
}

test('navigation groups appear in the declared workflow order', function () {
    $navigation = adminNavigation(User::factory()->admin()->create());

    // Dashboard sits ungrouped above everything, under a blank-label group.
    $groups = array_values(array_filter(
        array_keys($navigation),
        fn (string $label): bool => $label !== '',
    ));

    expect($groups)->toBe(EXPECTED_GROUP_ORDER);
});

test('every navigation item belongs to a declared group', function () {
    $navigation = adminNavigation(User::factory()->admin()->create());

    // The only permitted ungrouped item is the Dashboard. Anything else in the
    // blank-label group is an orphan that will render above every heading.
    expect($navigation[''] ?? [])->toBe(['Dashboard']);

    $undeclared = array_diff(
        array_keys($navigation),
        [...EXPECTED_GROUP_ORDER, ''],
    );

    expect($undeclared)->toBeEmpty(
        'Undeclared groups sort after every declared one: '.implode(', ', $undeclared),
    );
});

test('items are ordered within each group', function () {
    $navigation = adminNavigation(User::factory()->admin()->create());

    expect($navigation)->toMatchArray([
        'Today' => ['Appointments', 'Appointment Requests', 'Scheduling'],
        'Patients' => ['Patient Records', 'Patient Accounts', 'Link Requests', 'Conversations', 'Visit Feedback'],
        'Clinical' => ['Consultations', 'Prescriptions'],
        'Optical' => ['Quotations', 'Optical Orders'],
        'Billing' => ['Billing & Payments'],
        'Catalog' => ['Products', 'Inventory', 'Inventory History', 'Brands', 'Lens Categories', 'Lens Options', 'Product Categories', 'Services'],
        'Admin' => ['Staff Accounts', 'SMS Log', 'Audit Logs', 'Reports'],
    ]);
});

test('no group is rendered with a single item', function () {
    $navigation = adminNavigation(User::factory()->admin()->create());

    $singletons = array_keys(array_filter(
        $navigation,
        fn (array $items, string $label): bool => $label !== '' && count($items) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));

    // Billing is allowed to have a single item
    $singletons = array_filter($singletons, fn (string $label): bool => $label !== 'Billing');

    expect($singletons)->toBeEmpty(
        'A one-item group is a heading that earns no space: '.implode(', ', $singletons),
    );
});

test('every navigation icon is unique', function () {
    $icons = adminNavigationIcons(User::factory()->admin()->create());

    // The sidebar is collapsible on desktop, where the icon is the only label a staff
    // member gets. Two items sharing a glyph makes the collapsed sidebar unreadable.
    $duplicated = array_keys(array_filter(array_count_values($icons), fn (int $n): bool => $n > 1));

    expect($duplicated)->toBeEmpty(
        'Icons used more than once: '.implode(', ', $duplicated),
    );
});

test('navigation icons share one outlined family', function () {
    $icons = adminNavigationIcons(User::factory()->admin()->create());

    // Heroicon's outlined set is the panel's icon family; a solid glyph reads heavier
    // than its neighbours at 20px and breaks the row rhythm. Outlined cases resolve to
    // an 'o-' prefix; solid, mini, and micro resolve to 's-', 'm-', and 'c-'.
    $solid = array_filter(
        $icons,
        fn (string $icon): bool => ! str_starts_with($icon, 'o-'),
    );

    expect($solid)->toBeEmpty(
        'Non-outlined icons: '.implode(', ', array_map(
            fn (string $label, string $icon): string => "{$label} ({$icon})",
            array_keys($solid),
            $solid,
        )),
    );
});

test('a receptionist sees no empty groups and no orphaned items', function () {
    $navigation = adminNavigation(User::factory()->staff()->create());

    expect($navigation[''] ?? [])->toBe(['Dashboard'])
        ->and($navigation)->not->toHaveKey('Admin'); // every Admin item is admin-gated

    foreach ($navigation as $label => $items) {
        expect($items)->not->toBeEmpty("Group '{$label}' renders a heading with nothing under it.");
    }
});
