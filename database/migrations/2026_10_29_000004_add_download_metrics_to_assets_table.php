<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Measured download accounting (the previously unmeasured bandwidth
     * instrumentation): every authorized `?download=1` response counts one
     * download and the served artifact bytes against the asset row. Inline
     * previews are intentionally NOT counted (they are cacheable previews,
     * not exports) — the gap is documented in COST_ACCOUNTING.md.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('download_count')->default(0)->after('size_bytes');
            $table->unsignedBigInteger('downloaded_bytes')->default(0)->after('download_count');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn(['download_count', 'downloaded_bytes']);
        });
    }
};
