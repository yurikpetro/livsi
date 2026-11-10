<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\ReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * «Вернуть заказ» — заявка покупателя на возврат после получения.
 *
 * Доступ как у страницы заказа: владелец аккаунта или ссылка с токеном
 * из письма — гостю тоже нужно вернуть товар.
 */
class OrderReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public function create(Request $request, string $order): View|RedirectResponse
    {
        $record = $this->find($request, $order);
        $rules = $this->returns->eligibility($record);
        if (! $rules['allowed']) {
            return redirect()->to($this->orderUrl($request, $record))
                ->with('status', $rules['note'] ?? 'Этот заказ сейчас нельзя вернуть через сайт. Напишите нам — разберёмся.');
        }

        return view('pages.order-return', [
            'order' => $record,
            'rules' => $rules,
            'rows' => $this->returns->returnable($record),
            'token' => $this->owns($request, $record) ? null : (string) $request->query('token'),
            'backUrl' => $this->orderUrl($request, $record),
            'robots' => 'noindex, nofollow',
        ]);
    }

    public function store(Request $request, string $order): RedirectResponse
    {
        $record = $this->find($request, $order);
        $explain = array_merge(OrderReturn::sellerReasons(), [OrderReturn::REASON_OTHER]);
        $data = $request->validate([
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'reason' => ['required', Rule::in(array_keys(OrderReturn::reasons()))],
            'comment' => [Rule::requiredIf(fn (): bool => in_array($request->input('reason'), $explain, true)), 'nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.ReturnService::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:10240'],
            'conditions' => ['accepted'],
        ], [
            'qty.required' => 'Отметьте, что хотите вернуть.',
            'reason.required' => 'Выберите причину возврата.',
            'comment.required' => 'Опишите, что не так с товаром, — так мы решим быстрее.',
            'photos.max' => 'Не больше '.ReturnService::MAX_PHOTOS.' фото.',
            'photos.*.mimes' => 'Фото — в формате JPG, PNG, WEBP или HEIC.',
            'photos.*.max' => 'Фото слишком большое — до 10 МБ.',
            'photos.*.uploaded' => 'Фото не загрузилось — попробуйте файл поменьше.',
            'conditions.accepted' => 'Подтвердите, что ознакомились с условиями возврата.',
        ]);

        $this->returns->request($record, (array) $data['qty'], $data['reason'], $data['comment'] ?? null,
            $request->user(), array_values(array_filter((array) $request->file('photos', []))));

        return redirect()->to($this->orderUrl($request, $record))
            ->with('status', 'Заявка на возврат отправлена. Ответим письмом'.($record->customer_email ? ' на '.$record->customer_email : '').'.');
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $record = $this->find($request, $order);
        $return = $record->activeReturn();
        if ($return) {
            $this->returns->cancel($return);
        }

        return redirect()->to($this->orderUrl($request, $record))->with('status', 'Заявка на возврат отозвана.');
    }

    private function find(Request $request, string $number): Order
    {
        $record = Order::with('items')->where('number', $number)->first();
        if (! $record || ! ($this->owns($request, $record) || hash_equals((string) $record->access_token, (string) $request->input('token')))) {
            throw new NotFoundHttpException;
        }

        return $record;
    }

    private function owns(Request $request, Order $order): bool
    {
        return $order->user_id !== null && $order->user_id === $request->user()?->id;
    }

    private function orderUrl(Request $request, Order $order): string
    {
        return $this->owns($request, $order) ? route('order.show', $order->number) : $order->accessUrl();
    }
}
