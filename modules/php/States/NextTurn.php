<?php
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\QuanticTacToe\Game;

/**
 * Automatic (GAME) state: activate the opponent after a placement.
 * The active player can only change in a GAME-type state (framework rule).
 * Routes to CollapseChoice if the placement created a cycle, else CheckVictory.
 */
class NextTurn extends GameState
{
    function __construct(protected Game $game)
    {
        parent::__construct($game, id: 25, type: StateType::GAME);
    }

    public function onEnteringState(): mixed
    {
        $this->game->activeNextPlayer();

        $options = $this->game->bga->globals->get('collapse_options');
        if ($options !== null) {
            // The newly active player (opponent of the cycle creator) must choose
            $this->game->bga->notify->all('cycleDetected', clienttranslate('A cyclic entanglement was created! ${player_name} must choose how to collapse it.'), [
                'player_name' => $this->game->getActivePlayerName(),
                'cycle_path'  => $this->game->bga->globals->get('collapse_cycle_path'),
                'options'     => $options,
            ]);
            return CollapseChoice::class;
        }

        return CheckVictory::class;
    }
}
