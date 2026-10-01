<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use App\Services\HotPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payments through HotPay.
 *
 * THE RULE these tests guard: the package is granted by the notification
 * ALONE - the one that travels server to server and is signed with the shared
 * password. The return address can be typed by hand, so were it the thing
 * granting the package, everyone would take it for free.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sekret-testowy';

    private const PASSWORD = 'haslo-z-ustawien';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hotpay.secret' => self::SECRET,
            'services.hotpay.password' => self::PASSWORD,
        ]);
    }

    private function order(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => 'QR260828120000ABCDEF',
            'user_id' => User::factory()->create()->id,
            'plan' => 'wedding',
            'amount' => 24900,
            'status' => 'new',
        ], $overrides));
    }

    /** A notification shaped exactly the way HotPay computes it. */
    private function notification(Payment $p, array $overrides = []): array
    {
        $data = array_merge([
            'SEKRET' => self::SECRET,
            'KWOTA' => number_format($p->amount / 100, 2, '.', ''),
            'ID_PLATNOSCI' => 'HP-123456',
            'ID_ZAMOWIENIA' => $p->order_id,
            'STATUS' => 'SUCCESS',
            'SECURE' => 'bezpieczny-identyfikator',
        ], $overrides);

        $data['HASH'] = hash('sha256', implode(';', [
            self::PASSWORD, $data['KWOTA'], $data['ID_PLATNOSCI'],
            $data['ID_ZAMOWIENIA'], $data['STATUS'], $data['SECURE'], self::SECRET,
        ]));

        return $data;
    }

    // ================================================== the payment signature

    public function test_the_form_carries_the_signature_the_docs_specify(): void
    {
        $payment = $this->order();
        $fields = HotPay::make()->formFields($payment, 'QRowd — pakiet Wesele', 'https://qrowd.pl/powrot');

        $expected = hash('sha256', implode(';', [
            self::PASSWORD, '249.00', 'QRowd — pakiet Wesele',
            'https://qrowd.pl/powrot', $payment->order_id, self::SECRET,
        ]));

        $this->assertSame($expected, $fields['HASH']);
        $this->assertSame('249.00', $fields['KWOTA'], 'The amount must carry two decimal places.');
        $this->assertSame(self::SECRET, $fields['SEKRET']);
    }

    /** The password serves only to compute the signature and must never reach the form. */
    public function test_the_password_never_leaves_the_server(): void
    {
        $fields = HotPay::make()->formFields($this->order(), 'Pakiet', 'https://qrowd.pl/x');

        $this->assertNotContains(self::PASSWORD, array_values($fields));
    }

    // ==================================================== powiadomienie

    public function test_a_confirmed_payment_grants_the_package(): void
    {
        $party = Party::factory()->create(['plan' => 'free', 'max_guests' => 25]);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        $this->post('/platnosc/hotpay', $this->notification($payment))
            ->assertOk()
            ->assertSee('OK');

        $payment->refresh();
        $party->refresh();

        $this->assertSame('paid', $payment->status);
        $this->assertSame('wedding', $party->plan);
        $this->assertSame(1000, $party->max_guests);
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * The most important test in this file. Without checking the signature anyone
     * could send a request to this address and grant themselves a package free.
     */
    public function test_a_forged_notification_grants_nothing(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        $forged = $this->notification($payment);
        $forged['HASH'] = hash('sha256', 'zgadywanie');

        $this->post('/platnosc/hotpay', $forged)->assertStatus(400);

        $this->assertSame('new', $payment->fresh()->status);
        $this->assertSame('free', $party->fresh()->plan);
    }

    public function test_a_notification_without_a_signature_is_rejected(): void
    {
        $payment = $this->order();

        $without = $this->notification($payment);
        unset($without['HASH']);

        $this->post('/platnosc/hotpay', $without)->assertStatus(400);
        $this->assertSame('new', $payment->fresh()->status);
    }

    /**
     * The amount has to match the order opened on our side. Without that
     * comparison one could pay a zloty for a package costing 249.
     */
    public function test_an_underpaid_amount_grants_no_package(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        // A correctly computed signature, but for a different amount.
        $cheap = $this->notification($payment, ['KWOTA' => '1.00']);

        $this->post('/platnosc/hotpay', $cheap)->assertStatus(400);

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('free', $party->fresh()->plan);
    }

    public function test_a_failed_payment_grants_no_package(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        $this->post('/platnosc/hotpay', $this->notification($payment, ['STATUS' => 'FAILURE']))
            ->assertOk();

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('free', $party->fresh()->plan);
    }

    /** The operator repeats a notification when it gets no answer - the second must change nothing. */
    public function test_a_repeated_notification_is_not_counted_twice(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        $data = $this->notification($payment);

        $this->post('/platnosc/hotpay', $data)->assertOk();
        $first = $payment->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->post('/platnosc/hotpay', $data)->assertOk();

        $this->assertEquals($first, $payment->fresh()->paid_at,
            'Data zaplaty zostala nadpisana przez powtorzone powiadomienie.');
        $this->travelBack();
    }

    public function test_a_notification_for_an_unknown_order_is_rejected(): void
    {
        $payment = $this->order();

        $obce = $this->notification($payment, ['ID_ZAMOWIENIA' => 'QR-NIE-MA-TAKIEGO']);
        // Przeliczamy podpis dla podmienionego numeru.
        $obce['HASH'] = hash('sha256', implode(';', [
            self::PASSWORD, $obce['KWOTA'], $obce['ID_PLATNOSCI'],
            $obce['ID_ZAMOWIENIA'], $obce['STATUS'], $obce['SECURE'], self::SECRET,
        ]));

        $this->post('/platnosc/hotpay', $obce)->assertStatus(404);
    }

    // ==================================================== powrot z bramki

    /**
     * The return address is public and can be typed by hand - it must grant
     * nothing, only show the state from our own database.
     */
    public function test_visiting_the_return_url_grants_nothing(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $payment = $this->order(['user_id' => $party->user_id, 'party_id' => $party->id]);

        $this->get("/platnosc/status/{$payment->order_id}")->assertOk();

        $this->assertSame('new', $payment->fresh()->status);
        $this->assertSame('free', $party->fresh()->plan);
    }

    // ==================================================== zakladanie zamowienia

    public function test_an_order_takes_its_price_from_our_own_pricing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/platnosc/nowa?package=wedding')->assertOk();

        $this->assertSame(24900, Payment::first()->amount);
    }

    public function test_an_unknown_package_creates_no_order(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/platnosc/nowa?package=darmowy-pro-max')
            ->assertSessionHasErrors('package');

        $this->assertSame(0, Payment::count());
    }

    public function test_starting_a_payment_requires_signing_in(): void
    {
        $this->get('/platnosc/nowa?package=wedding')->assertRedirect();
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_does_not_start_without_operator_credentials(): void
    {
        config(['services.hotpay.secret' => null, 'services.hotpay.password' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/platnosc/nowa?package=wedding')
            ->assertStatus(503);
    }

    /** One cannot pay for somebody else's party and raise it. */
    public function test_cannot_create_an_order_for_someone_elses_party(): void
    {
        $foreign = Party::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/platnosc/nowa?package=wedding&party={$foreign->code}")
            ->assertNotFound();
    }
}
