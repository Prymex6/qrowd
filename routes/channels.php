<?php

use Illuminate\Support\Facades\Broadcast;

// The party channels are public - a guest has no account, and the party code is
// itself the secret (it expires with the party).
Broadcast::channel('party.{code}', fn () => true);
