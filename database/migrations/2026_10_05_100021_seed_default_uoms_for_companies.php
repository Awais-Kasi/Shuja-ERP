<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fills a standard set of units of measure for any existing company that has
 * none, so a freshly-provisioned company (which previously only got units via the
 * demo seeder) can create items straight away.
 */
return new class extends Migration
{
    private const UOMS = [
        ['PCS', 'Pieces'], ['UNIT', 'Unit'], ['KG', 'Kilogram'], ['G', 'Gram'],
        ['L', 'Litre'], ['ML', 'Millilitre'], ['M', 'Metre'], ['CM', 'Centimetre'],
        ['BOX', 'Box'], ['CTN', 'Carton'], ['PKT', 'Packet'], ['BAG', 'Bag'],
        ['ROLL', 'Roll'], ['DOZ', 'Dozen'], ['PAIR', 'Pair'], ['SET', 'Set'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (DB::table('uoms')->where('company_id', $companyId)->exists()) {
                continue; // don't disturb companies that already have units
            }

            $rows = [];
            foreach (self::UOMS as [$code, $name]) {
                $rows[] = [
                    'company_id' => $companyId, 'code' => $code, 'name' => $name,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('uoms')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally no-op — seeded units are left in place.
    }
};
