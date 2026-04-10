<?php
/*
 * Quantum Tic Tac Toe — BGA implementation
 *
 * Based on "Quantum Tic-Tac-Toe: A teaching metaphor for superposition in quantum mechanics"
 * by Allan Goff (Novatia Labs), American Journal of Physics, Vol. 74, No. 11, November 2006.
 * https://doi.org/10.1119/1.2213635
 *
 * BGA implementation by GoOn — BoardGameArena Studio
 */

$gameinfos = [
    'game_name'     => "Quantum Tic Tac Toe",
    'publisher'     => '',
    'publisher_website' => '',
    'publisher_bgg_id'  => 0,
    'bgg_id'        => 0,

    // 2-player only
    'players'       => [2],
    'suggest_player_number' => null,
    'not_recommend_player_number' => null,

    'estimated_duration'  => 10,
    'fast_additional_time' => 30,
    'medium_additional_time' => 40,
    'slow_additional_time'   => 50,

    'tie_breaker_description' => "",
    'losers_not_ranked'       => false,
    'solo_mode_ranked'        => false,
    'is_coop'                 => 0,
    'language_dependency'     => false,

    // X = red, O = blue
    'player_colors' => ['ff0000', '0000ff'],
    'favorite_colors_support' => false,
    'disable_player_order_swap_on_rematch' => false,

    'game_interface_width' => ['min' => 500],

    'exception_on_warning' => true,
];
