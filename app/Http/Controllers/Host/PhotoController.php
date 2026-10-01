<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class PhotoController extends Controller
{
    /** Every photo from the party - the host sees the pending ones too. */
    public function index(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $photos = $party->photos()->with('guest')->latest('id')->get();

        return response()->json([
            'pending' => $this->mapPhotos($photos->where('status', 'pending')),
            'visible' => $this->mapPhotos($photos->where('status', 'visible')),
            'stats' => [
                'total' => $photos->count(),
                'authors' => $photos->pluck('guest_id')->filter()->unique()->count(),
                'size_mb' => round($photos->sum('size') / 1048576, 1),
            ],
        ]);
    }

    public function approve(Request $request, Party $party, Photo $photo)
    {
        $this->authorizeParty($request, $party);
        abort_unless($photo->party_id === $party->id, 404);

        $photo->update(['status' => 'visible']);

        return $this->index($request, $party);
    }

    public function reject(Request $request, Party $party, Photo $photo)
    {
        $this->authorizeParty($request, $party);
        abort_unless($photo->party_id === $party->id, 404);

        // A rejected photo is deleted from the disk at once - keeping photos the
        // host does not want has no justification and takes up space.
        $photo->deleteWithFiles();

        return $this->index($request, $party);
    }

    /**
     * Every photo in one ZIP file.
     *
     * This is the whole value of the feature for the couple: after the wedding
     * they get a complete set of photos from guests that no photographer took.
     */
    public function download(Request $request, Party $party): StreamedResponse
    {
        $this->authorizeParty($request, $party);

        $photos = $party->photos()->with('guest')->where('status', 'visible')->get();

        abort_if($photos->isEmpty(), 404, 'Brak zdjęć do pobrania.');

        $tempFile = tempnam(sys_get_temp_dir(), 'qrowd');
        $zip = new ZipArchive;
        $zip->open($tempFile, ZipArchive::OVERWRITE);

        foreach ($photos as $i => $z) {
            $path = Storage::path($z->path());

            if (! is_file($path)) {
                continue;
            }

            // The name says who took the photo and when - otherwise the couple
            // get a hundred files with random names.
            $name = sprintf(
                '%03d_%s_%s.jpg',
                $i + 1,
                $z->created_at->format('H-i'),
                $z->guest?->nickname ? preg_replace('/[^\p{L}\p{N}]+/u', '', $z->guest->nickname) : 'guest'
            );

            $zip->addFile($path, $name);
        }

        $zip->close();

        return response()->streamDownload(function () use ($tempFile) {
            readfile($tempFile);
            @unlink($tempFile);
        }, 'qrowd-'.$party->code.'-zdjecia.zip', ['Content-Type' => 'application/zip']);
    }

    private function mapPhotos($collection): array
    {
        return $collection->map(fn (Photo $z) => [
            'id' => $z->id,
            'url' => route('guest.photos.file', [$z->party->code, $z->id]),
            'caption' => $z->caption,
            'author' => $z->guest?->nickname,
            'avatar' => $z->guest?->avatar,
            'when' => $z->created_at->diffForHumans(),
        ])->values()->all();
    }

    private function authorizeParty(Request $request, Party $party): void
    {
        abort_unless($party->user_id === $request->user()?->id, 403);
    }
}
