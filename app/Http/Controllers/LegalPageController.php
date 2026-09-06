<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;
use App\Models\SellerProfile;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LegalPageController extends Controller
{
    /**
     * Документ по фиксированному адресу.
     *
     * Slug приходит из маршрута, а не из адресной строки: адреса документов
     * объявлены статически, чтобы на них можно было ссылаться из чеков,
     * писем и уведомления в Роскомнадзор, не боясь, что правка в админке
     * их изменит.
     */
    public function show(string $slug): View
    {
        $page = LegalPage::active()->where('slug', $slug)->first();

        if (! $page) {
            throw new NotFoundHttpException;
        }

        return view('pages.legal', [
            'page'   => $page,
            'seller' => SellerProfile::current(),
            'others' => LegalPage::active()
                ->where('slug', '!=', $slug)
                ->orderBy('sort')
                ->get(),
        ]);
    }
}
