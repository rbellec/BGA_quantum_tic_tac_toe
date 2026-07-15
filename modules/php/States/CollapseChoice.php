<?php
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\QuanticTacToe\Game;

/**
 * The opponent of whoever created the cycle chooses which direction to collapse.
 * At this point, activeNextPlayer() has already been called, so the current active
 * player IS the opponent.
 */
class CollapseChoice extends GameState
{
    function __construct(protected Game $game)
    {
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER);
    }

    private const SQUARE_NAMES = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    public function getArgs(): array
    {
        $options = $this->game->bga->globals->get('collapse_options');
        // Build human-readable labels for each option
        $labels = [];
        $shortLabels = [];
        foreach ($options as $dir => $collapses) {
            $parts = [];
            foreach ($collapses as $moveNum => $square) {
                $row = (int)floor($square / 3) + 1;
                $col = ($square % 3) + 1;
                $parts[] = "move {$moveNum} → ({$row},{$col})";
            }
            $labels[$dir] = implode(', ', $parts);

            // The new (cycle-closing) move is the highest move number in this
            // option — its destination is the most actionable single fact for
            // telling the two options apart at a glance.
            $newMoveNum = max(array_keys($collapses));
            $shortLabels[$dir] = self::SQUARE_NAMES[$collapses[$newMoveNum]];
        }
        return [
            'options'      => $options,
            'labels'       => $labels,
            'short_labels' => $shortLabels,
        ];
    }

    #[PossibleAction]
    public function actChooseCollapse(string $direction, int $activePlayerId): string
    {
        if (!in_array($direction, ['A', 'B'])) {
            throw new UserException(clienttranslate("Invalid collapse direction."));
        }

        $options  = $this->game->bga->globals->get('collapse_options');
        $collapses = $options[$direction];
        $playerId = $activePlayerId;

        // Execute collapse
        $sequence = $this->game->performCollapse($collapses);

        // Clear stored options
        $this->game->bga->globals->set('collapse_options', null);
        $this->game->bga->globals->set('collapse_cycle_path', null);

        // Notify board update
        $this->game->bga->notify->all('boardCollapsed', clienttranslate('${player_name} collapses the entanglement (direction ${direction})'), [
            'player_name' => $this->game->getPlayerNameById($playerId),
            'direction'   => $direction,
            'collapses'   => $collapses,
            'collapse_sequence' => $sequence,
            'board'       => $this->game->getBoardData(),
            'moves'       => $this->game->getMovesData(),
        ]);

        return CheckVictory::class;
    }

    function zombie(int $playerId): string
    {
        return $this->actChooseCollapse('A', $playerId);
    }
}
