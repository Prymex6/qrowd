<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\Guest\GuestAppController;
use App\Http\Controllers\Guest\GuestQueueController;
use App\Http\Controllers\Guest\JoinController;
use App\Http\Controllers\Guest\PhotoController as GuestPhotoController;
use App\Http\Controllers\Guest\SearchController;
use App\Http\Controllers\Host\DashboardController;
use App\Http\Controllers\Host\LiveController;
use App\Http\Controllers\Host\MusicFolderController;
use App\Http\Controllers\Host\PartyController;
use App\Http\Controllers\Host\PhotoController as HostPhotoController;
use App\Http\Controllers\Host\PlanController;
use App\Http\Controllers\Host\ReadinessController;
use App\Http\Controllers\Host\ScheduleController;
use App\Http\Controllers\Host\SettingsController;
use App\Http\Controllers\Host\SummaryController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ScreenController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Strona
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => Inertia::render('Home/Landing'))->name('home');

/*
|--------------------------------------------------------------------------
| The guest - a phone, entering through the QR code
|--------------------------------------------------------------------------
| Short addresses, because people copy /j/ABC123 off a card on the table.
*/

Route::middleware('party.guest')->group(function () {
    Route::get('/j/{party:code}', [JoinController::class, 'show'])->name('guest.join');
    Route::post('/j/{party:code}', [JoinController::class, 'join'])->name('guest.join.save');

    Route::get('/p/{party:code}', [GuestAppController::class, 'show'])->name('guest.app');

    Route::prefix('api/p/{party:code}')->name('api.guest.')->group(function () {
        Route::get('state', [GuestAppController::class, 'state'])->name('state');
        Route::get('search', [SearchController::class, 'suggest'])->name('search');
        Route::get('search-youtube', [SearchController::class, 'youtube'])->name('search.youtube');

        Route::post('queue', [GuestQueueController::class, 'store'])->name('queue.add');
        Route::post('queue/{item}/hype', [GuestQueueController::class, 'hype'])->name('queue.hype');
        Route::delete('queue/{item}/hype', [GuestQueueController::class, 'unhype'])->name('queue.unhype');
        Route::post('skip-vote', [GuestQueueController::class, 'skipVote'])->name('skip.vote');

        Route::get('photos', [GuestPhotoController::class, 'index'])->name('photos');
        Route::post('photos', [GuestPhotoController::class, 'store'])->name('photos.add');
        Route::delete('photos/{photo}', [GuestPhotoController::class, 'destroy'])->name('photos.delete');
    });

    // The photo file lies outside public/ - access for a guest of this party or
    // its host alone. The address by itself is not enough.
    Route::get('/z/{party:code}/{photo}', [GuestPhotoController::class, 'show'])->name('guest.photos.file');
});

/*
|--------------------------------------------------------------------------
| Ekran imprezowy - telewizor / rzutnik
|--------------------------------------------------------------------------
*/

Route::get('/screen/{party:code}', [ScreenController::class, 'show'])->name('screen');
Route::get('/api/screen/{party:code}', [ScreenController::class, 'state'])->name('screen.state');

/*
|--------------------------------------------------------------------------
| Urzadzenie grajace - dostep tylko z tokenem parowania
|--------------------------------------------------------------------------
*/

Route::get('/player/{party:code}', [PlayerController::class, 'show'])->name('player');

Route::prefix('api/player/{party:code}')->name('api.player.')->group(function () {
    Route::get('state', [PlayerController::class, 'state'])->name('state');
    Route::post('finished', [PlayerController::class, 'finished'])->name('finished');
    Route::post('skip', [PlayerController::class, 'skip'])->name('skip');

});

/*
|--------------------------------------------------------------------------
| Organizator
|--------------------------------------------------------------------------
*/

// Laravel's own 'guest' middleware - it turns a signed-in host away from the
// sign-in form. Not to be confused with 'party.guest', which recognises a
// party guest by their device cookie.
Route::middleware('guest')->group(function () {
    Route::get('/logowanie', [AuthController::class, 'show'])->name('login');
    Route::post('/logowanie', [AuthController::class, 'login']);
    Route::post('/rejestracja', [AuthController::class, 'register'])->name('register');
});

Route::post('/wyloguj', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// The documents. Their text lives in the code, so a change in how the program
// behaves and a change in the document ship in the same deployment.
Route::get('/regulamin', [DocumentsController::class, 'terms'])->name('terms');
Route::get('/prywatnosc', [DocumentsController::class, 'privacy'])->name('privacy');

/*
 * Payments.
 *
 * "nowa" opens an order and shows a page that carries itself to the gateway.
 * "hotpay" is the operator's notification - it travels server to server, so
 * there is no session and no CSRF token; the signature computed from the shared
 * password is what proves it genuine. "status" is public on purpose: the
 * customer comes back from the gateway and has to see the state of their order,
 * even if their session expired on the way.
 */
Route::get('/platnosc/nowa', [PaymentController::class, 'start'])
    ->middleware('auth')->name('payment.new');
Route::post('/platnosc/hotpay', [PaymentController::class, 'notification'])->name('payment.hotpay');
Route::get('/platnosc/status/{orderId}', [PaymentController::class, 'result'])->name('payment.return');

Route::middleware('auth')->prefix('host')->name('host.')->group(function () {
    // The listing of tracks on disk is sent by the player's BROWSER - the server
    // never sees or reads the files. Only metadata lands here.
    Route::post('/muzyka/indeks', [MusicFolderController::class, 'index'])->name('music.index');
    Route::delete('/muzyka', [MusicFolderController::class, 'forget'])->name('music.forget');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/nowa', [PartyController::class, 'create'])->name('new');
    Route::post('/nowa', [PartyController::class, 'store'])->name('new.save');

    Route::prefix('{party:code}')->group(function () {
        Route::get('/', [LiveController::class, 'show'])->name('party');
        Route::delete('/', [PartyController::class, 'destroy'])->name('party.delete');

        Route::get('/ustawienia', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/ustawienia', [SettingsController::class, 'update'])->name('settings.save');
        Route::post('/blokady', [SettingsController::class, 'addBlock'])->name('blocks.add');
        Route::delete('/blokady/{block}', [SettingsController::class, 'removeBlock'])->name('blocks.delete');

        Route::get('/test', [ReadinessController::class, 'show'])->name('test');
        Route::get('/test/state', [ReadinessController::class, 'state'])->name('test.state');
        Route::post('/test/confirm', [ReadinessController::class, 'confirm'])->name('test.confirm');
        Route::post('/test/start', [ReadinessController::class, 'start'])->name('test.start');
        Route::get('/qr.png', [ReadinessController::class, 'qrDownload'])->name('qr');

        Route::get('/zdjecia.zip', [HostPhotoController::class, 'download'])->name('photos.zip');

        Route::get('/podsumowanie', [SummaryController::class, 'show'])->name('summary');
        Route::get('/podsumowanie/playlista', [SummaryController::class, 'playlist'])->name('summary.playlist');

        Route::get('/pakiety', [PlanController::class, 'show'])->name('packages');
        Route::post('/pakiety', [PlanController::class, 'select'])->name('packages.select');

        Route::get('/harmonogram', [ScheduleController::class, 'edit'])->name('schedule');
        Route::post('/harmonogram', [ScheduleController::class, 'store'])->name('schedule.add');
        Route::post('/harmonogram/szablon', [ScheduleController::class, 'loadTemplate'])->name('schedule.template');
        Route::delete('/harmonogram/{item}', [ScheduleController::class, 'destroy'])->name('schedule.delete');

        // Live control - called from the panel by fetch, answers with JSON state.
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('state', [LiveController::class, 'state'])->name('state');
            Route::post('status', [LiveController::class, 'status'])->name('status');
            Route::post('skip', [LiveController::class, 'skip'])->name('skip');
            Route::post('queue/{item}/veto', [LiveController::class, 'veto'])->name('veto');
            Route::post('queue/{item}/pin', [LiveController::class, 'pin'])->name('pin');
            Route::post('queue/{item}/approve', [LiveController::class, 'approve'])->name('approve');
            Route::post('queue/{item}/reject', [LiveController::class, 'reject'])->name('reject');
            Route::post('guests/{guest}/ban', [LiveController::class, 'ban'])->name('ban');
            Route::post('guests/{guest}/in-room', [LiveController::class, 'countInRoom'])->name('guests.in-room');

            Route::get('photos', [HostPhotoController::class, 'index'])->name('photos');
            Route::post('photos/{photo}/approve', [HostPhotoController::class, 'approve'])->name('photos.approve');
            Route::post('photos/{photo}/reject', [HostPhotoController::class, 'reject'])->name('photos.reject');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Panel administracyjny (nasz, wewnetrzny)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'admin'])->get('/admin', [AdminController::class, 'index'])->name('admin');
