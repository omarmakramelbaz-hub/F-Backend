<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Services\GoPayments\Gateway;
use App\Services\GoPayments\OrderPayments;
use Illuminate\Http\Request;

class GoOrderPaymentController extends Controller
{
    use ApiResponses;
    public function capabilities()
    {
        return $this->successResponse(['schema_ready'=>\Illuminate\Support\Facades\Schema::hasTable('go_order_payments'),
            'payment_methods'=>array_merge(['cash','wallet'],Gateway::methods()),'currency'=>'EGP','version'=>1]);
    }
    public function checkout(int $order)
    {
        return $this->successResponse((new OrderPayments())->checkout($order,(int)auth('api')->id()),__('api.success data'));
    }
    public function status(int $order)
    {
        $p=OrderPayments::record($order);
        abort_unless($p && (int)$p->customer_id===(int)auth('api')->id(),404);
        return $this->successResponse(['status'=>$p->status,'amount'=>\App\Services\GoServices\Money::decimal((int)$p->amount_cents)],__('api.success data'));
    }
    public function webhook(Request $request)
    {
        $request->validate(['obj'=>'required|array']);
        (new OrderPayments())->callback($request->input('obj'),(string)$request->query('hmac'));
        return response()->json(['received'=>true]);
    }
    public function paymentReturn(Request $request)
    {
        // A return may recover a delayed webhook, but query-string success is
        // never trusted. The merchant inquiry supplies all transaction fields.
        if ($request->filled('id')) {
            try { (new OrderPayments())->settleVerified((new Gateway())->inquire((string)$request->query('id'),Gateway::settings())); }
            catch (\Throwable $e) { /* The app continues to display server payment status. */ }
        }
        return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GO</title><body><h2>ارجع إلى التطبيق لمتابعة حالة الدفع</h2><p>تظهر نتيجة التحقق من Paymob داخل طلبك.</p><p>Return to the app to check the verified payment status.</p></body></html>')
            ->header('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'");
    }
}
