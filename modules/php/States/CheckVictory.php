<?php
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\QuanticTacToe\Game;

/**
 * Automatic (GAME) state: check win/draw conditions.
 * Runs after every placement or collapse.
 * Transitions to ComputeScores (win/draw) or PlayerTurn (continue).
 * The active player is already set correctly by the time we reach this state.
 */
class CheckVictory extends GameState
{
    function __construct(protected Game $game)
    {
        parent::__construct($game, id: 40, type: StateType::GAME);
    }

    public function onEnteringState(): mixed
    {
        $result = $this->game->checkVictory();

        if ($result === null) {
            // Game continues — active player is already set
            return PlayerTurn::class;
        }

        if ($result === 'draw') {
            $this->game->bga->notify->all(
                'gameResult',
                clienttranslate('The board is full with no winner — it\'s a draw!'),
                []
            );
            $this->game->bga->globals->set('winner_id', -1); // -1 = draw
        } else {
            $winnerId = $result;
            $this->game->bga->notify->all(
                'gameResult',
                clienttranslate('${player_name} wins!'),
                [
                    'player_name' => $this->game->getPlayerNameById($winnerId),
                    'winner_id'   => $winnerId,
                ]
            );
            $this->game->bga->globals->set('winner_id', $winnerId);
        }

        return ComputeScores::class;
    }
}
