<?php

namespace App\Http\Controllers\Host;

use App\Events\QueueUpdated;
use App\Http\Controllers\Controller;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\PartyBlock;
use App\Support\Live;
use App\Support\PartySettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function edit(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        return Inertia::render('Host/Settings', [
            'party' => [
                'code' => $party->code,
                'name' => $party->name,
                'type' => $party->type,
                'mode' => $party->mode,
                'status' => $party->status,
            ],
            'settings' => $party->settings()->toArray(),
            'defaults' => PartySettings::DEFAULTS,
            'blocks' => $party->blocks()->get(['id', 'type', 'value']),
            // This is not a party setting but a host's - one laptop, one
            // library. We show it here because this is the only place where the
            // host thinks about where the music comes from.
            'music' => [
                'folder' => $request->user()->music_folder,
                // THIS host's library alone - files from somebody else's laptop
                // could not be opened on theirs anyway.
                'in_catalogue' => CatalogTrack::where('user_id', $request->user()->id)
                    ->whereNotNull('local_path')->count(),
            ],
        ]);
    }

    public function update(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        $data = $request->validate([
            // rytm
            'set_length' => ['nullable', 'integer', 'min:0', 'max:50'],
            'break_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'max_track_seconds' => ['nullable', 'integer', 'min:60', 'max:900'],
            'min_track_seconds' => ['nullable', 'integer', 'min:0', 'max:300'],
            'max_video_seconds' => ['nullable', 'integer', 'min:60', 'max:1800'],
            'crossfade_seconds' => ['nullable', 'integer', 'min:0', 'max:12'],
            'fast_mode' => ['nullable', 'boolean'],
            'auto_fill' => ['nullable', 'boolean'],
            'resume_from_start' => ['nullable', 'boolean'],
            // kolejka
            'max_active_per_guest' => ['nullable', 'integer', 'min:1', 'max:20'],
            'max_total_per_guest' => ['nullable', 'integer', 'min:1', 'max:200'],
            'aging_strength' => ['nullable', 'in:weak,medium,strong'],
            'artist_cooldown' => ['nullable', 'integer', 'min:0', 'max:30'],
            'guest_cooldown' => ['nullable', 'integer', 'min:0', 'max:20'],
            'repeat_block_hours' => ['nullable', 'integer', 'min:0', 'max:24'],
            'entry_threshold' => ['nullable', 'integer', 'min:0', 'max:20'],
            'auto_expire_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'allow_unvote' => ['nullable', 'boolean'],
            'skip_vote_enabled' => ['nullable', 'boolean'],
            'skip_vote_percent' => ['nullable', 'integer', 'min:50', 'max:100'],
            'skip_vote_min' => ['nullable', 'integer', 'min:1', 'max:50'],
            'show_submitter' => ['nullable', 'boolean'],
            // ekran na sali
            'screen_show_qr' => ['nullable', 'boolean'],
            'screen_show_submitter' => ['nullable', 'boolean'],
            // tresc
            'filter_explicit' => ['nullable', 'boolean'],
            'catalog_only' => ['nullable', 'boolean'],
            'music_source' => ['nullable', 'in:youtube,dysk'],
            'moderation' => ['nullable', 'boolean'],
            'youtube_search_budget' => ['nullable', 'integer', 'min:0', 'max:200'],
            // guest photos
            'photos_enabled' => ['nullable', 'boolean'],
            'photo_moderation' => ['nullable', 'boolean'],
            'photos_visible_to_guests' => ['nullable', 'boolean'],
            'photo_captions' => ['nullable', 'boolean'],
            'photos_on_screen' => ['nullable', 'boolean'],
            'photo_max_per_guest' => ['nullable', 'integer', 'min:1', 'max:200'],
            'photo_retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $party->updateSettings(array_filter($data, fn ($v) => $v !== null));

        Live::send(new QueueUpdated($party, 'settings'));

        return back()->with('success', 'Ustawienia zapisane. Działają od razu.');
    }

    public function addBlock(Request $request, Party $party)
    {
        abort_unless($party->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'type' => ['required', 'in:track,artist,genre,keyword'],
            'value' => ['required', 'string', 'max:190'],
        ]);

        $party->blocks()->firstOrCreate($data);

        return back();
    }

    public function removeBlock(Request $request, Party $party, PartyBlock $block)
    {
        abort_unless($party->user_id === $request->user()->id, 403);
        abort_unless($block->party_id === $party->id, 404);

        $block->delete();

        return back();
    }
}
