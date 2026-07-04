CREATE TABLE IF NOT EXISTS `board` (
  `square_id`            INT(2)  NOT NULL,
  `classical_player_id`  INT(10) DEFAULT NULL,
  `classical_move_number` INT(3) DEFAULT NULL,
  `classical_symbol`     CHAR(1) DEFAULT NULL,
  PRIMARY KEY (`square_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `q_moves` (
  `move_number`  INT(3)  NOT NULL,
  `player_id`    INT(10) NOT NULL,
  `square1`      INT(2)  NOT NULL,
  `square2`      INT(2)  NOT NULL,
  `collapsed_to` INT(2)  DEFAULT NULL,
  PRIMARY KEY (`move_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
