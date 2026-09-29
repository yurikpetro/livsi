<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PrintOrderController extends Controller
{
    public function __invoke(Request $request, Order $order): Response
    {
        abort_unless(
            $request->user()?->canAccessPanel(Filament::getPanel('admin'))
                && OrderResource::canView($order),
            403,
        );

        return response()->view('pages.order-print', [
            'order' => $order->load('items'),
            'payment' => $order->lastPayment(),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
