<?php

namespace App\Http\Controllers;

use App\Models\Declaration;

class DeclarationController extends Controller
{
    public function __invoke()
    {
        return view('pages.declarations', [
            'declarations' => Declaration::active()
                ->with('products')
                ->orderBy('sort')
                ->orderByDesc('issued_on')
                ->get(),
        ]);
    }
}