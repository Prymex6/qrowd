<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\PartySummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SummaryController extends Controller
{
    public function show(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        return Inertia::render('Host/Summary', [
            'summary' => PartySummary::for($party)->build(),
        ]);
    }

    /** Playlista pamiatkowa - lista utworow do zapisania. */
    public function playlist(Request $request, Party $party): StreamedResponse
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        $data = PartySummary::for($party)->build();

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            // A BOM, so Excel does not mangle the Polish characters.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Godzina', 'Tytul', 'Wykonawca', 'Hype', 'Wrzucil', 'Link']);

            foreach ($data['playlist'] as $u) {
                fputcsv($out, [
                    $u['hour'], $u['title'], $u['artist'], $u['hype'], $u['submittedBy'],
                    'https://youtu.be/'.$u['youtube_id'],
                ]);
            }
            fclose($out);
        }, 'qrowd-'.$party->code.'-playlista.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
