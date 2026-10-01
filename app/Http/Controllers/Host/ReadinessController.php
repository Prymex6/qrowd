<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\PartyReadiness;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReadinessController extends Controller
{
    public function show(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return Inertia::render('Host/Test', [
            'party' => [
                'code' => $party->code,
                'name' => $party->name,
                'type' => $party->type,
                'status' => $party->status,
            ],
            'test' => PartyReadiness::for($party)->check(),
            'links' => [
                'player' => url('/player/'.$party->code.'?token='.$party->player_token),
                'screen' => $party->screenUrl(),
                'guest' => $party->joinUrl(),
            ],
            'qr' => (new Builder(writer: new SvgWriter, data: $party->joinUrl(), size: 260, margin: 0))
                ->build()->getString(),
        ]);
    }

    public function state(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return response()->json(PartyReadiness::for($party)->check());
    }

    /** The host's manual confirmations - sound and adverts can only be checked by ear. */
    public function confirm(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $data = $request->validate([
            'check' => ['required', 'in:audio,ads_none,ads_present'],
        ]);

        match ($data['check']) {
            'audio' => $party->updateSettings(['test_sound_at' => now()->toIso8601String()]),
            'ads_none' => $party->updateSettings(['test_ads' => 'none']),
            'ads_present' => $party->updateSettings(['test_ads' => 'present']),
        };

        return response()->json(PartyReadiness::for($party)->check());
    }

    /** The printable QR code - a card for the table. */
    public function qrDownload(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $png = (new Builder(writer: new PngWriter, data: $party->joinUrl(), size: 900, margin: 30))->build();

        return response($png->getString(), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="qrowd-'.$party->code.'.png"',
        ]);
    }

    public function start(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $party->update(['status' => 'live', 'starts_at' => $party->starts_at ?? now()]);

        return redirect()->route('host.party', $party->code)->with('success', 'Impreza wystartowała!');
    }

    protected function authorizeParty(Request $request, Party $party): void
    {
        abort_unless($party->user_id === $request->user()->id, 403);
    }
}
