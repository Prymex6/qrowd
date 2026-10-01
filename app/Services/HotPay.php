<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * HotPay integration.
 *
 * The gateway works through a form: we build the fields together with a
 * signature, the customer lands on HotPay, pays (BLIK, transfer, card) and the
 * operator sends us a notification on a separate address.
 *
 * OVERRIDING RULE: the package is granted by the NOTIFICATION ALONE.
 *
 * The customer returns from the gateway through an ordinary redirect that can
 * be typed by hand - were that the thing granting the package, anyone would
 * take it for free by visiting the return address. The notification travels
 * server to server and is signed with a password the customer does not know.
 *
 * Signatures per the HotPay documentation (dokumentacja.hotpay.pl); the field
 * names below are the operator's own and stay in Polish on the wire:
 *
 *   payment:      sha256(HASLO ; KWOTA ; NAZWA_USLUGI ; ADRES_WWW ; ID_ZAMOWIENIA ; SEKRET)
 *   notification: sha256(HASLO ; KWOTA ; ID_PLATNOSCI ; ID_ZAMOWIENIA ; STATUS ; SECURE ; SEKRET)
 */
class HotPay
{
    public const GATEWAY = 'https://platnosc.hotpay.pl/';

    public function __construct(
        private ?string $secret,
        private ?string $password,
    ) {}

    public static function make(): self
    {
        return new self(
            config('services.hotpay.secret'),
            config('services.hotpay.password'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->secret) && filled($this->password);
    }

    /**
     * The fields of the form that carries the customer to the gateway.
     *
     * The amount goes out with two decimal places and a dot as the separator -
     * the very same shape has to enter the signature, otherwise HotPay rejects
     * the request.
     */
    public function formFields(Payment $payment, string $serviceName, string $returnUrl): array
    {
        $amount = number_format($payment->amount / 100, 2, '.', '');

        return [
            'SEKRET' => $this->secret,
            'KWOTA' => $amount,
            'NAZWA_USLUGI' => $serviceName,
            'ADRES_WWW' => $returnUrl,
            'ID_ZAMOWIENIA' => $payment->order_id,
            'HASH' => $this->paymentSignature($amount, $serviceName, $returnUrl, $payment->order_id),
        ];
    }

    private function paymentSignature(string $amount, string $service, string $url, string $order): string
    {
        return hash('sha256', implode(';', [
            $this->password, $amount, $service, $url, $order, $this->secret,
        ]));
    }

    /**
     * Whether the notification really comes from HotPay.
     *
     * hash_equals rather than a plain comparison: comparing character by
     * character makes the response time reveal how many leading characters of
     * the signature already match.
     */
    public function notificationIsAuthentic(array $data): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        foreach (['KWOTA', 'ID_PLATNOSCI', 'ID_ZAMOWIENIA', 'STATUS', 'SECURE', 'HASH'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return false;
            }
        }

        $ours = hash('sha256', implode(';', [
            $this->password,
            $data['KWOTA'],
            $data['ID_PLATNOSCI'],
            $data['ID_ZAMOWIENIA'],
            $data['STATUS'],
            $data['SECURE'],
            $this->secret,
        ]));

        return hash_equals($ours, $data['HASH']);
    }

    /** Order number - short, unique, free of characters that need encoding. */
    public static function newOrderId(): string
    {
        return 'QR'.now()->format('ymdHis').Str::upper(Str::random(6));
    }
}
