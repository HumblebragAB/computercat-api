<?php

namespace App\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Support\SlackAlert;

// Slack-meddelanden för släppflödet (spec: ett släpp i awaiting_approval ger ett
// meddelande med länk till Filament; vid senare produktionssläpp får Erik ett besked).
// Anropas efter att transaktionen är klar och kastar aldrig: ett misslyckat larm
// syns i resultatet (SlackAlert::FAILED), inte som ett fel i flödet.
final class ReleaseNotifier
{
    public function __construct(private SlackAlert $slack) {}

    // Task 9 byter till GameResource::getUrl('releases', …) när sidan finns.
    public function approvalUrl(Game $game): string
    {
        return url('/admin/games/'.$game->getKey().'/releases');
    }

    /** @return string|null SlackAlert-konstant, eller null för teststeg (inget skickas) */
    public function afterPromote(Release $release): ?string
    {
        if (! ReleaseCatalog::isProduction($release->target, $release->stage)) {
            return null;
        }

        $game = $release->game;
        $what = '`'.SlackAlert::escape($game->slug).'` '.SlackAlert::escape($release->target).'/'.SlackAlert::escape($release->stage).', commit '.SlackAlert::escape($release->commit_sha);
        $text = $release->status === Release::AWAITING_APPROVAL
            ? "Släpp väntar på godkännande: {$what}. Godkänn eller avslå i Filament: ".$this->approvalUrl($game)
            : "Produktionssläpp begärt av spelets ägare: {$what} (första släppet är redan godkänt). ".$this->approvalUrl($game);

        return $this->slack->send("release-requested:{$release->uuid}", $text);
    }
}
