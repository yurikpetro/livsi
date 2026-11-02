<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['code' => 'pro-skin', 'title' => 'PRO SKIN', 'color_bg' => '#99d0f7', 'sort' => 50],
                ['code' => 'pro-manicure-pedicure', 'title' => 'PRO MANICURE / PEDICURE', 'color_bg' => '#d0dd56', 'sort' => 60],
            ] as $line) {
                DB::table('product_lines')->insertOrIgnore([
                    ...$line,
                    'color_ink' => '#111111',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $lines = DB::table('product_lines')->whereIn('code', ['pro-skin', 'pro-manicure-pedicure'])->pluck('id', 'code');
            $titles = DB::table('product_lines')->whereIn('id', $lines)->pluck('title', 'code');
            $products = DB::table('products')->where(fn (Builder $query): Builder => $query->where('is_pro', true)->orWhere('badge', 'pro'))->get();

            foreach ($products as $product) {
                $changes = ['is_pro' => true];
                if ($product->badge === 'pro') {
                    $changes['badge'] = null;
                }

                if (! $lines->contains($product->product_line_id)) {
                    $purposes = DB::table('product_purpose')
                        ->join('purposes', 'purposes.id', '=', 'product_purpose.purpose_id')
                        ->where('product_id', $product->id)->pluck('code')->all();
                    $skin = array_intersect($purposes, ['body', 'face']) !== [];
                    $nails = array_intersect($purposes, ['manicure', 'pedicure']) !== [];

                    if ($skin !== $nails) {
                        $code = $skin ? 'pro-skin' : 'pro-manicure-pedicure';
                        $changes['product_line_id'] = $lines[$code];
                        $changes['search_text'] = mb_strtolower(Str::squish(($product->search_text ?? '').' '.$titles[$code]));
                    }
                }

                DB::table('products')->where('id', $product->id)->update($changes);
            }
        });
    }

    /**
     * Линейки и назначения товаров сохраняются при откате кода как пользовательские данные.
     */
    public function down(): void {}
};
