<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\InterestConfirmation;
use App\Models\Game;
use App\Models\InterestSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Intresseanmälan: "säg till när mitt språk finns".
 *
 * Dubbel bekräftelse. Anmälan sparas obekräftad och ett mail med en
 * bekräftelselänk skickas. Obekräftade anmälningar gallras efter 30 dagar
 * (interest:prune-unconfirmed). Svaret är detsamma oavsett om adressen redan
 * fanns, så att endpointen inte avslöjar vilka adresser som är anmälda.
 */
class InterestSignupController extends Controller
{
    public function store(Request $request, Game $game): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'language' => ['required', Rule::in(array_keys(InterestSignup::SPRAK))],
            // Honungsfälla: fältet är dolt i formuläret. En bot som fyller i
            // det får ett valideringsfel, och inget sparas.
            'website' => ['nullable', 'max:0'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $signup = InterestSignup::firstOrNew(['game_id' => $game->id, 'email' => $email]);
        $signup->language = $data['language'];

        // Bekräftade adresser får inget nytt mail, bara det uppdaterade språket.
        if ($signup->confirmed_at !== null) {
            $signup->save();

            return $this->tack(mailSkickat: false);
        }

        $token = Str::random(48);
        $signup->token_hash = InterestSignup::hashToken($token);
        $signup->save();

        return $this->tack(mailSkickat: $this->skickaBekraftelse($signup, $token));
    }

    public function confirm(string $token): View
    {
        $signup = InterestSignup::where('token_hash', InterestSignup::hashToken($token))->first();

        abort_if($signup === null, 404);

        if ($signup->confirmed_at === null) {
            $signup->confirmed_at = now();
            $signup->save();
        }

        return view('interest.result', [
            'game' => $signup->game,
            'rubrik' => 'Tack, nu är du anmäld!',
            'text' => 'Vi hör av oss när '.mb_strtolower(InterestSignup::SPRAK[$signup->language] ?? 'ditt språk').' finns i '.$signup->game->name.'. Inget annat.',
        ]);
    }

    /**
     * Visar en knapp, raderar ingenting. Mailklienters säkerhetsskannrar
     * (t.ex. Safe Links) öppnar länkar i mail automatiskt, och en GET som
     * raderade hade avregistrerat folk som aldrig klickat.
     */
    public function unsubscribeForm(string $token): View
    {
        $signup = InterestSignup::where('token_hash', InterestSignup::hashToken($token))->first();

        return view('interest.unsubscribe', ['game' => $signup?->game, 'token' => $token, 'finns' => $signup !== null]);
    }

    public function unsubscribe(string $token): View
    {
        $signup = InterestSignup::where('token_hash', InterestSignup::hashToken($token))->first();
        $game = $signup?->game;

        // Raderas helt, inte bara flaggas: vi har ingen anledning att spara
        // adressen till någon som inte vill höra av oss.
        $signup?->delete();

        return view('interest.result', [
            'game' => $game,
            'rubrik' => 'Du är avregistrerad',
            'text' => 'Din adress är borttagen. Du får inga fler mail från oss om det här.',
        ]);
    }

    /** @return bool om bekräftelsemailet faktiskt skickades */
    private function skickaBekraftelse(InterestSignup $signup, string $token): bool
    {
        // Mailaren "log" skickar ingenting. Anmälan sparas ändå, men det ska
        // synas: raden får inget confirmation_sent_at och loggen säger varför.
        if (config('mail.default') === 'log') {
            Log::warning('Intresseanmälan sparad utan bekräftelsemail: MAIL_MAILER är log.', [
                'signup_id' => $signup->id,
                'game' => $signup->game->slug,
            ]);

            return false;
        }

        try {
            Mail::to($signup->email)->send(new InterestConfirmation($signup, $token));
            $signup->forceFill(['confirmation_sent_at' => now()])->save();

            return true;
        } catch (\Throwable $e) {
            // Anmälan finns kvar och kan få ett nytt mail när den skickas igen.
            Log::error('Bekräftelsemail för intresseanmälan gick inte att skicka.', [
                'signup_id' => $signup->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Svaret säger bara det som faktiskt hände: "kolla din mail" enbart när
     * ett mail har gått iväg. Samma svar oavsett om adressen redan fanns,
     * utom just det.
     */
    private function tack(bool $mailSkickat): JsonResponse
    {
        return response()->json([
            'message' => $mailSkickat
                ? 'Tack! Kolla din mail och bekräfta anmälan.'
                : 'Tack! Vi hör av oss när språket finns.',
            'confirmation_sent' => $mailSkickat,
        ], 202);
    }
}
