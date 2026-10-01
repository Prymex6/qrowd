<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The photos guests take.
 *
 * A photo arrives already shrunk by the browser (about 300 KB rather than the
 * four megabytes the camera makes). That is not an optimisation but a condition
 * of working at all: the Wi-Fi in a wedding venue barely holds up the music
 * queue, and a hundred guests sending full-size photos would choke it entirely.
 */
class PhotoController extends Controller
{
    /** The list of photos a guest can see. */
    public function index(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');
        $settings = $party->settings();

        if (! $settings->bool('photos_enabled')) {
            return response()->json(['photos' => [], 'turned_off' => true]);
        }

        // The host can close the gallery - then everyone sees their own photos only.
        $shared = $settings->bool('photos_visible_to_guests');

        $photos = $party->photos()
            ->with('guest')
            ->where('status', 'visible')
            ->when(! $shared && $guest, fn ($q) => $q->where('guest_id', $guest->id))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json([
            'photos' => $photos->map(fn (Photo $z) => $this->forViewer($z, $guest))->all(),
            'mine' => $guest ? $party->photos()->where('guest_id', $guest->id)->count() : 0,
            'limit' => $settings->int('photo_max_per_guest'),
            'moderation' => $settings->bool('photo_moderation'),
            'shared' => $shared,
        ]);
    }

    public function store(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');
        $settings = $party->settings();

        if (! $guest) {
            return response()->json(['error' => 'Dolacz do imprezy najpierw.'], 403);
        }

        if ($guest->is_banned) {
            return response()->json(['error' => 'Organizator wstrzymał Twój dostęp.'], 422);
        }

        if (! $settings->bool('photos_enabled')) {
            return response()->json(['error' => 'Organizator wyłączył zdjęcia na tej imprezie.'], 422);
        }

        if (! $party->acceptsSubmissions()) {
            return response()->json(['error' => 'Impreza jest już zamknięta.'], 422);
        }

        $limit = $settings->int('photo_max_per_guest');

        if ($party->photos()->where('guest_id', $guest->id)->count() >= $limit) {
            return response()->json([
                'error' => "Wykorzystałeś swój limit {$limit} zdjęć na tej imprezie.",
            ], 422);
        }

        $data = $request->validate([
            // 4 MB is headroom - the browser sends about 300 KB. If somebody went
            // around our code and sent the original, it would not fit anyway.
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:140'],
            'width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ], [], ['photo' => 'zdjęcie']);

        $file = $data['photo'];
        $name = Str::uuid().'.'.($file->extension() ?: 'jpg');

        $file->storeAs(Photo::directory($party), $name);

        $photo = $party->photos()->create([
            'guest_id' => $guest->id,
            'file' => $name,
            'caption' => $settings->bool('photo_captions') ? ($data['caption'] ?? null) : null,
            'size' => $file->getSize(),
            'width' => $data['width'] ?? 0,
            'height' => $data['height'] ?? 0,
            // At a wedding a photo goes to the host first. Somebody will send
            // something unsuitable - not "may", but "will".
            'status' => $settings->bool('photo_moderation') ? 'pending' : 'visible',
        ]);

        return response()->json([
            'ok' => true,
            'moderation' => $photo->status === 'pending',
            'photo' => $this->forViewer($photo->load('guest'), $guest),
        ]);
    }

    /** A guest deletes their own photo - a data-protection requirement, not a courtesy. */
    public function destroy(Request $request, Party $party, Photo $photo)
    {
        $guest = $request->attributes->get('guest');

        abort_unless($photo->party_id === $party->id, 404);

        if (! $guest || $photo->guest_id !== $guest->id) {
            return response()->json(['error' => 'To nie jest Twoje zdjęcie.'], 403);
        }

        $photo->deleteWithFiles();

        return response()->json(['ok' => true]);
    }

    /**
     * Serving the file.
     *
     * The photos live outside public/, so the address alone is not enough - you
     * have to belong to the party or be its host. Otherwise guessing an id would
     * be enough to browse somebody else's wedding.
     */
    public function show(Request $request, Party $party, Photo $photo)
    {
        abort_unless($photo->party_id === $party->id, 404);

        $guest = $request->attributes->get('guest');
        $host = $request->user() && $request->user()->id === $party->user_id;

        // The screen on the television has neither a guest cookie nor a host
        // session. Rather than open the photos to anyone who knows the party code
        // - and the code is printed on cards on the tables, so it is a poor secret
        // where people's likenesses are concerned - the screen is given
        // cryptographically signed addresses, valid for a few hours.
        $signed = $request->hasValidSignature();

        abort_unless($guest || $host || $signed, 403);

        // Only the host and the author see an unapproved photo - even with a signed address.
        if ($photo->status !== 'visible') {
            abort_unless($host || ($guest && $photo->guest_id === $guest->id), 403);
        }

        $path = $photo->path($request->boolean('mini'));

        abort_unless(Storage::exists($path), 404);

        return response()->file(Storage::path($path), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function forViewer(Photo $z, $guest): array
    {
        return [
            'id' => $z->id,
            'url' => route('guest.photos.file', [$z->party->code, $z->id]),
            'caption' => $z->caption,
            'author' => $z->guest?->nickname,
            'avatar' => $z->guest?->avatar,
            'when' => $z->created_at->format('H:i'),
            'mine' => $guest && $z->guest_id === $guest->id,
            'status' => $z->status,
        ];
    }
}
