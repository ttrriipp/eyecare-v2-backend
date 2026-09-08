<?php

use Illuminate\Support\Facades\Storage;

test('catalog and AR disks use explicit logical aliases and visibility defaults', function (): void {
    expect(config('filesystems.catalog_disk'))->toBe('public')
        ->and(config('ar.assets.quarantine_disk'))->toBe('ar_quarantine')
        ->and(config('ar.assets.published_disk'))->toBe('ar_published')
        ->and(config('filesystems.disks.public.driver'))->toBe('local')
        ->and(config('filesystems.disks.public.visibility'))->toBe('public')
        ->and(config('filesystems.disks.ar_quarantine.driver'))->toBe('local')
        ->and(config('filesystems.disks.ar_quarantine.visibility'))->toBe('private')
        ->and(config('filesystems.disks.ar_published.driver'))->toBe('local')
        ->and(config('filesystems.disks.ar_published.visibility'))->toBe('public');
});

test('logical asset aliases can target S3-compatible disks without changing domain names', function (): void {
    config([
        'filesystems.catalog_disk' => 'catalog_cloud',
        'filesystems.disks.catalog_cloud' => [
            'driver' => 's3',
            'root' => 'catalog',
            'visibility' => 'public',
        ],
        'ar.assets.quarantine_disk' => 'ar_quarantine_cloud',
        'filesystems.disks.ar_quarantine_cloud' => [
            'driver' => 's3',
            'root' => 'ar/quarantine',
            'visibility' => 'private',
        ],
        'ar.assets.published_disk' => 'ar_published_cloud',
        'filesystems.disks.ar_published_cloud' => [
            'driver' => 's3',
            'root' => 'ar',
            'visibility' => 'public',
        ],
    ]);

    expect(config('filesystems.catalog_disk'))->toBe('catalog_cloud')
        ->and(config('filesystems.disks.catalog_cloud.driver'))->toBe('s3')
        ->and(config('filesystems.disks.catalog_cloud.root'))->toBe('catalog')
        ->and(config('filesystems.disks.catalog_cloud.visibility'))->toBe('public')
        ->and(config('ar.assets.quarantine_disk'))->toBe('ar_quarantine_cloud')
        ->and(config('filesystems.disks.ar_quarantine_cloud.visibility'))->toBe('private')
        ->and(config('ar.assets.published_disk'))->toBe('ar_published_cloud')
        ->and(config('filesystems.disks.ar_published_cloud.root'))->toBe('ar')
        ->and(config('filesystems.disks.ar_published_cloud.visibility'))->toBe('public');
});

test('catalog disk configuration remains compatible with storage fakes', function (): void {
    Storage::fake((string) config('filesystems.catalog_disk'));

    Storage::disk((string) config('filesystems.catalog_disk'))->put('catalog/demo.png', 'demo');

    Storage::disk((string) config('filesystems.catalog_disk'))->assertExists('catalog/demo.png');
});
