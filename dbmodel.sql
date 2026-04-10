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
CREATE TABLE IF NOT EXISTS `moves` (
  `move_number`  INT(3)  NOT NULL,
  `player_id`    INT(10) NOT NULL,
  `square1`      INT(2)  NOT NULL,      -- first square (0-8)
  `square2`      INT(2)  NOT NULL,      -- second square (0-8)
  `collapsed_to` INT(2)  DEFAULT NULL,  -- NULL = still spooky
  PRIMARY KEY (`move_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Note: symbol ('X' or 'O') is derived from move_number parity (odd=X, even=O)
