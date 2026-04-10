-- Quantum Tic Tac Toe — database schema
-- Based on Allan Goff's Quantum Tic-Tac-Toe (AJP 2006)

-- 9 squares of the board (0-8, row-major)
CREATE TABLE IF NOT EXISTS `board` (
  `square_id`            INT(2)  NOT NULL,
  `classical_player_id`  INT(10) DEFAULT NULL,
  `classical_move_number` INT(3) DEFAULT NULL,
  `classical_symbol`     CHAR(1) DEFAULT NULL,
  PRIMARY KEY (`square_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each quantum move = one edge in the entanglement graph
-- Named q_moves (not moves) to avoid conflict with any BGA internal table
-- Note: symbol derived from move_number parity (odd=X, even=O). No inline comments below (BGA strips newlines before SQL exec).
CREATE TABLE IF NOT EXISTS `q_moves` (
  `move_number`  INT(3)  NOT NULL,
  `player_id`    INT(10) NOT NULL,
  `square1`      INT(2)  NOT NULL,
  `square2`      INT(2)  NOT NULL,
  `collapsed_to` INT(2)  DEFAULT NULL,
  PRIMARY KEY (`move_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
