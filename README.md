# QRowd

**Your guests are the DJ.**

Scan a QR code, add a track, upvote someone else's — the laptop plays it on its own.
No app to install, no one hunched over the aux cable, and nobody has to ask the DJ for
anything.

<p>
  <img alt="PHP 8.2" src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white">
  <img alt="Laravel 12" src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white">
  <img alt="Vue 3" src="https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vuedotjs&logoColor=white">
  <img alt="Inertia 3" src="https://img.shields.io/badge/Inertia-3-9553E9">
  <img alt="Tailwind 4" src="https://img.shields.io/badge/Tailwind-4-06B6D4?logo=tailwindcss&logoColor=white">
  <img alt="404 tests" src="https://img.shields.io/badge/tests-404-success">
</p>

---

## At a glance

|               |                                                           |
| ------------- | --------------------------------------------------------- |
| **Stack**     | Laravel 12 · Inertia 3 · Vue 3 · Tailwind 4 · Reverb · MySQL |
| **Surfaces**  | guest phone (PWA) · host panel · party screen              |
| **Size**      | ~10.5k lines of PHP, ~5k of Vue                            |
| **Surface**   | 23 controllers · 14 models · 14 services · 19 pages        |
| **Routes**    | 72                                                          |
| **Schema**    | 28 migrations                                               |
| **Tests**     | 317 PHPUnit · 87 Vitest                                     |
| **Realtime**  | Laravel Reverb (WebSockets), polling as the fallback        |

---

## Three surfaces

**The guest's phone.** A PWA opened from a QR code — no install, no account, a nickname
and you are in. Search, add a track, hype someone else's, see the queue reorder itself
live, vote to skip, send a photo to the party screen.

**The host's panel.** The queue with everything the algorithm is thinking, manual
reordering, pinning, bans, content filters, the energy curve of the evening, and a
settings screen where every weight in the ranking is a slider rather than a constant in
the source.

**The party screen.** What goes on the TV or the projector: what is playing now, what is
next, the QR code to join, and photos guests have sent.

---

## The queue algorithm

This is the part worth reading the code for: [`app/Services/RankingEngine.php`](app/Services/RankingEngine.php).

Ranking by votes alone does not work. The first track thrown in collects the most hypes
and then blocks the queue all evening, while nothing new ever surfaces. The score has to
age:

```
score = hype × weight
      + waiting_time × ageing
      − artist_penalty      (this artist played recently)
      − submitter_penalty   (this person had a track a moment ago)
```

Anything the host pins skips all of it.

Three consequences that matter more than the formula:

- **A quiet track eventually wins.** Two hypes and forty minutes of waiting beats nine
  hypes and three minutes, which is what stops the loudest group in the room owning the
  evening.
- **The same artist does not play twice in a row**, and neither does the same guest get
  two tracks back to back — both are cooldowns that decay rather than hard blocks.
- **Scoring is a pure function.** No database, no clock. That is what makes it testable
  against a table of cases, and explainable to the host in the panel: every number on
  that screen can be traced back to a term in the formula.

Once chosen, the next track is frozen for a few seconds so it cannot change under
someone's feet in the last moment before it plays.

---

## Where the music comes from

YouTube, through the IFrame API. Chosen for catalogue rather than quality: Polish wedding
repertoire, remixes and mashups exist there and do not exist on Spotify, and the host does
not need a paid account with anyone.

**The API is free. The constraint is not money — it is the daily search quota, and no
amount of money raises it.** That shapes the architecture:

- the first layer of search is a **local catalogue** built from what has been played
  before, so a popular track costs nothing to find,
- YouTube is only asked when the catalogue has no answer,
- results are written back into the catalogue, so the longer the system runs, the less
  often it has to ask.

[`app/Services/MusicSearch.php`](app/Services/MusicSearch.php) and
[`app/Services/CatalogImporter.php`](app/Services/CatalogImporter.php).

---

## Realtime

Reverb carries queue changes to every phone in the room over WebSockets, so a hype shows
up on fifty screens at once rather than on the next poll.

Polling stays as the fallback, because a venue's Wi-Fi is not a datacentre network and a
guest whose socket drops has to keep seeing a working queue. The two paths converge on one
guarded assignment in the guest app: a reply that does not carry a party is ignored rather
than rendered, because a screen that blanks mid-party blanks for everyone at once.

---

## Anti-abuse

A room full of strangers with an open text field needs more than good intentions:

- one hype per track per device, tracked by a local identifier and a browser fingerprint,
- rate limits on how much one guest can add per minute and per evening,
- the party code dies with the party, so the neighbour cannot rejoin tomorrow,
- a one-click device ban for the host,
- a nickname filter, and a moderation mode for formal events,
- a log of who added what, so the host always knows who to blame.

---

## Getting it running

Requires **PHP 8.2+**, **MySQL 8**, **Node 20+** and Composer.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

A YouTube Data API key goes in `.env`; without one the local catalogue still works and
search falls back to what has already been played.

For the realtime layer:

```bash
php artisan reverb:start
```

### Showing it to someone

The app and the WebSocket server listen on different ports, so exposing a local instance
publicly needs **two tunnels** — one to the application, one to Reverb. A single quick
tunnel forwards a single port, and a demo with the second one missing looks like a queue
that never updates.

---

## Tests

```bash
php artisan test     # 317 tests
npm run test         # 87 tests
npm run test:all     # both
```

The PHP suite runs against a real MySQL database (`qrowd_test`, configured in
`phpunit.xml`). The Vue suite runs in Vitest with a mocked transport.

What they cover is deliberately lopsided. The ranking engine is a pure function and gets
a table of cases — a pinned track beating everything, an old submission climbing on little
support, an artist dropping down after playing. That is where the product lives, so that
is where the tests are.

---

## Layout

```
app/
  Http/Controllers/Guest/     the phone: joining, queue, hype, photos
  Http/Controllers/Host/      the panel: settings, moderation, the evening
  Services/RankingEngine.php  the score
  Services/QueueManager.php   what plays next
  Services/PartyConductor.php the evening's state machine
  Services/MusicSearch.php    catalogue first, YouTube second
  Services/SkipVoting.php     the room votes a track off
resources/js/Pages/Guest/     the PWA
resources/js/Pages/Host/      the panel
tests/Feature/                PHPUnit
tests/js/                     Vitest
```

---

## Conventions

**Scoring is pure, side effects are not.** Anything that decides an order takes values and
returns a number. Anything that touches the database does not decide an order.

**Settings are data, not constants.** Every weight in the ranking is a row the host can
change, because the right ageing rate for a wedding is not the right one for a house party.

**The guest screen never blanks.** Every payload that could replace the state is checked
before it does, and a failed refresh leaves the last good state on screen.

**Comments say why, not what.** The ranking engine explains why votes alone do not work;
it does not explain that it multiplies two numbers.
