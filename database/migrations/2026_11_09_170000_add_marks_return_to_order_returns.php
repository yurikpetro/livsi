<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Коды «Честного знака», которые после возврата нужно вернуть в оборот
 * вручную (чек возврата пробила Точка без кодов), и отметка, что вернули.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->json('marks_to_return')->nullable();
            $table->timestamp('marks_returned_at')->nullable();
            $table->string('marks_returned_by', 120)->nullable();
        });

        // Уже закрытые возвраты: коды берём из записи возврата денег.
        foreach (DB::table('order_returns')->where('status', 'refunded')->get(['id', 'order_id']) as $return) {
            $refund = DB::table('order_receipts')->where('order_id', $return->order_id)->where('kind', 'refund')->first(['payload']);
            $payload = $refund ? (array) json_decode((string) $refund->payload, true) : [];
            $codes = self::codes((array) ($payload['released_marks'] ?? []));
            if ($codes !== [] && empty($payload['with_receipt'])) {
                DB::table('order_returns')->where('id', $return->id)->update(['marks_to_return' => json_encode($codes)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropColumn(['marks_to_return', 'marks_returned_at', 'marks_returned_by']);
        });
    }

    /** «GTIN серийный» → код идентификации 01GTIN21серийный. */
    private static function codes(array $released): array
    {
        $codes = [];
        foreach ($released as $mark) {
            [$gtin, $serial] = array_pad(explode(' ', (string) $mark, 2), 2, '');
            if ($gtin !== '' && $serial !== '') {
                $codes[] = '01'.$gtin.'21'.$serial;
            }
        }

        return $codes;
    }
};
