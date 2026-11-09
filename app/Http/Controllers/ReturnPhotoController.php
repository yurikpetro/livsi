<?php

namespace App\Http\Controllers;

use App\Models\OrderReturn;
use App\Services\ReturnService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Фото к заявке на возврат — только для админки: файлы лежат вне public. */
class ReturnPhotoController extends Controller
{
    public function __invoke(Request $request, OrderReturn $return, int $index): StreamedResponse
    {
        abort_unless((bool) $request->user()?->canAccessPanel(Filament::getPanel('admin')), 403);
        abort_unless(ReturnService::photoExists($return, $index), 404);

        return Storage::disk(ReturnService::PHOTO_DISK)->response($return->photos[$index], null, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
