<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Отписка по ссылке из рекламного письма — без входа, по подписанной ссылке. */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, Customer $customer): View
    {
        if (! $customer->unsubscribed_at || $customer->unsubscribed_at->lt($customer->marketing_consent_at ?? now()->subYears(10))) {
            $customer->forceFill(['unsubscribed_at' => now()])->save();
        }

        return view('pages.unsubscribed', ['robots' => 'noindex, nofollow']);
    }
}
