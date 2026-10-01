<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Host\PlanController;
use App\Models\Payment;
use App\Services\HotPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Paying for packages through HotPay.
 *
 * Three steps: we open an order and send the customer to the gateway, the
 * gateway notifies us server to server, the customer comes back to a thank-you
 * page.
 *
 * The package is granted by the NOTIFICATION ALONE. The return address can be
 * typed by hand, so were it the thing granting the package, everyone would
 * take it for free.
 */
class PaymentController extends Controller
{
    /**
     * Opens an order and shows a page that carries itself to the gateway.
     *
     * The amount comes from OUR price list, never from the request - otherwise
     * the customer would name their own price in the form.
     */
    public function start(Request $request, HotPay $hotpay)
    {
        $data = $request->validate([
            'package' => ['required', 'in:party,wedding,pro'],
            'party' => ['nullable', 'string', 'size:6'],
        ]);

        abort_unless($hotpay->isConfigured(), 503, 'Payments are not switched on yet.');

        $package = PlanController::PACKAGES[$data['package']];
        $party = null;

        if (! empty($data['party'])) {
            $party = $request->user()->parties()->where('code', $data['party'])->firstOrFail();
        }

        $payment = Payment::create([
            'order_id' => HotPay::newOrderId(),
            'user_id' => $request->user()->id,
            'party_id' => $party?->id,
            'plan' => $data['package'],
            'amount' => $package['price'] * 100,
            'status' => Payment::NEW,
        ]);

        return Inertia::render('Host/Payment', [
            'gateway' => HotPay::GATEWAY,
            'fields' => $hotpay->formFields(
                $payment,
                'QRowd — pakiet '.$package['name'],
                route('payment.return', $payment->order_id),
            ),
            'order' => [
                'number' => $payment->order_id,
                'package' => $package['name'],
                'amount' => $package['price'],
            ],
        ]);
    }

    /**
     * The notification from HotPay - the only place where we grant a package.
     *
     * The route is exempt from CSRF protection, because the request arrives
     * from the operator's server rather than from a browser. The signature is
     * what proves it genuine.
     */
    public function notification(Request $request, HotPay $hotpay)
    {
        $data = $request->all();

        if (! $hotpay->notificationIsAuthentic($data)) {
            Log::warning('HotPay: notification with an invalid signature', [
                'order' => $data['ID_ZAMOWIENIA'] ?? null,
            ]);

            return response('BLEDNY PODPIS', 400);
        }

        $payment = Payment::where('order_id', $data['ID_ZAMOWIENIA'])->first();

        if (! $payment) {
            Log::warning('HotPay: notification for an unknown order', [
                'order' => $data['ID_ZAMOWIENIA'],
            ]);

            return response('NIEZNANE ZAMOWIENIE', 404);
        }

        // A notification can arrive twice - the operator repeats it when it
        // gets no answer. The second one must neither extend the package nor
        // overwrite the payment date.
        if ($payment->isPaid()) {
            return response('OK');
        }

        // The amount has to match what we recorded when opening the order.
        // Without this comparison one could pay a zloty for a 249 package.
        $paidAmount = (int) round(((float) $data['KWOTA']) * 100);

        if ($paidAmount !== $payment->amount) {
            Log::warning('HotPay: the amount does not match the order', [
                'order' => $payment->order_id,
                'expected' => $payment->amount,
                'received' => $paidAmount,
            ]);

            $payment->update(['status' => Payment::REJECTED, 'notification' => $data]);

            return response('ZLA KWOTA', 400);
        }

        if ($data['STATUS'] !== 'SUCCESS') {
            $payment->update([
                'status' => $data['STATUS'] === 'PENDING' ? Payment::NEW : Payment::REJECTED,
                'notification' => $data,
            ]);

            return response('OK');
        }

        $payment->update([
            'status' => Payment::PAID,
            'payment_id' => $data['ID_PLATNOSCI'],
            'secure' => $data['SECURE'],
            'paid_at' => now(),
            'notification' => $data,
        ]);

        $this->grantPackage($payment);

        return response('OK');
    }

    /** The customer returns from the gateway. We grant nothing here - we only show the state. */
    public function result(string $orderId)
    {
        $payment = Payment::where('order_id', $orderId)->firstOrFail();

        return Inertia::render('Host/PaymentResult', [
            'order' => [
                'number' => $payment->order_id,
                'package' => PlanController::PACKAGES[$payment->plan]['name'] ?? $payment->plan,
                'amount' => $payment->amount / 100,
                'status' => $payment->status,
                'party' => $payment->party?->code,
            ],
        ]);
    }

    /**
     * Grants the package once the payment is confirmed.
     *
     * When the payment concerned one particular party we raise that party.
     * When it did not (the Pro subscription) we raise the account.
     */
    private function grantPackage(Payment $payment): void
    {
        $package = PlanController::PACKAGES[$payment->plan] ?? null;

        if (! $package) {
            return;
        }

        // The 'plan' column on a party and on an account are TWO DIFFERENT
        // vocabularies:
        //
        //   party:   free | party | wedding | pro   - what was bought for it
        //   account: free | pro                     - whether the host subscribes
        //
        // One-off packages (Impreza, Wesele) raise that single party only. The
        // account is touched by Pro alone, because Pro is the subscription.
        if ($payment->party) {
            $payment->party->update([
                'plan' => $payment->plan,
                'max_guests' => $package['max_guests'],
            ]);
        }

        if ($payment->plan === 'pro') {
            // is_admin and plan are deliberately NOT mass assignable on the model.
            $payment->user->forceFill(['plan' => 'pro'])->save();
        }
    }
}
