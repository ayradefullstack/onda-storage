<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;
use Tests\Support\TestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * The temporary root this test's four vault disks point at.
     */
    protected string $storageRoot = '';

    /**
     * Every test gets its OWN temporary vault / incoming / work / variants
     * roots — never the ones in .env. Those hold real deposits (and, in dev,
     * the e2e suite's uploads); a test that wrote there left thousands of
     * orphan files behind and, worse, could delete a real file by path. The
     * roots live in the system temp dir (one partition, so `rename()` stays
     * instant, as the vault requires) and are removed in tearDown.
     * `Tests\Feature\Isolation\StorageIsolationTest` fails if this is bypassed.
     */
    /**
     * Runs after the application exists and BEFORE any trait (RefreshDatabase
     * included) boots: a cached config or a real database stops the run here,
     * before a single query. See TestEnvironmentGuard.
     */
    protected function setUpTraits(): array
    {
        TestEnvironmentGuard::assertSafe($this->app);

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // HARD CAPS. Real ffmpeg / pdftoppm / soffice exist on dev machines
        // and the tests that use them must fail fast, never hang the suite:
        // every external tool already runs under one of these timeouts, so
        // they are tightened here, and one test may not run past 5 minutes.
        config([
            'vault.consult.convert_timeout' => 60,
            'vault.consult.render_timeout' => 60,
            'vault.variant_timeout_seconds' => 60,
            'vault.probe_timeout_seconds' => 30,
        ]);
        set_time_limit(300);

        $this->storageRoot = str_replace('\\', '/', sys_get_temp_dir()).'/onda-test-'.getmypid().'-'.bin2hex(random_bytes(4));

        foreach (['vault', 'incoming', 'work', 'variants'] as $disk) {
            @mkdir("{$this->storageRoot}/{$disk}", 0700, true);
            config(["filesystems.disks.{$disk}.root" => "{$this->storageRoot}/{$disk}"]);
        }

        Storage::forgetDisk(['vault', 'incoming', 'work', 'variants']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->storageRoot);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
