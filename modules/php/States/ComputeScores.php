<?php
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\QuanticTacToe\Game;

/**
 * State 98: set final scores before transitioning to gameEnd (99).
 */
class ComputeScores extends GameState
{
    function __construct(protected Game $game)
    {
        parent::__construct($game, id: 98, type: StateType::GAME);
    }

    public function onEnteringState(): mixed
    {
        $winnerId = (int)$this->game->bga->globals->get('winner_id');

        if ($winnerId > 0) {
            // Winner gets 1 point, loser gets 0
            $players = $this->game->getCollectionFromDb("SELECT player_id FROM player");
            foreach ($players as $row) {
                $pid = (int)$row['player_id'];
                $score = ($pid === $winnerId) ? 1 : 0;
                $this->game->bga->playerScore->set($pid, $score);
            }
        }
        // Draw: all players keep score 0

        return 99; // gameEnd
    }
}
