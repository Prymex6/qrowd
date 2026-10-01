<?php

return [

    /*
     * Whether anyone at all may open an account.
     *
     * Every new account reaches for OUR YouTube quota - 10,000 units a day for
     * the whole service, with no way to buy more. Until payments start
     * filtering the traffic, open registration is an invitation to exhaust the
     * quota for everybody at once.
     *
     * "closed" does not switch off signing in - accounts are then created with
     * php artisan qrowd:accounts or by hand in the database.
     */
    'registration' => env('QROWD_REGISTRATION', 'open'),

    /*
     * How many parties at once an account on the free package may run.
     *
     * Only ongoing and scheduled ones count - ended parties block nothing,
     * because the host has every right to come back to them for the summary
     * and the photos.
     */
    'free_parties' => (int) env('QROWD_FREE_PARTIES', 2),

];
