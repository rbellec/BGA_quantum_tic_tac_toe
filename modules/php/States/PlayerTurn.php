<?php
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\QuanticTacToe\Game;

class PlayerTurn extends GameState
{
    function __construct(protected Game $game)
    {
        parent::__construct($game, id: 20, type: StateType::ACTIVE_PLAYER);
    }

    public function getArgs(): array
    {
        // Squares still available (no classical mark)
        $rows = $this->game->getCollectionFromDb(
            "SELECT square_id FROM board WHERE classical_player_id IS NULL"
        );
        $available = array_map(fn($r) => (int)$r['square_id'], array_values($rows));
        return ['available_squares' => $available];
    }

    #[PossibleAction]
    public function actPlaceSpookyMarks(int $sq1, int $sq2): string
    {
        // --- Validation ---
        if ($sq1 === $sq2) {
            throw new UserException("You must choose two different squares.");
        }
        if ($sq1 < 0 || $sq1 > 8 || $sq2 < 0 || $sq2 > 8) {
            throw new UserException("Invalid square index.");
        }

        // Both squares must be free of classical marks
        $args = $this->getArgs();
        $available = $args['available_squares'];
        if (!in_array($sq1, $available) || !in_array($sq2, $available)) {
            throw new UserException("One or both squares already have a classical mark.");
        }

        $playerId  = $this->game->getCurrentPlayerId();
        $moveNum   = $this->game->incrementAndGetMoveNumber();
        $symbol    = $this->game->symbolFromMoveNumber($moveNum);

        // --- Detect cycle BEFORE inserting the new move ---
        $cyclePath = $this->game->detectCycle($sq1, $sq2);

        // --- Insert the new move (symbol derived from move_number parity, not stored) ---
        $this->game->DbQuery(
            "INSERT INTO q_moves (move_number, player_id, square1, square2)"
            . " VALUES ({$moveNum}, {$playerId}, {$sq1}, {$sq2})"
        );

        // --- Notify all players of the spooky placement ---
        $this->game->bga->notify->all('spookyPlaced', clienttranslate('${player_name} places ${symbol}${move_num} in squares ${sq1} and ${sq2}'), [
            'player_name' => $this->game->getPlayerNameById($playerId),
            'player_id'   => $playerId,
            'symbol'      => $symbol,
            'move_num'    => $moveNum,
            'sq1'         => $sq1,
            'sq2'         => $sq2,
            'board'       => $this->game->getBoardData(),
            'moves'       => $this->game->getMovesData(),
        ]);

        // Stats
        $this->game->bga->tableStats->inc('turns_number', 1);

        // --- Activate the opponent (always: either for CollapseChoice or next PlayerTurn) ---
        $this->game->activeNextPlayer();

        if ($cyclePath !== null) {
            // Cycle detected: opponent must choose collapse direction
            $options = $this->game->computeCollapseOptions($cyclePath, $moveNum);
            $this->game->bga->globals->set('collapse_options', $options);

            $this->game->bga->notify->all('cycleDetected', clienttranslate('A cyclic entanglement was created! ${player_name} must choose how to collapse it.'), [
                'player_name' => $this->game->getActivePlayerName(),
                'cycle_path'  => $cyclePath,
                'options'     => $options,
            ]);

            return CollapseChoice::class;
        }

        return CheckVictory::class;
    }

    function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        $available = $args['available_squares'];
        if (count($available) < 2) {
            // No valid move — just skip to next state
            $this->game->activeNextPlayer();
            return CheckVictory::class;
        }
        shuffle($available);
        return $this->actPlaceSpookyMarks($available[0], $available[1]);
    }
}
