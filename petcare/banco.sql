-- ============================================================
-- PetCare · banco.sql
-- Apaga e recria o banco do zero. Execute com:
--   mysql -u root -p < banco.sql
-- ============================================================

DROP DATABASE IF EXISTS petcare;
CREATE DATABASE petcare
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE petcare;

-- ------------------------------------------------------------
-- Tutores (donos dos pets)
-- ------------------------------------------------------------
CREATE TABLE tutores (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(100) NOT NULL,
  telefone   VARCHAR(20)  NOT NULL,
  email      VARCHAR(150) NOT NULL,
  criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tutores_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Pets
-- Regra de exclusão: ON DELETE RESTRICT. Um tutor que ainda
-- tem pets NÃO pode ser excluído (a API devolve 409).
-- Justificativa completa no README.
-- ------------------------------------------------------------
CREATE TABLE pets (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome             VARCHAR(60)  NOT NULL,
  especie          ENUM('cachorro','gato','ave','roedor','outro') NOT NULL,
  raca             VARCHAR(60)  NULL,
  data_nascimento  DATE         NOT NULL,
  peso_kg          DECIMAL(5,2) NOT NULL,
  tutor_id         INT UNSIGNED NOT NULL,
  criado_em        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pets_tutor (tutor_id),
  KEY idx_pets_especie (especie),
  CONSTRAINT fk_pets_tutor
    FOREIGN KEY (tutor_id) REFERENCES tutores (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Usuários (funcionários da clínica)
-- A senha é guardada apenas como hash (password_hash do PHP).
-- ------------------------------------------------------------
CREATE TABLE usuarios (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  senha_hash  VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tokens de autenticação
-- Se o usuário for excluído, os tokens dele vão junto.
-- ------------------------------------------------------------
CREATE TABLE tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  token       CHAR(64)     NOT NULL,
  expira_em   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_token (token),
  KEY idx_tokens_usuario (usuario_id),
  CONSTRAINT fk_tokens_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Dados de exemplo
-- ============================================================

INSERT INTO tutores (nome, telefone, email) VALUES
  ('Mariana Souza',   '(61) 99111-2233', 'mariana.souza@email.com'),
  ('Carlos Henrique', '(61) 98222-3344', 'carlos.henrique@email.com'),
  ('Beatriz Lima',    '(61) 99333-4455', 'beatriz.lima@email.com'),
  ('Rafael Almeida',  '(61) 98444-5566', 'rafael.almeida@email.com'),
  ('Juliana Costa',   '(61) 99555-6677', 'juliana.costa@email.com'),
  ('Tiago Ribeiro',   '(61) 98666-7788', 'tiago.ribeiro@email.com');

-- Mariana (1) tem 3 pets, Carlos (2) tem 2, Beatriz (3) tem 2
INSERT INTO pets (nome, especie, raca, data_nascimento, peso_kg, tutor_id) VALUES
  ('Thor',     'cachorro', 'Labrador',        '2020-03-15', 30.50, 1),
  ('Mel',      'gato',     'Siamês',          '2021-07-02',  4.20, 1),
  ('Piupiu',   'ave',      'Calopsita',       '2022-01-20',  0.09, 1),
  ('Bolinha',  'cachorro', 'Poodle',          '2019-11-08',  6.80, 2),
  ('Nina',     'gato',     NULL,              '2023-05-30',  3.90, 2),
  ('Simba',    'gato',     'Persa',           '2018-09-12',  5.40, 3),
  ('Pipoca',   'roedor',   'Hamster sírio',   '2024-02-14',  0.15, 3),
  ('Rex',      'cachorro', 'Pastor Alemão',   '2017-06-25', 34.00, 4),
  ('Luna',     'cachorro', NULL,              '2022-10-10', 12.30, 5),
  ('Kiwi',     'ave',      'Periquito',       '2024-08-01',  0.04, 6),
  ('Bidu',     'cachorro', 'Beagle',          '2021-12-05', 11.70, 6),
  ('Nemo',     'outro',    'Tartaruga',       '2016-04-18',  1.80, 4);

-- Usuário de teste para o professor
-- E-mail: professor@petcare.com   Senha: petcare123
INSERT INTO usuarios (nome, email, senha_hash) VALUES
  ('Professor Teste', 'professor@petcare.com',
   '$2y$10$zrCzmBV6KigHpYlxpWUzreH.WoSNKmUJTCPQ0x4B/C0Obp4pnwflS');
