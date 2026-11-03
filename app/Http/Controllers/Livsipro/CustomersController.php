<?php

namespace App\Http\Controllers\Livsipro;

use App\Filament\Support\PromoFields;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Mailing;
use App\Models\MailingRecipient;
use App\Models\Order;
use App\Models\PromoCode;
use App\Services\MailingService;
use App\Services\PromoCodeService;
use App\Support\Fulfillment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Клиенты сайта, промокоды и рассылки для livsipro.ru.
 *
 * В отличие от сборки, здесь есть телефон и почта: раздел на livsipro
 * открыт только администраторам (это проверяет сам livsipro — сюда он
 * ходит одним токеном за всех). Суммы отдаются в копейках, а при выдаче
 * промокода рубли принимаются рублями — как их вводит человек.
 */
class CustomersController extends Controller
{
    public function __construct(
        private readonly PromoCodeService $promoCodes,
        private readonly MailingService $mailings,
    ) {}

    /** Список клиентов: поиск, фильтр, по страницам. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter' => ['sometimes', 'in:all,subscribed,registered,guests,buyers'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = Customer::query()->withStats();
        $this->search($query, (string) ($data['q'] ?? ''));
        match ($data['filter'] ?? 'all') {
            'subscribed' => $query->subscribed(),
            'registered' => $query->whereNotNull('user_id'),
            'guests' => $query->whereNull('user_id'),
            'buyers' => $query->whereHas('orders', fn (Builder $q) => $q->where('status', Order::STATUS_PAID)),
            default => null,
        };
        $per = (int) ($data['per'] ?? 50);
        $page = $query->orderByDesc('last_order_at')->orderByDesc('id')->paginate($per, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'customers' => collect($page->items())->map(fn (Customer $customer): array => $this->brief($customer))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'counts' => ['all' => Customer::query()->count(), 'subscribed' => Customer::query()->subscribed()->count()],
        ]);
    }

    /** Карточка клиента: заказы с итогом и промокодом, его личные промокоды. */
    public function show(int $id): JsonResponse
    {
        $customer = Customer::query()->withStats()->findOrFail($id);
        $orders = $customer->orders()->with(['items', 'receipts'])->latest('created_at')->limit(200)->get();
        $codes = $customer->promoCodes()->with('orders:id,number,promo_code_id,status')->latest('id')->get();

        return response()->json([
            'customer' => $this->brief($customer) + [
                'consent_at' => $customer->marketing_consent_at?->toIso8601String(),
                'unsubscribed_at' => $customer->unsubscribed_at?->toIso8601String(),
                'note' => $customer->note,
            ],
            'orders' => $orders->map(fn (Order $order): array => $this->order($order))->all(),
            'promo_codes' => $codes->map(fn (PromoCode $promo): array => $this->promo($promo))->all(),
        ]);
    }

    /** Выдать личный одноразовый промокод одному или нескольким клиентам. */
    public function issuePromo(Request $request): JsonResponse
    {
        $data = $request->validate($this->promoRules('') + [
            'customer_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'customer_ids.*' => ['integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
            'by' => ['required', 'string', 'max:80'],
        ]);
        $customers = Customer::query()->whereIn('id', $data['customer_ids'])->get();
        abort_if($customers->isEmpty(), 404, 'Клиенты не найдены.');
        $spec = PromoFields::spec($data);
        $issued = $customers->map(fn (Customer $customer): array => [
            'customer_id' => $customer->id,
            'code' => $this->promoCodes->issue($spec, $customer, $this->by($data['by']))->code,
        ]);

        return response()->json(['issued' => $issued->values()->all(), 'benefit' => (new PromoCode(['kind' => $spec['kind'], 'value' => $spec['value'], 'min_total' => $spec['min_total']]))->benefit()]);
    }

    /** База промокодов: кому, кто, когда выдал, в каком заказе использован. */
    public function promoCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = PromoCode::query()->with(['customer', 'orders:id,number,promo_code_id,status'])->latest('id');
        if (filled($q = trim((string) ($data['q'] ?? '')))) {
            $query->where(fn (Builder $w) => $w->where('code', 'like', '%'.PromoCodeService::normalize($q).'%')
                ->orWhereHas('customer', fn (Builder $c) => $this->search($c, $q)));
        }
        $page = $query->paginate(50, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'promo_codes' => collect($page->items())->map(fn (PromoCode $promo): array => $this->promo($promo) + [
                'customer' => $promo->customer ? ['id' => $promo->customer->id, 'name' => $promo->customer->name, 'email' => $promo->customer->email] : null,
            ])->all(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage(),
        ]);
    }

    public function mailingsIndex(): JsonResponse
    {
        return response()->json([
            'mailings' => Mailing::query()->latest('id')->limit(50)->get()->map(fn (Mailing $mailing): array => $this->mailing($mailing))->all(),
            'subscribed' => Customer::query()->subscribed()->count(),
        ]);
    }

    public function mailingShow(int $id): JsonResponse
    {
        $mailing = Mailing::query()->findOrFail($id);

        return response()->json([
            'mailing' => $this->mailing($mailing) + ['body' => $mailing->body],
            'recipients' => $mailing->recipients()->with(['customer:id,name', 'promoCode:id,code'])->orderBy('id')->limit(1000)->get()
                ->map(fn (MailingRecipient $r): array => ['email' => $r->email, 'name' => $r->customer?->name, 'status' => $r->status,
                    'error' => $r->error, 'promo_code' => $r->promoCode?->code, 'sent_at' => $r->sent_at?->toIso8601String()])->all(),
        ]);
    }

    /** Рассылка отмеченным (customer_ids) или всем с согласием (audience=subscribed). */
    public function mailingStore(Request $request): JsonResponse
    {
        $data = $request->validate($this->mailingRules() + [
            'audience' => ['required', 'in:selected,subscribed'],
            'customer_ids' => ['required_if:audience,selected', 'array', 'max:20000'],
            'customer_ids.*' => ['integer'],
            'by' => ['required', 'string', 'max:80'],
        ]);
        $customers = $data['audience'] === 'subscribed'
            ? Customer::query()->subscribed()->get()
            : Customer::query()->whereIn('id', $data['customer_ids'] ?? [])->get();
        $mailing = $this->mailings->create($this->mailingData($data), $customers, $this->by($data['by']));

        return response()->json([
            'mailing' => $this->mailing($mailing),
            'skipped' => $mailing->recipients()->where('status', MailingRecipient::SKIPPED)->count(),
        ], 201);
    }

    /** Пробное письмо на указанный адрес — как увидит клиент. */
    public function mailingTest(Request $request): JsonResponse
    {
        $data = $request->validate($this->mailingRules() + ['email' => ['required', 'email', 'max:255']]);
        $this->mailings->sendTest($this->mailingData($data), $data['email']);

        return response()->json(['sent_to' => $data['email']]);
    }

    // ------------------------------------------------------------------

    private function search(Builder $query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }
        $digits = preg_replace('/\D/', '', $q);
        $query->where(function (Builder $w) use ($q, $digits): void {
            $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhere('email', 'like', '%'.mb_strtolower($q).'%')
                ->orWhere('city', 'like', '%'.$q.'%');
            if (strlen($digits) >= 3) {
                $w->orWhere('phone', 'like', '%'.(strlen($digits) === 11 && $digits[0] === '8' ? '7'.substr($digits, 1) : $digits).'%');
            }
        });
    }

    private function brief(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'city' => $customer->city,
            'registered' => $customer->user_id !== null,
            'subscribed' => $customer->canReceiveMarketing(),
            'unsubscribed' => $customer->unsubscribed_at !== null && ! $customer->canReceiveMarketing(),
            'orders_count' => (int) ($customer->paid_orders_count ?? 0),
            'paid_total' => (int) ($customer->paid_total ?? 0),
            'last_order_at' => $customer->last_order_at ? Carbon::parse($customer->last_order_at)->toIso8601String() : null,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    private function order(Order $order): array
    {
        $status = Fulfillment::current($order);

        return [
            'number' => $order->number,
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'total' => (int) $order->total,
            'items' => $order->items->map(fn ($item): string => $item->label().($item->quantity > 1 ? ' × '.$item->quantity : ''))->all(),
            'status' => ['code' => $order->status, 'label' => $order->statusLabel()],
            'outcome' => $order->outcome(),
            'fulfillment' => $status ? ['code' => $status, 'label' => Fulfillment::label($status),
                'since' => Fulfillment::since($order)?->toIso8601String()] : null,
            'delivery_city' => $order->delivery_city,
            'promo' => $order->promo_code ? ['code' => $order->promo_code, 'discount' => (int) $order->promo_discount,
                'free_delivery' => (bool) $order->promo_free_delivery] : null,
        ];
    }

    private function promo(PromoCode $promo): array
    {
        return [
            'code' => $promo->code,
            'benefit' => $promo->benefit(),
            'state' => $promo->stateLabel(),
            'personal' => $promo->customer_id !== null,
            'expires_at' => $promo->expires_at?->toIso8601String(),
            'issued_by' => $promo->issued_by,
            'issued_at' => $promo->created_at?->toIso8601String(),
            'note' => $promo->note,
            'used_in' => $promo->orders->whereIn('status', [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PAID])->pluck('number')->values()->all(),
        ];
    }

    private function mailing(Mailing $mailing): array
    {
        return [
            'id' => $mailing->id,
            'subject' => $mailing->subject,
            'created_at' => $mailing->created_at?->toIso8601String(),
            'created_by' => $mailing->created_by,
            'total' => (int) $mailing->total,
            'sent' => (int) $mailing->sent,
            'failed' => (int) $mailing->failed,
            'status' => $mailing->status,
            'status_label' => $mailing->statusLabel(),
            'promo' => $mailing->promo_kind
                ? (new PromoCode(['kind' => $mailing->promo_kind, 'value' => (int) $mailing->promo_value]))->benefit()
                    .($mailing->promo_days ? ', на '.$mailing->promo_days.' дн.' : '')
                : null,
        ];
    }

    /** Поля промокода: вид, скидка (% или ₽), «от суммы» в рублях, срок в днях. */
    private function promoRules(string $prefix): array
    {
        return [
            $prefix.'kind' => [$prefix === '' ? 'required' : 'required_if:with_promo,true', Rule::in(array_keys(PromoCode::kinds()))],
            $prefix.'value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            $prefix.'min_total' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            $prefix.'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    private function mailingRules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:20000'],
            'with_promo' => ['sometimes', 'boolean'],
        ] + $this->promoRules('promo_');
    }

    private function mailingData(array $data): array
    {
        return ['subject' => (string) $data['subject'], 'body' => (string) $data['body'],
            'promo' => ! empty($data['with_promo']) ? PromoFields::spec($data, 'promo_') : null];
    }

    private function by(string $name): string
    {
        return 'livsipro · '.trim($name);
    }
}
