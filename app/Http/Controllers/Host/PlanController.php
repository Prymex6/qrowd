<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\HotPay;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PlanController extends Controller
{
    /**
     * The price list.
     *
     * A couple buys ONCE in their life, so the party packages are one-off.
     * The subscription is for hosts-for-hire and venues that run a wedding
     * every weekend. Pushing a subscription onto a couple ends in a forgotten
     * renewal, a refund and a bad word in the wedding group - which is exactly
     * where the selling happens.
     */
    public const PACKAGES = [
        'free' => [
            'name' => 'Darmowy', 'price' => 0, 'period' => 'na zawsze', 'icon' => '🍻',
            'for' => 'Domówki i urodziny', 'max_guests' => 25,
            'features' => ['do 25 gości', 'Kolejka i głosowanie', 'Ekran na telewizor'],
            'missing' => ['Logo QRowd na ekranie', 'Bez harmonogramu'],
        ],
        'party' => [
            'name' => 'Impreza', 'price' => 99, 'period' => 'jednorazowo', 'icon' => '🎂',
            'for' => '30-tka, firmówka', 'max_guests' => 60,
            'features' => ['do 60 gości', 'Wszystkie ustawienia', 'Ekran bez naszego logo', 'Moderacja wrzutek'],
            'missing' => [],
        ],
        'wedding' => [
            'name' => 'Wesele', 'price' => 249, 'period' => 'jednorazowo', 'icon' => '💍',
            'for' => 'Główny produkt', 'max_guests' => 1000, 'featured' => true,
            'features' => ['Bez limitu gości', 'Harmonogram wesela', 'Imiona i grafika na ekranie',
                'Playlista pamiątkowa', 'Wsparcie w dniu imprezy', 'Faktura'],
            'missing' => [],
        ],
        'pro' => [
            'name' => 'Pro', 'price' => 79, 'period' => 'miesięcznie', 'icon' => '🎧',
            'for' => 'Wodzireje i sale', 'max_guests' => 1000, 'subscription' => true,
            'features' => ['Nielimitowane imprezy', 'Twoje logo zamiast naszego', 'Statystyki zbiorcze', 'Faktura co miesiąc'],
            'missing' => [],
        ],
    ];

    public function show(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        return Inertia::render('Host/Packages', [
            'party' => ['code' => $party->code, 'name' => $party->name, 'plan' => $party->plan, 'max_guests' => $party->max_guests],
            'packages' => collect(self::PACKAGES)->map(fn ($p, $id) => $p + ['id' => $id])->values(),
            // Whether a paid package can be bought at all right now.
            'payments_ready' => HotPay::make()->isConfigured(),
        ]);
    }

    public function select(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        $data = $request->validate(['package' => ['required', 'in:free,party,wedding,pro']]);
        $package = self::PACKAGES[$data['package']];

        // The free one switches on straight away. Paid ones go through the
        // payment operator, which may not be configured yet - see below.
        if ($package['price'] === 0) {
            $party->update(['plan' => $data['package'], 'max_guests' => $package['max_guests']]);

            return back()->with('success', 'Pakiet zmieniony na '.$package['name'].'.');
        }

        if (! HotPay::make()->isConfigured()) {
            return back()->with('error',
                'Płatności nie są jeszcze uruchomione — brakuje danych HotPay. '
                .'Do testów możesz włączyć pakiet ręcznie: php artisan qrowd:plan '.$party->code.' '.$data['package']
            );
        }

        // A paid package goes through the gateway. Choosing it grants nothing
        // by itself - only a confirmed notification of the transfer does.
        return redirect()->route('payment.new', [
            'package' => $data['package'],
            'party' => $party->code,
        ]);
    }
}
