<?php

use Database\Seeders\PlasticPackagingSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off data load for the live company: polyethylene → plastic-bag master data
 * (units, raw materials, finished products, warehouses, a BOM and sample partners),
 * mapped to the right accounts. Delegates to the idempotent PlasticPackagingSeeder,
 * so re-running is safe and a fresh install (no company yet at migrate time) is a
 * no-op. This lets the deploy pipeline load it via `migrate --force` with no shell.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PlasticPackagingSeeder())->run();
    }

    public function down(): void
    {
        // One-off data seed — nothing to roll back.
    }
};
