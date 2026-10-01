<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Services\PartyState;
use App\Support\ReverbConfig;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Inertia\Inertia;

/**
 * The party screen - a television or a projector in the room.
 *
 * Designed to be read from ten metres, so we treat it as a billboard rather than
 * a web page.
 */
class ScreenController extends Controller
{
    public function show(Party $party)
    {
        return Inertia::render('Screen/Main', [
            'state' => PartyState::for($party)->forScreen(),
            'qr' => $this->qrSvg($party->joinUrl()),
            'reverb' => ReverbConfig::forBrowser(),
        ]);
    }

    public function state(Party $party)
    {
        return response()->json(PartyState::for($party)->forScreen());
    }

    /**
     * The QR code as SVG - it scales to any television without blurring.
     *
     * endroid/qr-code version 6 dropped the fluent Builder::create() interface in
     * favour of a plain constructor with named arguments.
     */
    private function qrSvg(string $url): string
    {
        return (new Builder(
            writer: new SvgWriter,
            data: $url,
            size: 400,
            margin: 0,
        ))->build()->getString();
    }
}
