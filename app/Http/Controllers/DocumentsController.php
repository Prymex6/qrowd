<?php

namespace App\Http\Controllers;

use App\Support\PartySettings;
use Inertia\Inertia;

/**
 * The terms of service and the privacy policy.
 *
 * Their text lives in the code rather than in the database, and deliberately so:
 * these are documents that change rarely and have to be versioned with the
 * application. When the program's behaviour changes, the change to the document
 * ships in the same deployment.
 *
 * The photo retention is read from the settings instead of being typed in as a
 * fixed number - the document is to tell the truth about what the code does.
 */
class DocumentsController extends Controller
{
    /** Data ostatniej zmiany tresci - aktualizowac przy kazdej edycji. */
    private const EFFECTIVE_FROM = '2026-08-28';

    public function terms()
    {
        return Inertia::render('Home/Document', [
            'title' => 'Regulamin',
            'effectiveFrom' => self::EFFECTIVE_FROM,
            'intro' => 'Zasady korzystania z QRowd. Krótko i bez kruczków — '
                .'jeśli coś jest niejasne, napisz, poprawimy.',
            'sections' => $this->termsSections(),
        ]);
    }

    public function privacy()
    {
        return Inertia::render('Home/Document', [
            'title' => 'Polityka prywatności',
            'effectiveFrom' => self::EFFECTIVE_FROM,
            'intro' => 'Co zbieramy, po co, jak długo trzymamy i jak to usunąć. '
                .'Bez zdań, których nikt nie czyta.',
            'sections' => $this->privacySections(),
        ]);
    }

    // ------------------------------------------------------------ regulamin

    private function termsSections(): array
    {
        return [
            [
                'title' => 'Czym jest QRowd',
                'paragraphs' => [
                    'QRowd pozwala gościom imprezy wybierać muzykę: skanują kod QR, wyszukują utwór '
                        .'i głosują na to, co ma zagrać. Kolejką steruje organizator ze swojego panelu.',
                    'Usługę świadczymy w modelu „tak jak jest". Nie zastępujemy DJ-a i nie gwarantujemy, '
                        .'że każdy utwór da się odtworzyć — to zależy od YouTube albo od plików organizatora.',
                ],
            ],
            [
                'title' => 'Kto za co odpowiada',
                'paragraphs' => [
                    'Organizator odpowiada za swoją imprezę: kogo zaprasza, jakie ustawienia włącza '
                        .'i co dzieje się na sali. To on jest administratorem danych swoich gości.',
                    'My odpowiadamy za działanie aplikacji i za bezpieczeństwo danych, które dla niego '
                        .'przechowujemy.',
                    'Muzyka odtwarzana jest z YouTube albo z plików organizatora. Nie hostujemy, '
                        .'nie pobieramy i nie udostępniamy nagrań — odtwarzacz łączy się bezpośrednio '
                        .'z YouTube, a pliki z dysku organizatora nigdy nie opuszczają jego komputera.',
                    'Za prawo do korzystania z własnych plików odpowiada organizator. Odtwarzanie muzyki '
                        .'publicznie może wymagać opłat na rzecz organizacji zbiorowego zarządzania '
                        .'(w Polsce m.in. ZAiKS) — to obowiązek organizatora imprezy, nie nasz.',
                ],
            ],
            [
                'title' => 'Pakiety i płatności',
                'paragraphs' => [
                    'Pakiet darmowy działa bez opłat, z ograniczeniami opisanymi w cenniku. '
                        .'Pakiety płatne kupuje się jednorazowo na konkretną imprezę albo w abonamencie.',
                    'Płatności obsługuje HotPay. Nie przechowujemy danych kart ani danych logowania '
                        .'do banku — nie przechodzą one przez nasze serwery.',
                    'Pakiet aktywuje się po potwierdzeniu wpłaty przez operatora, zwykle w kilkanaście sekund.',
                ],
            ],
            [
                'title' => 'Odstąpienie i reklamacje',
                'paragraphs' => [
                    'Kupując pakiet na konkretną imprezę, prosimy o zgodę na rozpoczęcie świadczenia '
                        .'przed upływem 14 dni — bez tego nie moglibyśmy uruchomić usługi na czas. '
                        .'Zgoda oznacza utratę prawa odstąpienia po pełnym wykonaniu usługi.',
                    'Jeśli impreza się nie odbyła albo usługa nie zadziałała z naszej winy — zwracamy '
                        .'pieniądze. Napisz, rozpatrzymy w ciągu 14 dni.',
                ],
            ],
            [
                'title' => 'Czego robić nie wolno',
                'paragraphs' => [
                    'Wgrywać treści, do których nie ma się praw, ani obraźliwych czy nielegalnych.',
                    'Obchodzić limitów pakietu, w tym zakładać wielu kont, żeby ominąć ograniczenia.',
                    'Zakłócać działania usługi ani sięgać po dane innych organizatorów i gości.',
                    'Konto łamiące te zasady możemy zablokować. Za opłacony, a niewykorzystany pakiet '
                        .'zwracamy proporcjonalną część.',
                ],
            ],
            [
                'title' => 'Zmiany regulaminu',
                'paragraphs' => [
                    'O zmianach informujemy z wyprzedzeniem na adres e-mail konta. '
                        .'Do imprez już opłaconych stosujemy regulamin z chwili zakupu.',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------ prywatnosc

    private function privacySections(): array
    {
        $retention = PartySettings::DEFAULTS['photo_retention_days'];

        return [
            [
                'title' => 'Kto jest administratorem',
                'paragraphs' => [
                    'Danych organizatorów (konto, e-mail, historia płatności) — my.',
                    'Danych gości imprezy (ksywka, wrzutki, zdjęcia) — organizator tej imprezy. '
                        .'My przetwarzamy je w jego imieniu, jako podmiot przetwarzający.',
                ],
            ],
            [
                'title' => 'Co zbieramy od organizatora',
                'paragraphs' => [
                    'Imię lub nazwę, adres e-mail, opcjonalnie telefon i nazwę firmy — do prowadzenia konta.',
                    'Historię płatności i numery zamówień — bo wymagają tego przepisy podatkowe.',
                    'Hasło przechowujemy wyłącznie w postaci nieodwracalnego skrótu. Nie znamy go '
                        .'i nie umiemy odtworzyć.',
                ],
            ],
            [
                'title' => 'Co zbieramy od gościa',
                'paragraphs' => [
                    'Ksywkę i awatar — te, które sam poda. Nie prosimy o imię, nazwisko ani e-mail.',
                    'Identyfikator urządzenia w ciasteczku — po to, żeby wiedzieć, że to wciąż ta sama '
                        .'osoba, i pilnować limitów wrzutek. Nie da się z niego odczytać, kim gość jest.',
                    'Wybrane utwory i oddane głosy — do działania kolejki i podsumowania imprezy.',
                    'Zdjęcia, jeśli gość sam je zrobi lub wybierze. To dane o wizerunku — traktujemy je '
                        .'ostrożniej niż resztę.',
                ],
            ],
            [
                'title' => 'Zdjęcia — jak są chronione',
                'paragraphs' => [
                    'Leżą poza katalogiem publicznym. Nie da się do nich dostać, zgadując adres.',
                    'Ekran na sali dostaje adresy podpisane kryptograficznie, ważne kilka godzin. '
                        .'Sam kod imprezy nie wystarczy, żeby je obejrzeć.',
                    "Kasujemy je automatycznie po {$retention} dniach od zakończenia imprezy — razem "
                        .'z plikami na dysku, nie tylko wpisami w bazie. Organizator może ten czas '
                        .'zmienić w ustawieniach.',
                    'Gość może usunąć własne zdjęcie w każdej chwili. Organizator może odrzucić każde.',
                    'Usunięcie imprezy kasuje wszystkie jej zdjęcia z dysku natychmiast.',
                ],
            ],
            [
                'title' => 'Komu przekazujemy dane',
                'paragraphs' => [
                    'HotPay — dane potrzebne do rozliczenia płatności.',
                    'YouTube (Google) — treść wyszukiwania wpisaną przez gościa, gdy szuka poza naszym '
                        .'katalogiem. Nie wysyłamy tam ksywki ani niczego, co identyfikuje osobę.',
                    'Dostawcy serwera, na którym stoi aplikacja.',
                    'Nikomu więcej. Nie sprzedajemy danych i nie używamy ich do reklam.',
                ],
            ],
            [
                'title' => 'Jak długo trzymamy',
                'paragraphs' => [
                    'Konto organizatora — do jego usunięcia.',
                    'Dane rozliczeniowe — 5 lat, bo tyle nakazują przepisy podatkowe.',
                    "Zdjęcia gości — {$retention} dni od zakończenia imprezy, domyślnie.",
                    'Kolejkę i głosy — razem z imprezą; usunięcie imprezy kasuje je bezpowrotnie.',
                ],
            ],
            [
                'title' => 'Twoje prawa',
                'paragraphs' => [
                    'Dostęp do swoich danych, sprostowanie, usunięcie, ograniczenie przetwarzania, '
                        .'przeniesienie i sprzeciw.',
                    'Gość, który chce usunąć swoje zdjęcia lub ksywkę, może zrobić to sam w aplikacji '
                        .'albo poprosić organizatora imprezy.',
                    'Masz też prawo złożyć skargę do Prezesa Urzędu Ochrony Danych Osobowych.',
                ],
            ],
            [
                'title' => 'Ciasteczka',
                'paragraphs' => [
                    'Używamy tylko niezbędnych: sesja logowania, ochrona przed fałszowaniem żądań '
                        .'i identyfikator urządzenia gościa.',
                    'Nie ma u nas ciasteczek reklamowych ani śledzących między stronami.',
                ],
            ],
        ];
    }
}
