-- SIGE ANGOLA BACKUP
-- Escola ID: 2

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `alocacoes`;
CREATE TABLE `alocacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `turma_id` int(11) DEFAULT NULL,
  `ano_letivo` year(4) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `turma_id` (`turma_id`),
  KEY `idx_professor` (`professor_id`),
  KEY `idx_disciplina` (`disciplina_id`),
  CONSTRAINT `alocacoes_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alocacoes_ibfk_2` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alocacoes_ibfk_3` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `angola_bairros`;
CREATE TABLE `angola_bairros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `comuna_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bairro_comuna` (`nome`,`comuna_id`),
  KEY `comuna_id` (`comuna_id`),
  CONSTRAINT `angola_bairros_ibfk_1` FOREIGN KEY (`comuna_id`) REFERENCES `angola_comunas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `angola_comunas`;
CREATE TABLE `angola_comunas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `municipio_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_comuna` (`municipio_id`,`nome`),
  CONSTRAINT `angola_comunas_ibfk_1` FOREIGN KEY (`municipio_id`) REFERENCES `angola_municipios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `angola_comunas` VALUES('1','14','Ingombota','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('2','14','Maculusso','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('3','14','Patrice Lumumba','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('4','14','Ilha do Cabo','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('5','10','Sambizanga','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('6','11','Rangel','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('7','12','Samba','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('8','13','Maianga','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('9','7','Viana','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('10','7','Calumbo','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('11','7','Zango','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('12','5','Belas','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('13','2','Cacuaco','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('14','2','Kicolo','2026-04-11 16:13:58');
INSERT INTO `angola_comunas` VALUES('16','15','Dande','2026-04-11 16:24:29');
INSERT INTO `angola_comunas` VALUES('17','16','Malanje','2026-04-16 10:44:41');

DROP TABLE IF EXISTS `angola_municipios`;
CREATE TABLE `angola_municipios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provincia_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_municipio` (`provincia_id`,`nome`),
  CONSTRAINT `angola_municipios_ibfk_1` FOREIGN KEY (`provincia_id`) REFERENCES `angola_provincias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `angola_municipios` VALUES('1','11','Luanda','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('2','11','Cacuaco','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('3','11','Cazenga','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('4','11','Ícolo e Bengo','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('5','11','Belas','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('6','11','Quiçama','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('7','11','Viana','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('8','11','Talatona','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('9','11','Kilamba Kiaxi','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('10','11','Sambizanga','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('11','11','Rangel','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('12','11','Samba','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('13','11','Maianga','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('14','11','Ingombota','2026-04-11 16:13:58');
INSERT INTO `angola_municipios` VALUES('15','1','Panguila ','2026-04-11 16:16:44');
INSERT INTO `angola_municipios` VALUES('16','14','Malanje','2026-04-16 10:44:32');

DROP TABLE IF EXISTS `angola_provincias`;
CREATE TABLE `angola_provincias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `sigla` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `angola_provincias` VALUES('1','Bengo','BGO','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('2','Benguela','BGU','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('3','Bié','BIE','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('4','Cabinda','CAB','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('5','Cuando Cubango','CCU','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('6','Cuanza Norte','CNO','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('7','Cuanza Sul','CSU','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('8','Cunene','CNN','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('9','Huambo','HUA','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('10','Huíla','HUI','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('11','Luanda','LAD','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('12','Lunda Norte','LNO','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('13','Lunda Sul','LSU','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('14','Malanje','MAL','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('15','Moxico','MOX','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('16','Namibe','NAM','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('17','Uíge','UIG','2026-04-09 14:11:53');
INSERT INTO `angola_provincias` VALUES('18','Zaire','ZAI','2026-04-09 14:11:53');

DROP TABLE IF EXISTS `ano_letivo`;
CREATE TABLE `ano_letivo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `ano` varchar(20) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `ativo` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ano_letivo` VALUES('1','2','2024','2024-02-01','2024-12-20','0','2026-04-18 11:50:25');
INSERT INTO `ano_letivo` VALUES('2','2','2025','2025-02-01','2025-12-20','0','2026-04-18 11:50:25');
INSERT INTO `ano_letivo` VALUES('3','2','2026','2026-02-01','2026-12-20','1','2026-04-18 11:50:25');

DROP TABLE IF EXISTS `anos_letivos`;
CREATE TABLE `anos_letivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `ano` varchar(9) NOT NULL,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ano_escola` (`ano`,`escola_id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `anos_letivos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `assinaturas`;
CREATE TABLE `assinaturas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `plano_id` int(11) NOT NULL,
  `tipo_cobranca` enum('mensal','anual','trimestral') DEFAULT 'mensal',
  `valor` decimal(10,2) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `data_proxima_cobranca` date DEFAULT NULL,
  `status` enum('ativa','cancelada','expirada','pendente') DEFAULT 'pendente',
  `auto_renovacao` tinyint(1) DEFAULT 1,
  `observacoes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `plano_id` (`plano_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data_fim` (`data_fim`),
  CONSTRAINT `assinaturas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assinaturas_ibfk_2` FOREIGN KEY (`plano_id`) REFERENCES `planos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `assinaturas` VALUES('1','1','1','mensal','19900.00','2026-04-09','2027-07-09',NULL,'ativa','1',NULL,'2026-04-09 16:05:43','2026-04-09 21:12:20');
INSERT INTO `assinaturas` VALUES('2','2','1','mensal','19900.00','2026-04-10','2027-06-10',NULL,'ativa','1',NULL,'2026-04-10 21:37:14','2026-04-11 16:50:45');

DROP TABLE IF EXISTS `atividades`;
CREATE TABLE `atividades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL COMMENT 'Título da atividade',
  `descricao` text DEFAULT NULL COMMENT 'Descrição detalhada',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `professor_id` int(11) NOT NULL COMMENT 'ID do professor (funcionarios.id)',
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `tipo` enum('trabalho','prova','exercicio','outro') DEFAULT 'trabalho' COMMENT 'Tipo de atividade',
  `valor_maximo` decimal(5,2) DEFAULT 10.00 COMMENT 'Valor máximo da atividade',
  `bimestre` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Bimestre (1, 2, 3)',
  `data_entrega` date DEFAULT NULL COMMENT 'Data de entrega',
  `status` enum('ativo','inativo') DEFAULT 'ativo' COMMENT 'Status da atividade',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_atividades_turma` (`turma_id`),
  KEY `idx_atividades_disciplina` (`disciplina_id`),
  KEY `idx_atividades_professor` (`professor_id`),
  KEY `idx_atividades_escola` (`escola_id`),
  KEY `idx_atividades_bimestre` (`bimestre`),
  KEY `idx_atividades_status` (`status`),
  KEY `idx_atividades_data_entrega` (`data_entrega`),
  CONSTRAINT `fk_atividades_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_atividades_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_atividades_professor` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_atividades_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Atividades e trabalhos dos professores';

INSERT INTO `atividades` VALUES('1','Trabalho escola','Um trabalho na disciplina de informatica','1','1','2','2','trabalho','10.00','1','2026-04-30','ativo','2026-04-22 01:14:08','2026-04-22 01:14:08');
INSERT INTO `atividades` VALUES('3','Trabalho escola','dsdsd','1','1','2','2','prova','10.00','1','2026-04-16','ativo','2026-04-22 01:56:22','2026-04-22 01:56:22');
INSERT INTO `atividades` VALUES('4','Trabalho escola','A','9','1','2','2','trabalho','10.00','1','2026-04-24','ativo','2026-04-23 19:24:23','2026-04-23 19:24:23');

DROP TABLE IF EXISTS `avaliacao_criterios`;
CREATE TABLE `avaliacao_criterios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `peso` decimal(5,2) DEFAULT 1.00,
  `ordem` int(11) DEFAULT 0,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `avaliacao_criterios` VALUES('1','Assiduidade','Pontualidade e presença no trabalho','1.00','1','ativo');
INSERT INTO `avaliacao_criterios` VALUES('2','Disciplina','Cumprimento das normas e regulamentos','1.00','2','ativo');
INSERT INTO `avaliacao_criterios` VALUES('3','Produtividade','Eficiência e qualidade do trabalho','1.00','3','ativo');
INSERT INTO `avaliacao_criterios` VALUES('4','Relacionamento Interpessoal','Capacidade de trabalhar em equipa','1.00','4','ativo');
INSERT INTO `avaliacao_criterios` VALUES('5','Iniciativa','Proatividade e criatividade','1.00','5','ativo');
INSERT INTO `avaliacao_criterios` VALUES('6','Responsabilidade','Assumir compromissos e cumprir prazos','1.00','6','ativo');
INSERT INTO `avaliacao_criterios` VALUES('7','Assiduidade','Pontualidade e presença no trabalho','1.00','1','ativo');
INSERT INTO `avaliacao_criterios` VALUES('8','Disciplina','Cumprimento das normas e regulamentos','1.00','2','ativo');
INSERT INTO `avaliacao_criterios` VALUES('9','Produtividade','Eficiência e qualidade do trabalho','1.00','3','ativo');
INSERT INTO `avaliacao_criterios` VALUES('10','Relacionamento Interpessoal','Capacidade de trabalhar em equipa','1.00','4','ativo');
INSERT INTO `avaliacao_criterios` VALUES('11','Iniciativa','Proatividade e criatividade','1.00','5','ativo');
INSERT INTO `avaliacao_criterios` VALUES('12','Responsabilidade','Assumir compromissos e cumprir prazos','1.00','6','ativo');

DROP TABLE IF EXISTS `avaliacao_notas`;
CREATE TABLE `avaliacao_notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `avaliacao_id` int(11) NOT NULL,
  `criterio_id` int(11) NOT NULL,
  `nota` decimal(5,2) NOT NULL,
  `comentario` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `criterio_id` (`criterio_id`),
  KEY `idx_avaliacao` (`avaliacao_id`),
  CONSTRAINT `avaliacao_notas_ibfk_1` FOREIGN KEY (`avaliacao_id`) REFERENCES `avaliacoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `avaliacao_notas_ibfk_2` FOREIGN KEY (`criterio_id`) REFERENCES `avaliacao_criterios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `avaliacao_periodos`;
CREATE TABLE `avaliacao_periodos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `peso` decimal(5,2) DEFAULT 1.00,
  `status` enum('ativa','encerrada','pendente') DEFAULT 'pendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_datas` (`data_inicio`,`data_fim`),
  CONSTRAINT `avaliacao_periodos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `avaliacoes`;
CREATE TABLE `avaliacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `periodo_id` int(11) NOT NULL,
  `avaliador_id` int(11) NOT NULL,
  `pontuacao_total` decimal(5,2) DEFAULT NULL,
  `classificacao` enum('Excelente','Bom','Regular','Insatisfatório') DEFAULT NULL,
  `comentarios` text DEFAULT NULL,
  `data_avaliacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_avaliacao` (`funcionario_id`,`periodo_id`),
  KEY `avaliador_id` (`avaliador_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_periodo` (`periodo_id`),
  CONSTRAINT `avaliacoes_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `avaliacoes_ibfk_2` FOREIGN KEY (`periodo_id`) REFERENCES `avaliacao_periodos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `avaliacoes_ibfk_3` FOREIGN KEY (`avaliador_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `avaliacoes_institucionais`;
CREATE TABLE `avaliacoes_institucionais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo` enum('autoavaliacao','externa','pedagogica','administrativa') DEFAULT 'pedagogica',
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `status` enum('pendente','em_andamento','concluida','cancelada') DEFAULT 'pendente',
  `resultados` text DEFAULT NULL,
  `recomendacoes` text DEFAULT NULL,
  `responsavel` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `avaliacoes_institucionais_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `avaliacoes_materiais`;
CREATE TABLE `avaliacoes_materiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `material_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `nota` int(11) NOT NULL CHECK (`nota` >= 1 and `nota` <= 5),
  `comentario` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_avaliacao_material` (`material_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `avaliacoes_materiais_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materiais_didaticos` (`id`),
  CONSTRAINT `avaliacoes_materiais_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `avaliacoes_materiais` VALUES('2','31','1','5','Excelente livro de matemática financeira! Muito útil.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('3','32','1','5','Cálculo bem explicado com muitos exemplos.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('4','33','1','4','Bom conteúdo, poderia ter mais exercícios.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('5','36','1','5','Gramática completa e bem organizada.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('6','37','1','5','Técnicas de redação muito úteis para meus alunos.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('7','41','1','5','Halliday é referência em física!','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('8','45','1','5','História de Angola essencial para todos.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('9','50','1','4','Bom livro de geografia, bem atualizado.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('10','51','1','5','Gramática inglesa excelente!','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('11','58','1','5','Biologia celular completa e detalhada.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('12','60','1','4','Anatomia bem ilustrada, falta mais detalhes.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('13','66','1','5','Vídeo aula muito didática!','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('14','70','1','4','Exercícios variados, bom para revisão.','2026-04-29 23:16:38');
INSERT INTO `avaliacoes_materiais` VALUES('30','28','1','1','','2026-04-29 23:50:59');
INSERT INTO `avaliacoes_materiais` VALUES('31','117','1','3','','2026-04-29 23:51:13');
INSERT INTO `avaliacoes_materiais` VALUES('32','179','1','4','','2026-04-30 00:16:06');
INSERT INTO `avaliacoes_materiais` VALUES('33','193','1','1','','2026-04-30 00:45:41');
INSERT INTO `avaliacoes_materiais` VALUES('34','389','1','1','','2026-04-30 01:16:47');

DROP TABLE IF EXISTS `bairros`;
CREATE TABLE `bairros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `comuna_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bairro_comuna` (`nome`,`comuna_id`),
  KEY `comuna_id` (`comuna_id`),
  CONSTRAINT `bairros_ibfk_1` FOREIGN KEY (`comuna_id`) REFERENCES `angola_comunas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `calendario_escolar`;
CREATE TABLE `calendario_escolar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `tipo` enum('prova','reuniao','evento','feriado','recesso') DEFAULT 'evento',
  `bimestre` int(11) DEFAULT NULL,
  `cor` varchar(7) DEFAULT '#006B3E',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_data` (`data_inicio`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_bimestre` (`bimestre`),
  CONSTRAINT `calendario_escolar_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `calendario_provas`;
CREATE TABLE `calendario_provas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `professor_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `prova_id` int(11) DEFAULT NULL,
  `data_prova` date NOT NULL,
  `horario` time DEFAULT NULL,
  `bimestre` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `peso` decimal(3,1) DEFAULT 1.0,
  `conteudo` text DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('agendada','realizada','cancelada') DEFAULT 'agendada',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `turma_id` (`turma_id`),
  KEY `disciplina_id` (`disciplina_id`),
  KEY `professor_id` (`professor_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  KEY `fk_calendario_provas_prova_id` (`prova_id`),
  CONSTRAINT `calendario_provas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendario_provas_ibfk_2` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendario_provas_ibfk_3` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendario_provas_ibfk_4` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendario_provas_ibfk_5` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_calendario_provas_prova_id` FOREIGN KEY (`prova_id`) REFERENCES `provas` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `calendario_provas` VALUES('1','1','1','1','2','Prova de Lingua Portuguesa','1','2026-04-30',NULL,'1','3','2.0',NULL,NULL,'agendada','2026-04-23 19:29:17','2026-04-23 19:30:14');
INSERT INTO `calendario_provas` VALUES('2','1','1','1','2','Prova Trimestral de Matemática','1','2026-04-30',NULL,'1','1','2.0',NULL,NULL,'agendada','2026-04-23 19:32:17','2026-04-23 19:32:17');
INSERT INTO `calendario_provas` VALUES('3','1','1','2','2','Teste de Português','1','2026-04-26',NULL,'1','1','1.0',NULL,NULL,'agendada','2026-04-23 19:32:17','2026-05-03 22:47:32');
INSERT INTO `calendario_provas` VALUES('4','1','2','1','2','Trabalho de Matemática','1','2026-05-03',NULL,'1','1','1.5',NULL,NULL,'agendada','2026-04-23 19:32:17','2026-05-03 22:47:32');

DROP TABLE IF EXISTS `candidatos`;
CREATE TABLE `candidatos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vaga_id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `curriculo` varchar(500) DEFAULT NULL,
  `carta_motivacao` text DEFAULT NULL,
  `status` enum('pendente','analisado','entrevistado','aprovado','reprovado') DEFAULT 'pendente',
  `data_candidatura` timestamp NOT NULL DEFAULT current_timestamp(),
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vaga` (`vaga_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `candidatos_ibfk_1` FOREIGN KEY (`vaga_id`) REFERENCES `vagas_emprego` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `candidatos_documentos`;
CREATE TABLE `candidatos_documentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `candidato_id` int(11) NOT NULL,
  `tipo_documento` enum('certificado_habilitacoes','certificado_formacao','carta_recomendacao','outro_documento') NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL,
  `data_upload` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `candidato_id` (`candidato_id`),
  CONSTRAINT `candidatos_documentos_ibfk_1` FOREIGN KEY (`candidato_id`) REFERENCES `candidatos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `cargos_sistema`;
CREATE TABLE `cargos_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `nivel` int(11) DEFAULT 1,
  `ativo` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `cargos_sistema_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cargos_sistema` VALUES('1','2','Diretor Geral','Acesso total ao sistema','10','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('2','2','Coordenador Pedagógico','Gestão pedagógica completa','8','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('3','2','Secretário','Gestão administrativa','7','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('4','2','Financeiro','Gestão financeira','7','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('5','2','Professor','Acesso restrito a turmas','5','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('6','2','Bibliotecário','Gestão da biblioteca','5','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('7','2','Encarregado','Acompanhamento do aluno','3','1','2026-05-02 01:35:17');
INSERT INTO `cargos_sistema` VALUES('8','2','Aluno','Acesso restrito','2','1','2026-05-02 01:35:17');

DROP TABLE IF EXISTS `certificados`;
CREATE TABLE `certificados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `tipo` enum('conclusao','frequencia','aproveitamento','participacao','estagio') DEFAULT 'conclusao',
  `numero_certificado` varchar(50) NOT NULL,
  `data_emissao` date NOT NULL,
  `observacoes` text DEFAULT NULL,
  `assinado_por` varchar(200) DEFAULT NULL,
  `status` enum('ativo','cancelado') DEFAULT 'ativo',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero_certificado` (`numero_certificado`,`escola_id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_status` (`status`),
  KEY `idx_data_emissao` (`data_emissao`),
  KEY `idx_certificados_numero` (`numero_certificado`),
  KEY `idx_certificados_aluno` (`aluno_id`),
  KEY `idx_certificados_emissao` (`data_emissao`),
  CONSTRAINT `certificados_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `certificados_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `certificados` VALUES('1','2','10','conclusao','ctr','2026-05-07','','Armanda Pombal','ativo','3','2026-05-07 07:14:45','2026-05-07 07:14:45');
INSERT INTO `certificados` VALUES('2','2','12','frequencia','CERT-FRE-2026-0001','2026-05-07','','Armanda Pombal','ativo','3','2026-05-07 07:23:50','2026-05-07 07:23:50');

DROP TABLE IF EXISTS `chamada`;
CREATE TABLE `chamada` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `professor_id` int(11) NOT NULL COMMENT 'ID do professor que registou',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno',
  `data_aula` date NOT NULL COMMENT 'Data da aula',
  `horario_inicio` time DEFAULT NULL COMMENT 'Horário de início da aula',
  `horario_fim` time DEFAULT NULL COMMENT 'Horário de fim da aula',
  `status` enum('presente','falta','falta_justificada','atraso','dispensa') NOT NULL DEFAULT 'presente' COMMENT 'Status do aluno',
  `minutos_atraso` int(11) DEFAULT 0 COMMENT 'Minutos de atraso (se aplicável)',
  `justificativa` text DEFAULT NULL COMMENT 'Justificativa da falta/atraso',
  `documento_justificativa` varchar(255) DEFAULT NULL COMMENT 'Comprovativo da justificativa',
  `observacao` text DEFAULT NULL COMMENT 'Observações adicionais',
  `lancado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que lançou',
  `data_lancamento` datetime DEFAULT current_timestamp() COMMENT 'Data do lançamento',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `bimestre` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chamada_unica` (`estudante_id`,`disciplina_id`,`data_aula`),
  KEY `idx_chamada_aluno` (`estudante_id`),
  KEY `idx_chamada_turma` (`turma_id`),
  KEY `idx_chamada_disciplina` (`disciplina_id`),
  KEY `idx_chamada_professor` (`professor_id`),
  KEY `idx_chamada_data` (`data_aula`),
  KEY `idx_chamada_status` (`status`),
  KEY `idx_chamada_escola` (`escola_id`),
  KEY `idx_chamada_ano_letivo` (`ano_letivo_id`),
  CONSTRAINT `fk_chamada_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `fk_chamada_professor` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registo de presenças e faltas dos estudantes';

INSERT INTO `chamada` VALUES('4','2','3','9','1','2','10','2026-04-23',NULL,NULL,'atraso','0',NULL,NULL,'',NULL,'2026-04-23 09:31:52','2026-04-23 09:31:52','2026-05-03 17:59:18','1');
INSERT INTO `chamada` VALUES('5','2','3','9','1','2','12','2026-04-23',NULL,NULL,'atraso','0',NULL,NULL,'',NULL,'2026-04-23 09:31:52','2026-04-23 09:31:52','2026-05-03 17:59:18','1');
INSERT INTO `chamada` VALUES('6','2','3','9','1','2','11','2026-04-23',NULL,NULL,'atraso','0',NULL,NULL,'',NULL,'2026-04-23 09:31:52','2026-04-23 09:31:52','2026-05-03 17:59:18','1');
INSERT INTO `chamada` VALUES('7','2','3','9','1','2','8','2026-04-23',NULL,NULL,'atraso','0',NULL,NULL,'',NULL,'2026-04-23 09:31:52','2026-04-23 09:31:52','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('8','2','3','9','1','2','9','2026-04-23',NULL,NULL,'atraso','0',NULL,NULL,'',NULL,'2026-04-23 09:31:52','2026-04-23 09:31:52','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('9','2','3','9','1','2','10','2026-04-25',NULL,NULL,'presente','0',NULL,NULL,NULL,NULL,'2026-04-25 18:24:06','2026-04-25 18:24:06','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('10','2','3','9','1','2','11','2026-04-25',NULL,NULL,'presente','0',NULL,NULL,NULL,NULL,'2026-04-25 18:24:07','2026-04-25 18:24:07','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('11','2','3','9','1','2','12','2026-04-25',NULL,NULL,'atraso','0','',NULL,NULL,NULL,'2026-04-25 18:24:07','2026-04-25 18:24:07','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('12','2','3','9','1','2','8','2026-04-25',NULL,NULL,'','0',NULL,NULL,NULL,NULL,'2026-04-25 18:24:07','2026-04-25 18:24:07','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('13','2','3','9','1','2','9','2026-04-25',NULL,NULL,'presente','0',NULL,NULL,NULL,NULL,'2026-04-25 18:24:07','2026-04-25 18:24:07','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('14','2','3','9','1','2','10','2026-04-30',NULL,NULL,'falta','0',NULL,NULL,NULL,NULL,'2026-04-30 10:59:28','2026-04-30 10:59:28','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('15','2','3','9','1','2','11','2026-04-30',NULL,NULL,'atraso','0',NULL,NULL,NULL,NULL,'2026-04-30 10:59:31','2026-04-30 10:59:31','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('16','2','3','9','1','2','8','2026-04-30',NULL,NULL,'atraso','0',NULL,NULL,NULL,NULL,'2026-04-30 10:59:32','2026-04-30 10:59:32','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('17','2','3','9','1','2','9','2026-04-30',NULL,NULL,'','0',NULL,NULL,NULL,NULL,'2026-04-30 10:59:34','2026-04-30 10:59:34','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('18','2','3','9','3','3','10','2026-05-02',NULL,NULL,'presente','0',NULL,NULL,NULL,NULL,'2026-05-01 23:27:30','2026-05-01 23:27:30','2026-05-03 18:00:36','1');
INSERT INTO `chamada` VALUES('19','2','3','9','3','3','12','2026-05-02',NULL,NULL,'falta','0',NULL,NULL,NULL,NULL,'2026-05-01 23:27:32','2026-05-01 23:27:32','2026-05-03 18:00:36','1');

DROP TABLE IF EXISTS `chamados_respostas`;
CREATE TABLE `chamados_respostas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chamado_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `mensagem` text NOT NULL,
  `anexo` varchar(255) DEFAULT NULL,
  `data_resposta` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `chamado_id` (`chamado_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `chamados_respostas_ibfk_1` FOREIGN KEY (`chamado_id`) REFERENCES `chamados_suporte` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chamados_respostas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `chamados_suporte`;
CREATE TABLE `chamados_suporte` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_chamado` varchar(20) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text NOT NULL,
  `categoria` enum('tecnico','administrativo','financeiro','academico','outro') DEFAULT 'outro',
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `status` enum('aberto','em_andamento','respondido','fechado') DEFAULT 'aberto',
  `data_abertura` datetime NOT NULL,
  `data_fechamento` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_chamado` (`numero_chamado`),
  KEY `escola_id` (`escola_id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `chamados_suporte_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `chamados_suporte_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`),
  CONSTRAINT `chamados_suporte_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `chamados_suporte` VALUES('1','CHAM-20260506-8628','2',NULL,'3','fffff','ffffff','tecnico','media','aberto','2026-05-06 22:56:35',NULL,'2026-05-06 22:56:35','2026-05-06 22:56:35');

DROP TABLE IF EXISTS `cidades`;
CREATE TABLE `cidades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `pais_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cidade_pais` (`nome`,`pais_id`),
  KEY `pais_id` (`pais_id`),
  CONSTRAINT `cidades_ibfk_1` FOREIGN KEY (`pais_id`) REFERENCES `paises` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cidades` VALUES('1','Luanda','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('2','Benguela','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('3','Huambo','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('4','Lubango','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('5','Malanje','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('6','Kuito','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('7','Cabinda','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('8','Uíge','1','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('9','Lisboa','2','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('10','Porto','2','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('11','Rio de Janeiro','3','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('12','São Paulo','3','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('13','Praia','4','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('14','Maputo','6','2026-04-11 16:13:58');
INSERT INTO `cidades` VALUES('15','Bissau','7','2026-04-11 16:13:58');

DROP TABLE IF EXISTS `classe_curso`;
CREATE TABLE `classe_curso` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `classe_id` int(11) NOT NULL,
  `curso_id` int(11) NOT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_associacao` (`classe_id`,`curso_id`),
  KEY `idx_escola_classe_curso` (`escola_id`),
  KEY `idx_classe` (`classe_id`),
  KEY `idx_curso` (`curso_id`),
  CONSTRAINT `classe_curso_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classe_curso_ibfk_2` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classe_curso_ibfk_3` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `classe_curso` VALUES('1','2','1','11','ativo','2026-05-01 08:19:30');
INSERT INTO `classe_curso` VALUES('2','2','2','11','ativo','2026-05-01 08:19:42');
INSERT INTO `classe_curso` VALUES('3','2','3','11','ativo','2026-05-01 08:19:53');

DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL COMMENT 'Ex: 1ª Classe, 2ª Classe',
  `descricao` text DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `status` enum('ativa','inativa') DEFAULT 'ativa',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola_classes` (`escola_id`),
  KEY `idx_status_classes` (`status`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `classes` VALUES('1','2','1ª Classe','','0','ativa','2026-04-19 19:16:40');
INSERT INTO `classes` VALUES('2','2','2ª Classe','','0','ativa','2026-04-19 19:17:05');
INSERT INTO `classes` VALUES('3','2','3ª Classe','Classe do ensino primário, onde a cotação é  0 à 10','3','ativa','2026-05-01 07:49:29');

DROP TABLE IF EXISTS `comunicados_coordenacao`;
CREATE TABLE `comunicados_coordenacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `conteudo` text NOT NULL,
  `tipo` enum('informativo','aviso','urgente','circular') DEFAULT 'informativo',
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `destinatarios` text DEFAULT NULL,
  `data_publicacao` date DEFAULT NULL,
  `data_expiracao` date DEFAULT NULL,
  `status` enum('ativo','arquivado') DEFAULT 'ativo',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `comunicados_coordenacao_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comunicados_coordenacao_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `configuracoes_remuneracao`;
CREATE TABLE `configuracoes_remuneracao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cargo` varchar(100) NOT NULL COMMENT 'Cargo do funcionário',
  `tipo_divida` enum('emprestimo','taxa','multa','mensalidade','beneficio','ressarcimento') DEFAULT 'beneficio',
  `descricao` varchar(255) NOT NULL COMMENT 'Descrição do benefício/dívida',
  `valor_base` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor base',
  `porcentagem_salario` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentual do salário',
  `mes_competencia` int(11) DEFAULT NULL COMMENT 'Mês específico (1-12, NULL = todos)',
  `periodicidade` enum('unico','mensal','trimestral','semestral','anual') DEFAULT 'mensal',
  `ativo` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cargo` (`cargo`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `configuracoes_remuneracao` VALUES('1','Professor','beneficio','Subsídio de Alimentação','25000.00','0.00',NULL,'mensal','1','2026-04-27 19:00:26');
INSERT INTO `configuracoes_remuneracao` VALUES('2','Professor','beneficio','Subsídio de Transporte','15000.00','0.00',NULL,'mensal','1','2026-04-27 19:00:26');
INSERT INTO `configuracoes_remuneracao` VALUES('3','Professor','beneficio','Seguro Saúde','8000.00','0.00',NULL,'mensal','1','2026-04-27 19:00:26');
INSERT INTO `configuracoes_remuneracao` VALUES('4','Coordenador','beneficio','Gratificação de Coordenação','50000.00','0.00',NULL,'mensal','1','2026-04-27 19:00:26');
INSERT INTO `configuracoes_remuneracao` VALUES('5','Diretor','beneficio','Gratificação de Direção','75000.00','0.00',NULL,'mensal','1','2026-04-27 19:00:26');

DROP TABLE IF EXISTS `configuracoes_sistema`;
CREATE TABLE `configuracoes_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome_sistema` varchar(100) NOT NULL DEFAULT 'SIGE Angola',
  `sigla` varchar(20) DEFAULT 'SIGE',
  `versao` varchar(20) DEFAULT '2.0.0',
  `email_geral` varchar(100) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `favicon` varchar(255) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'Africa/Luanda',
  `moeda` varchar(10) DEFAULT 'KZ',
  `moeda_simbolo` varchar(10) DEFAULT 'KZ',
  `ano_letivo_atual` year(4) DEFAULT NULL,
  `bimestre_atual` int(11) DEFAULT 1,
  `nota_maxima` int(11) DEFAULT 20,
  `nota_minima_aprovacao` int(11) DEFAULT 10,
  `permite_recuperacao` tinyint(1) DEFAULT 1,
  `limite_faltas` int(11) DEFAULT 20,
  `enviar_email` tinyint(1) DEFAULT 1,
  `email_host` varchar(100) DEFAULT NULL,
  `email_porta` int(11) DEFAULT 587,
  `email_seguranca` varchar(10) DEFAULT 'tls',
  `email_usuario` varchar(100) DEFAULT NULL,
  `email_senha` varchar(255) DEFAULT NULL,
  `email_remetente` varchar(100) DEFAULT NULL,
  `recaptcha_site_key` varchar(100) DEFAULT NULL,
  `recaptcha_secret_key` varchar(100) DEFAULT NULL,
  `manutencao` tinyint(1) DEFAULT 0,
  `manutencao_mensagem` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `configuracoes_sistema` VALUES('1','SIGE Angola','SIGE','2.0.0','','943911384','943911384','',NULL,NULL,'Africa/Luanda','KZ','KZ','2026','1','20','10','1','20','1','','587','tls','',NULL,'','','','1','Não ','2026-04-09 14:11:53','2026-04-11 16:48:49');

DROP TABLE IF EXISTS `conselho_nota_historicos`;
CREATE TABLE `conselho_nota_historicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `solicitacao_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_solicitacao` (`solicitacao_id`),
  CONSTRAINT `conselho_nota_historicos_ibfk_1` FOREIGN KEY (`solicitacao_id`) REFERENCES `conselho_nota_solicitacoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `conselho_nota_participantes`;
CREATE TABLE `conselho_nota_participantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sessao_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `papel` varchar(20) DEFAULT 'membro',
  `presente` tinyint(4) DEFAULT 0,
  `confirmado` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessao_funcionario` (`sessao_id`,`funcionario_id`),
  KEY `idx_sessao` (`sessao_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  CONSTRAINT `conselho_nota_participantes_ibfk_1` FOREIGN KEY (`sessao_id`) REFERENCES `conselho_nota_sessoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_participantes_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `conselho_nota_participantes` VALUES('1','6','2','membro','0','1','2026-04-28 00:47:27');
INSERT INTO `conselho_nota_participantes` VALUES('2','7','2','membro','0','0','2026-04-28 00:47:27');
INSERT INTO `conselho_nota_participantes` VALUES('3','8','2','membro','0','0','2026-04-28 00:47:36');
INSERT INTO `conselho_nota_participantes` VALUES('4','9','2','membro','0','0','2026-04-28 00:47:36');
INSERT INTO `conselho_nota_participantes` VALUES('5','10','2','membro','0','0','2026-04-28 00:47:44');
INSERT INTO `conselho_nota_participantes` VALUES('6','11','2','membro','0','0','2026-04-28 00:47:44');
INSERT INTO `conselho_nota_participantes` VALUES('7','12','2','membro','0','0','2026-04-28 00:48:01');
INSERT INTO `conselho_nota_participantes` VALUES('8','13','2','membro','0','0','2026-04-28 00:48:01');
INSERT INTO `conselho_nota_participantes` VALUES('9','14','2','membro','0','0','2026-04-28 00:51:50');
INSERT INTO `conselho_nota_participantes` VALUES('10','15','2','membro','0','0','2026-04-28 00:51:50');

DROP TABLE IF EXISTS `conselho_nota_permissoes`;
CREATE TABLE `conselho_nota_permissoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coordenador_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `ativo` tinyint(4) DEFAULT 1,
  `criado_por` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_funcionario_ano` (`funcionario_id`,`ano_letivo_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `coordenador_id` (`coordenador_id`),
  KEY `escola_id` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  CONSTRAINT `conselho_nota_permissoes_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_permissoes_ibfk_2` FOREIGN KEY (`coordenador_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_permissoes_ibfk_3` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_permissoes_ibfk_4` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `conselho_nota_permissoes` VALUES('1','1','2','2','3','1',NULL,'2026-04-28 00:47:27','2026-04-28 00:47:27');

DROP TABLE IF EXISTS `conselho_nota_sessoes`;
CREATE TABLE `conselho_nota_sessoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coordenador_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `bimestre` int(11) NOT NULL,
  `titulo` varchar(200) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `data_sessao` date DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fim` time DEFAULT NULL,
  `status` varchar(20) DEFAULT 'agendado',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_turma` (`turma_id`),
  KEY `idx_disciplina` (`disciplina_id`),
  KEY `idx_status` (`status`),
  KEY `coordenador_id` (`coordenador_id`),
  KEY `escola_id` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  CONSTRAINT `conselho_nota_sessoes_ibfk_1` FOREIGN KEY (`coordenador_id`) REFERENCES `funcionarios` (`id`),
  CONSTRAINT `conselho_nota_sessoes_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `conselho_nota_sessoes_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`),
  CONSTRAINT `conselho_nota_sessoes_ibfk_4` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`),
  CONSTRAINT `conselho_nota_sessoes_ibfk_5` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `conselho_nota_sessoes` VALUES('6','1','2','3','9','1','1','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 1º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 1º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:27','2026-04-28 00:47:27');
INSERT INTO `conselho_nota_sessoes` VALUES('7','1','2','3','9','1','2','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 2º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 2º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:27','2026-04-28 00:47:27');
INSERT INTO `conselho_nota_sessoes` VALUES('8','1','2','3','9','1','1','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 1º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 1º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:36','2026-04-28 00:47:36');
INSERT INTO `conselho_nota_sessoes` VALUES('9','1','2','3','9','1','2','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 2º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 2º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:36','2026-04-28 00:47:36');
INSERT INTO `conselho_nota_sessoes` VALUES('10','1','2','3','9','1','1','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 1º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 1º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:44','2026-04-28 00:47:44');
INSERT INTO `conselho_nota_sessoes` VALUES('11','1','2','3','9','1','2','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 2º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 2º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:47:44','2026-04-28 00:47:44');
INSERT INTO `conselho_nota_sessoes` VALUES('12','1','2','3','9','1','1','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 1º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 1º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:48:01','2026-04-28 00:48:01');
INSERT INTO `conselho_nota_sessoes` VALUES('13','1','2','3','9','1','2','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 2º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 2º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:48:01','2026-04-28 00:48:01');
INSERT INTO `conselho_nota_sessoes` VALUES('14','1','2','3','9','1','1','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 1º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 1º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:51:50','2026-04-28 00:51:50');
INSERT INTO `conselho_nota_sessoes` VALUES('15','1','2','3','9','1','2','Conselho de Nota - Lingua Portuguesa - 5º ATA/2025-2026 - 2º Bimestre','Sessão do Conselho de Nota para análise das notas da disciplina Lingua Portuguesa da turma 5º ATA/2025-2026 referente ao 2º Bimestre.','2026-04-28','14:00:00','18:00:00','agendado','2026-04-28 00:51:50','2026-04-28 00:51:50');

DROP TABLE IF EXISTS `conselho_nota_solicitacoes`;
CREATE TABLE `conselho_nota_solicitacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sessao_id` int(11) NOT NULL,
  `funcionario_solicitante_id` int(11) NOT NULL,
  `matricula_id` int(11) NOT NULL,
  `estudante_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `bimestre` int(11) NOT NULL,
  `nota_atual` decimal(5,2) NOT NULL,
  `nota_sugerida` decimal(5,2) NOT NULL,
  `motivo` varchar(200) NOT NULL,
  `justificativa` text NOT NULL,
  `evidencias` text DEFAULT NULL,
  `documento_anexo` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pendente',
  `votos_favoraveis` int(11) DEFAULT 0,
  `votos_contra` int(11) DEFAULT 0,
  `resultado_final` varchar(20) DEFAULT NULL,
  `parecer_final` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sessao` (`sessao_id`),
  KEY `idx_matricula` (`matricula_id`),
  KEY `idx_status` (`status`),
  KEY `estudante_id` (`estudante_id`),
  KEY `disciplina_id` (`disciplina_id`),
  KEY `funcionario_solicitante_id` (`funcionario_solicitante_id`),
  CONSTRAINT `conselho_nota_solicitacoes_ibfk_1` FOREIGN KEY (`sessao_id`) REFERENCES `conselho_nota_sessoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_solicitacoes_ibfk_2` FOREIGN KEY (`matricula_id`) REFERENCES `matriculas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_solicitacoes_ibfk_3` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_solicitacoes_ibfk_4` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`),
  CONSTRAINT `conselho_nota_solicitacoes_ibfk_5` FOREIGN KEY (`funcionario_solicitante_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `conselho_nota_solicitacoes` VALUES('2','6','2','5','10','1','1','6.00','10.00','Erro de Lançamento','Nova nota',NULL,NULL,'em_votacao','1','0',NULL,NULL,'2026-04-30 16:24:22','2026-04-30 16:26:18');
INSERT INTO `conselho_nota_solicitacoes` VALUES('3','6','2','7','12','1','1','3.50','7.00','Erro de Lançamento','Nova nota',NULL,NULL,'em_votacao','1','0',NULL,NULL,'2026-04-30 20:06:44','2026-04-30 20:13:19');
INSERT INTO `conselho_nota_solicitacoes` VALUES('4','7','2','5','10','1','2','2.00','10.00','Erro de Lançamento','Notas',NULL,NULL,'em_votacao','1','0',NULL,NULL,'2026-04-30 22:57:06','2026-04-30 22:57:21');
INSERT INTO `conselho_nota_solicitacoes` VALUES('5','7','2','7','12','1','2','0.00','7.50','Prova de Recuperação','Notas',NULL,NULL,'em_votacao','1','0',NULL,NULL,'2026-04-30 23:00:01','2026-04-30 23:00:12');

DROP TABLE IF EXISTS `conselho_nota_votos`;
CREATE TABLE `conselho_nota_votos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `solicitacao_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `voto` varchar(20) NOT NULL,
  `justificativa` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_solicitacao_funcionario` (`solicitacao_id`,`funcionario_id`),
  KEY `idx_solicitacao` (`solicitacao_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  CONSTRAINT `conselho_nota_votos_ibfk_1` FOREIGN KEY (`solicitacao_id`) REFERENCES `conselho_nota_solicitacoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conselho_nota_votos_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `conselho_nota_votos` VALUES('1','2','2','favoravel','O Aluno tem uma boa presenca','2026-04-30 16:26:18');
INSERT INTO `conselho_nota_votos` VALUES('10','3','2','favoravel','s','2026-04-30 20:13:19');
INSERT INTO `conselho_nota_votos` VALUES('11','4','2','favoravel','ssd','2026-04-30 22:57:21');
INSERT INTO `conselho_nota_votos` VALUES('12','5','2','favoravel','','2026-04-30 23:00:12');

DROP TABLE IF EXISTS `contas_bancarias`;
CREATE TABLE `contas_bancarias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `banco` varchar(100) NOT NULL,
  `agencia` varchar(20) DEFAULT NULL,
  `numero_conta` varchar(50) NOT NULL,
  `titular` varchar(200) DEFAULT NULL,
  `saldo_inicial` decimal(15,2) DEFAULT 0.00,
  `saldo_atual` decimal(15,2) DEFAULT 0.00,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `contas_bancarias_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `cursos`;
CREATE TABLE `cursos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(11) NOT NULL,
  `nome` varchar(150) NOT NULL COMMENT 'Nome do curso',
  `sigla` varchar(20) DEFAULT NULL COMMENT 'Sigla do curso',
  `descricao` text DEFAULT NULL COMMENT 'Descrição do curso',
  `nivel_id` int(11) DEFAULT NULL COMMENT 'Nível de ensino do curso',
  `duracao_meses` int(11) DEFAULT NULL COMMENT 'Duração em meses',
  `duracao_anos` int(11) DEFAULT NULL COMMENT 'Duração em anos',
  `carga_horaria_total` int(11) DEFAULT NULL COMMENT 'Carga horária total em horas',
  `valor_mensalidade` decimal(10,2) DEFAULT NULL COMMENT 'Valor da mensalidade',
  `requisitos` text DEFAULT NULL COMMENT 'Pré-requisitos para ingresso',
  `certificado_emitido` varchar(100) DEFAULT NULL COMMENT 'Tipo de certificado',
  `escola_id` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cursos_status` (`status`),
  KEY `idx_cursos_nivel` (`nivel_id`),
  KEY `idx_cursos_escola` (`escola_id`),
  CONSTRAINT `fk_cursos_nivel` FOREIGN KEY (`nivel_id`) REFERENCES `niveis` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cursos oferecidos pela escola';

INSERT INTO `cursos` VALUES('1','132','Informática','INF','Curso Técnico em Informática','4','36','3','1200',NULL,NULL,'Técnico em Informática','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `cursos` VALUES('2','1333','Administração','ADM','Curso Técnico em Administração','4','36','3','1200',NULL,NULL,'Técnico em Administração','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `cursos` VALUES('3','523ee','Enfermagem','ENF','Curso Técnico em Enfermagem','4','24','2','800',NULL,NULL,'Técnico em Enfermagem','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `cursos` VALUES('4','34WE','Desenvolvimento de Sistemas','DS','Curso de Desenvolvimento de Sistemas','4','36','3','1200',NULL,NULL,'Técnico em Desenvolvimento de Sistemas','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `cursos` VALUES('6','34REE','Contabilidade','CONT','Curso Técnico em Contabilidade','4','24','2','800',NULL,NULL,'Técnico em Contabilidade','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `cursos` VALUES('7','44RER','Energia e Instalações Electrica','EEI','','4',NULL,'4','1200','15000.00','','','2','1','2026-04-18 19:54:53','2026-04-18 20:06:13');
INSERT INTO `cursos` VALUES('8','ELE001','Electrotecnia','ELE','','4',NULL,'3',NULL,'6000.00','','','2','1','2026-04-18 20:12:33',NULL);
INSERT INTO `cursos` VALUES('9','DFD001','dfdsf','DFD','',NULL,NULL,NULL,NULL,NULL,'','','2','1','2026-04-18 20:17:10',NULL);
INSERT INTO `cursos` VALUES('10','EER001','eerer','EER','',NULL,NULL,NULL,NULL,NULL,'','','2','1','2026-04-18 20:17:18',NULL);
INSERT INTO `cursos` VALUES('11','ETY001','Ensino primário da pré a 4ª classe','ETY','','1','1','5',NULL,'5000.00','','','2','1','2026-04-18 20:17:26','2026-05-01 07:52:11');
INSERT INTO `cursos` VALUES('12','VFD001','vfdsfsdfdsv','VFD','',NULL,NULL,NULL,NULL,NULL,'','','2','1','2026-04-18 20:17:40',NULL);

DROP TABLE IF EXISTS `disciplina_classe`;
CREATE TABLE `disciplina_classe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `classe_id` int(11) NOT NULL,
  `carga_horaria` int(11) DEFAULT 0,
  `ano_letivo` varchar(9) DEFAULT NULL,
  `periodo` varchar(20) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_relacao` (`disciplina_id`,`classe_id`,`ano_letivo`),
  KEY `idx_escola_disciplina_classe` (`escola_id`),
  KEY `idx_disciplina_classe` (`disciplina_id`),
  KEY `idx_classe_disciplina` (`classe_id`),
  CONSTRAINT `disciplina_classe_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disciplina_classe_ibfk_2` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disciplina_classe_ibfk_3` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `disciplina_classe` VALUES('1','2','1','1','4','2026/2027','1º Bimestre','ativo','2026-04-22 02:20:33','2026-04-22 02:20:33');
INSERT INTO `disciplina_classe` VALUES('2','2','1','2','4','2026/2027','1º Bimestre','ativo','2026-04-22 02:20:59','2026-04-22 02:20:59');

DROP TABLE IF EXISTS `disciplina_turma`;
CREATE TABLE `disciplina_turma` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `professor_id` int(11) DEFAULT NULL,
  `carga_horaria` int(11) DEFAULT 0,
  `ano_letivo` varchar(9) DEFAULT NULL,
  `periodo` varchar(20) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_relacao` (`disciplina_id`,`turma_id`,`ano_letivo`),
  KEY `escola_id` (`escola_id`),
  KEY `turma_id` (`turma_id`),
  KEY `professor_id` (`professor_id`),
  CONSTRAINT `disciplina_turma_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disciplina_turma_ibfk_2` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disciplina_turma_ibfk_3` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disciplina_turma_ibfk_4` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `disciplina_turma` VALUES('1','2','1','9',NULL,'4','2026/2027','1º Bimestre','ativo','2026-04-19 19:15:03','2026-04-19 19:15:03');

DROP TABLE IF EXISTS `disciplinas`;
CREATE TABLE `disciplinas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `codigo` varchar(20) DEFAULT NULL,
  `carga_horaria` int(11) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `status` enum('ativa','inativa') DEFAULT 'ativa',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `disciplinas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `disciplinas` VALUES('1','2','Matemática','MAT','180','Disciplina de Matemática - Estudo de números, álgebra, geometria e estatística.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('2','2','Português','PORT','180','Disciplina de Língua Portuguesa - Gramática, redação e literatura.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('3','2','Física','FIS','120','Disciplina de Física - Estudo da matéria, energia e suas interações.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('4','2','Química','QUIM','120','Disciplina de Química - Estudo da matéria, suas transformações e propriedades.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('5','2','História','HIST','120','Disciplina de História - Estudo dos acontecimentos passados da humanidade.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('6','2','Geografia','GEOG','120','Disciplina de Geografia - Estudo do espaço geográfico e relações sociedade-natureza.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('7','2','Inglês','ING','120','Disciplina de Língua Inglesa - Vocabulário, gramática e conversação.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('8','2','Biologia','BIO','120','Disciplina de Biologia - Estudo da vida e dos seres vivos.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('9','2','EMC','FIL','80','Disciplina de Filosofia - Estudo dos fundamentos do pensamento humano.','ativa','2026-04-29 23:16:38','2026-05-01 07:53:24');
INSERT INTO `disciplinas` VALUES('10','2','Educação Física','EDF','80','Disciplina de Educação Física - Atividades físicas e esportes.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('11','2','Ciência da Natureza','SOC','80','Disciplina de Sociologia - Estudo da sociedade e suas estruturas.','ativa','2026-04-29 23:16:38','2026-05-01 07:54:46');
INSERT INTO `disciplinas` VALUES('12','2','Educação Laboral','PSI','80','Disciplina de Psicologia - Estudo do comportamento humano.','ativa','2026-04-29 23:16:38','2026-05-01 07:54:00');
INSERT INTO `disciplinas` VALUES('13','2','Informática','INF','120','Disciplina de Informática - Introdução à computação e programação.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('14','2','Literatura','LIT','80','Disciplina de Literatura - Estudo de obras literárias e autores.','ativa','2026-04-29 23:16:38',NULL);
INSERT INTO `disciplinas` VALUES('15','2','EVP','ART','80','Disciplina de Artes - Expressão artística e história da arte.','ativa','2026-04-29 23:16:38','2026-05-01 07:52:56');

DROP TABLE IF EXISTS `divida_parcelas`;
CREATE TABLE `divida_parcelas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `divida_id` int(11) NOT NULL,
  `numero_parcela` int(11) NOT NULL,
  `valor_parcela` decimal(10,2) NOT NULL,
  `data_vencimento` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `status` enum('pendente','pago','vencido') DEFAULT 'pendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `divida_id` (`divida_id`),
  CONSTRAINT `divida_parcelas_ibfk_1` FOREIGN KEY (`divida_id`) REFERENCES `dividas_a_receber` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dividas`;
CREATE TABLE `dividas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL COMMENT 'ID do funcionário/devedor',
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `descricao` varchar(255) NOT NULL COMMENT 'Descrição da dívida',
  `referencia` varchar(100) DEFAULT NULL COMMENT 'Número de referência/processo',
  `tipo` enum('emprestimo','taxa','multa','mensalidade','outro') DEFAULT 'outro' COMMENT 'Tipo da dívida',
  `valor_original` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor original da dívida',
  `valor_pago` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor já pago',
  `valor_restante` decimal(10,2) GENERATED ALWAYS AS (`valor_original` - `valor_pago`) STORED COMMENT 'Valor restante (calculado automaticamente)',
  `juros` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor de juros aplicados',
  `multas` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor de multas aplicadas',
  `desconto` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto concedido',
  `data_vencimento` date NOT NULL COMMENT 'Data de vencimento',
  `data_emissao` date DEFAULT curdate() COMMENT 'Data de emissão da dívida',
  `data_pagamento` date DEFAULT NULL COMMENT 'Data do pagamento total',
  `numero_parcelas` int(11) DEFAULT 1 COMMENT 'Número total de parcelas',
  `parcela_atual` int(11) DEFAULT 1 COMMENT 'Parcela atual',
  `valor_parcela` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor por parcela',
  `desconto_folha` tinyint(1) DEFAULT 0 COMMENT 'Descontar em folha (0=Não, 1=Sim)',
  `processado_folha` tinyint(1) DEFAULT 0 COMMENT 'Já processado na folha (0=Não, 1=Sim)',
  `mes_processamento` int(11) DEFAULT NULL COMMENT 'Mês de processamento (1-12)',
  `ano_processamento` int(11) DEFAULT NULL COMMENT 'Ano de processamento',
  `processamento_id` int(11) DEFAULT NULL COMMENT 'ID do registro na folha_processamento_funcionarios',
  `forma_pagamento` enum('transferencia','deposito','dinheiro','cheque','folha') DEFAULT NULL COMMENT 'Forma de pagamento',
  `observacao_pagamento` text DEFAULT NULL COMMENT 'Observação sobre o pagamento',
  `status` enum('pendente','vencido','negociando','pago','cancelado','processado_folha') DEFAULT 'pendente' COMMENT 'Status da dívida',
  `observacoes` text DEFAULT NULL COMMENT 'Observações gerais',
  `anexo` varchar(255) DEFAULT NULL COMMENT 'Caminho do arquivo anexo (comprovante, contrato, etc.)',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL COMMENT 'ID do usuário que criou',
  `updated_by` int(11) DEFAULT NULL COMMENT 'ID do usuário que atualizou',
  PRIMARY KEY (`id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_ano_letivo` (`ano_letivo_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_vencimento` (`data_vencimento`),
  KEY `idx_desconto_folha` (`desconto_folha`,`processado_folha`),
  CONSTRAINT `dividas_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dividas` VALUES('1','2','1','3','Empréstimo Pessoal','EMP-2024-001','emprestimo','150000.00','150000.00','0.00','0.00','0.00','0.00','2026-05-12','2026-04-27','2026-04-27','1','1','0.00','1','0',NULL,NULL,NULL,'transferencia','','pago',NULL,NULL,'2026-04-27 18:13:18','2026-04-27 18:21:11',NULL,NULL);
INSERT INTO `dividas` VALUES('2','1','1','3','Empréstimo Pessoal','EMP-2024-001','emprestimo','150000.00','0.00','150000.00','0.00','0.00','0.00','2026-05-12','2026-04-27',NULL,'1','1','0.00','1','0',NULL,NULL,NULL,NULL,NULL,'pendente',NULL,NULL,'2026-04-27 18:13:18','2026-04-27 18:13:18',NULL,NULL);
INSERT INTO `dividas` VALUES('3','2','2','3','Empréstimo Pessoal','EMP-2024-001','emprestimo','150000.00','0.00','150000.00','0.00','0.00','0.00','2026-05-12','2026-04-27',NULL,'1','1','0.00','1','0',NULL,NULL,NULL,NULL,NULL,'pendente',NULL,NULL,'2026-04-27 18:13:18','2026-04-27 18:13:18',NULL,NULL);
INSERT INTO `dividas` VALUES('4','1','2','3','Empréstimo Pessoal','EMP-2024-001','emprestimo','150000.00','0.00','150000.00','0.00','0.00','0.00','2026-05-12','2026-04-27',NULL,'1','1','0.00','1','0',NULL,NULL,NULL,NULL,NULL,'pendente',NULL,NULL,'2026-04-27 18:13:18','2026-04-27 18:13:18',NULL,NULL);

DROP TABLE IF EXISTS `dividas_a_pagar`;
CREATE TABLE `dividas_a_pagar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `tipo` varchar(50) DEFAULT 'vale',
  `referencia_id` int(11) DEFAULT NULL,
  `valor_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_pago` decimal(10,2) DEFAULT 0.00,
  `valor_pendente` decimal(10,2) NOT NULL DEFAULT 0.00,
  `parcelas_total` int(11) DEFAULT 1,
  `parcelas_pagas` int(11) DEFAULT 0,
  `primeira_parcela` date DEFAULT NULL,
  `ultima_parcela` date DEFAULT NULL,
  `status` enum('ativa','paga','cancelada','vencida') DEFAULT 'ativa',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_referencia` (`referencia_id`),
  KEY `idx_datas_vencimento` (`primeira_parcela`,`ultima_parcela`),
  CONSTRAINT `dividas_a_pagar_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_a_pagar_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_a_pagar_ibfk_3` FOREIGN KEY (`referencia_id`) REFERENCES `solicitacoes_vale` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dividas_a_receber`;
CREATE TABLE `dividas_a_receber` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `tipo` enum('emprestimo','taxa','multa','mensalidade','beneficio','ressarcimento') DEFAULT 'beneficio',
  `valor_original` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_recebido` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_restante` decimal(10,2) GENERATED ALWAYS AS (`valor_original` - `valor_recebido`) STORED,
  `juros` decimal(10,2) NOT NULL DEFAULT 0.00,
  `multas` decimal(10,2) NOT NULL DEFAULT 0.00,
  `desconto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `data_vencimento` date NOT NULL,
  `data_emissao` date DEFAULT curdate(),
  `data_recebimento` date DEFAULT NULL,
  `mes_competencia` int(11) DEFAULT NULL,
  `ano_competencia` int(11) DEFAULT NULL,
  `status` enum('pendente','vencido','negociando','recebido','cancelado','parcial') DEFAULT 'pendente',
  `devedor_nome` varchar(200) DEFAULT NULL,
  `devedor_documento` varchar(50) DEFAULT NULL,
  `forma_recebimento` enum('transferencia','deposito','dinheiro','cheque','compensacao','automatico') DEFAULT NULL,
  `observacao_recebimento` text DEFAULT NULL,
  `comprovante` varchar(255) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `gerado_automaticamente` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_ano_letivo` (`ano_letivo_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_vencimento` (`data_vencimento`),
  CONSTRAINT `dividas_a_receber_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_a_receber_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dividas_a_receber_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `documentos_secretaria`;
CREATE TABLE `documentos_secretaria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `categoria` enum('oficio','certidao','declaracao','atestado','requerimento','comunicado','outro') DEFAULT 'outro',
  `tipo_documento` enum('pdf','word','excel','imagem','link') DEFAULT 'pdf',
  `arquivo` varchar(255) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `destinatario` varchar(200) DEFAULT NULL,
  `data_validade` date DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `documentos_secretaria_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `documentos_secretaria` VALUES('1','2','Acta nº 45-Reunião com os professores','','oficio','pdf','doc_1778132365_69fc258d6e3da.pdf','../../uploads/documentos/doc_1778132365_69fc258d6e3da.pdf','Para todod os professores','2026-05-07','','inativo','3','2026-05-07 06:39:25','2026-05-07 06:40:26');

DROP TABLE IF EXISTS `email_queue`;
CREATE TABLE `email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destinatario` varchar(255) NOT NULL,
  `assunto` varchar(255) NOT NULL,
  `mensagem` text NOT NULL,
  `status` enum('pendente','enviado','falha') DEFAULT 'pendente',
  `tentativas` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `emprestimo_materiais`;
CREATE TABLE `emprestimo_materiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `material_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `data_emprestimo` date NOT NULL,
  `data_devolucao_prevista` date NOT NULL,
  `data_devolucao_real` date DEFAULT NULL,
  `quantidade` int(11) DEFAULT 1,
  `status` varchar(20) DEFAULT 'emprestado',
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `material_id` (`material_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `emprestimo_materiais_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materiais_didaticos` (`id`),
  CONSTRAINT `emprestimo_materiais_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `emprestimo_materiais` VALUES('1','31','1','2026-04-14','2026-04-21','2026-04-19','1','devolvido',NULL,'2026-04-29 23:16:38');
INSERT INTO `emprestimo_materiais` VALUES('2','36','1','2026-04-17','2026-04-24',NULL,'1','emprestado',NULL,'2026-04-29 23:16:38');
INSERT INTO `emprestimo_materiais` VALUES('3','45','1','2026-04-09','2026-04-16','2026-04-15','2','devolvido',NULL,'2026-04-29 23:16:38');
INSERT INTO `emprestimo_materiais` VALUES('4','51','1','2026-04-21','2026-05-05',NULL,'1','emprestado',NULL,'2026-04-29 23:16:38');
INSERT INTO `emprestimo_materiais` VALUES('5','58','1','2026-04-04','2026-04-11','2026-04-10','1','devolvido',NULL,'2026-04-29 23:16:38');
INSERT INTO `emprestimo_materiais` VALUES('9','81','1','2026-04-29','2026-05-06',NULL,'1','emprestado','','2026-04-29 23:50:41');
INSERT INTO `emprestimo_materiais` VALUES('10','179','1','2026-04-30','2026-05-06',NULL,'1','emprestado','','2026-04-30 00:15:58');
INSERT INTO `emprestimo_materiais` VALUES('11','187','1','2026-04-30','2026-05-06',NULL,'3','emprestado','','2026-04-30 00:31:48');
INSERT INTO `emprestimo_materiais` VALUES('12','187','1','2026-04-30','2026-05-06',NULL,'3','emprestado','','2026-04-30 00:43:21');
INSERT INTO `emprestimo_materiais` VALUES('13','187','1','2026-04-30','2026-05-06',NULL,'3','emprestado','','2026-04-30 00:43:33');
INSERT INTO `emprestimo_materiais` VALUES('14','183','1','2026-04-30','2026-05-06',NULL,'2','emprestado','','2026-04-30 00:45:47');
INSERT INTO `emprestimo_materiais` VALUES('15','380','1','2026-04-30','2026-05-07',NULL,'1','emprestado','','2026-04-30 01:16:53');

DROP TABLE IF EXISTS `escola_acordos_parcelamento`;
CREATE TABLE `escola_acordos_parcelamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `valor_total` decimal(15,2) NOT NULL,
  `numero_parcelas` int(11) NOT NULL,
  `valor_parcela` decimal(15,2) NOT NULL,
  `entrada` decimal(15,2) DEFAULT 0.00,
  `data_acordo` date NOT NULL,
  `status` enum('ativo','concluido','cancelado') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_aluno` (`aluno_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `escola_acordos_parcelamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_acordos_parcelamento_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_categorias_orcamento`;
CREATE TABLE `escola_categorias_orcamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('receita','despesa') NOT NULL,
  `cor` varchar(7) DEFAULT '#006B3E',
  `ordem` int(11) DEFAULT 0,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_categoria` (`nome`,`tipo`,`escola_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_tipo` (`tipo`),
  CONSTRAINT `escola_categorias_orcamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `escola_categorias_orcamento` VALUES('1','1','Mensalidades','receita','#28a745','1','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('2','2','Mensalidades','receita','#28a745','1','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('3','1','Matrículas','receita','#20c997','2','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('4','2','Matrículas','receita','#20c997','2','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('5','1','Taxas Escolares','receita','#17a2b8','3','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('6','2','Taxas Escolares','receita','#17a2b8','3','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('7','1','Doações','receita','#ffc107','4','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('8','2','Doações','receita','#ffc107','4','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('9','1','Outras Receitas','receita','#6f42c1','5','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('10','2','Outras Receitas','receita','#6f42c1','5','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('11','1','Salários','despesa','#dc3545','1','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('12','2','Salários','despesa','#dc3545','1','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('13','1','Material Escolar','despesa','#fd7e14','2','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('14','2','Material Escolar','despesa','#fd7e14','2','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('15','1','Utilidades (Água/Luz)','despesa','#e83e8c','3','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('16','2','Utilidades (Água/Luz)','despesa','#e83e8c','3','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('17','1','Manutenção','despesa','#6c757d','4','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('18','2','Manutenção','despesa','#6c757d','4','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('19','1','Impostos','despesa','#343a40','5','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('20','2','Impostos','despesa','#343a40','5','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('21','1','Outras Despesas','despesa','#adb5bd','6','ativo','2026-04-16 00:02:52');
INSERT INTO `escola_categorias_orcamento` VALUES('22','2','Outras Despesas','despesa','#adb5bd','6','ativo','2026-04-16 00:02:52');

DROP TABLE IF EXISTS `escola_contas_bancarias`;
CREATE TABLE `escola_contas_bancarias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `banco` varchar(100) NOT NULL,
  `tipo_conta` enum('corrente','poupanca','salario','empresarial') DEFAULT 'corrente',
  `agencia` varchar(20) DEFAULT NULL,
  `numero_conta` varchar(50) NOT NULL,
  `digito` varchar(5) DEFAULT NULL,
  `titular` varchar(200) DEFAULT NULL,
  `nif` varchar(20) DEFAULT NULL,
  `saldo_inicial` decimal(15,2) DEFAULT 0.00,
  `saldo_atual` decimal(15,2) DEFAULT 0.00,
  `iban` varchar(50) DEFAULT NULL,
  `swift` varchar(20) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `escola_contas_bancarias_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_contas_pagar`;
CREATE TABLE `escola_contas_pagar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `fornecedor` varchar(200) NOT NULL,
  `documento` varchar(50) DEFAULT NULL,
  `descricao` text NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `valor_pago` decimal(15,2) DEFAULT 0.00,
  `desconto` decimal(15,2) DEFAULT 0.00,
  `multa` decimal(15,2) DEFAULT 0.00,
  `juros` decimal(15,2) DEFAULT 0.00,
  `data_emissao` date NOT NULL,
  `data_vencimento` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `categoria` enum('material','servico','utilidade','salario','imposto','outro') DEFAULT 'outro',
  `parcela` int(11) DEFAULT 1,
  `total_parcelas` int(11) DEFAULT 1,
  `status` enum('pendente','parcial','pago','cancelado','vencido') DEFAULT 'pendente',
  `observacoes` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_fornecedor` (`fornecedor`),
  KEY `idx_status` (`status`),
  KEY `idx_vencimento` (`data_vencimento`),
  CONSTRAINT `escola_contas_pagar_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_contas_pagar_ibfk_2` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_contas_pagar_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_contas_receber`;
CREATE TABLE `escola_contas_receber` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `documento` varchar(50) DEFAULT NULL,
  `descricao` text NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `valor_recebido` decimal(15,2) DEFAULT 0.00,
  `desconto` decimal(15,2) DEFAULT 0.00,
  `multa` decimal(15,2) DEFAULT 0.00,
  `juros` decimal(15,2) DEFAULT 0.00,
  `data_emissao` date NOT NULL,
  `data_vencimento` date NOT NULL,
  `data_recebimento` date DEFAULT NULL,
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `parcela` int(11) DEFAULT 1,
  `total_parcelas` int(11) DEFAULT 1,
  `status` enum('pendente','parcial','recebido','cancelado','vencido') DEFAULT 'pendente',
  `observacoes` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_vencimento` (`data_vencimento`),
  KEY `idx_aluno` (`aluno_id`),
  CONSTRAINT `escola_contas_receber_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_contas_receber_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_contas_receber_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_contas_receber_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_fluxo_caixa`;
CREATE TABLE `escola_fluxo_caixa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `data_movimento` date NOT NULL,
  `tipo` enum('entrada','saida') NOT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `descricao` text NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `documento` varchar(50) DEFAULT NULL,
  `conta_id` int(11) DEFAULT NULL,
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `status` enum('confirmado','pendente','cancelado') DEFAULT 'confirmado',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `conta_id` (`conta_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_data` (`data_movimento`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_status` (`status`),
  CONSTRAINT `escola_fluxo_caixa_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_fluxo_caixa_ibfk_2` FOREIGN KEY (`conta_id`) REFERENCES `escola_contas_bancarias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_fluxo_caixa_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_fluxo_caixa_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_formas_pagamento`;
CREATE TABLE `escola_formas_pagamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo` enum('dinheiro','transferencia','cheque','multicaixa','deposito') DEFAULT 'dinheiro',
  `taxa_juros` decimal(5,2) DEFAULT 0.00,
  `taxa_multa` decimal(5,2) DEFAULT 0.00,
  `parcelas_maximo` int(11) DEFAULT 1,
  `instrucoes` text DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `escola_formas_pagamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_historicos_pagamento`;
CREATE TABLE `escola_historicos_pagamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `mensalidade_id` int(11) NOT NULL,
  `valor_pago` decimal(15,2) NOT NULL,
  `data_pagamento` date NOT NULL,
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `mensalidade_id` (`mensalidade_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `escola_historicos_pagamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_historicos_pagamento_ibfk_2` FOREIGN KEY (`mensalidade_id`) REFERENCES `escola_mensalidades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_historicos_pagamento_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_historicos_pagamento_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_mensalidades`;
CREATE TABLE `escola_mensalidades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `ano_letivo` varchar(9) NOT NULL,
  `mes` int(11) NOT NULL,
  `ano` int(11) NOT NULL,
  `valor_original` decimal(15,2) NOT NULL,
  `valor_pago` decimal(15,2) DEFAULT 0.00,
  `desconto` decimal(15,2) DEFAULT 0.00,
  `multa` decimal(15,2) DEFAULT 0.00,
  `juros` decimal(15,2) DEFAULT 0.00,
  `data_vencimento` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `status` enum('pendente','parcial','pago','vencido','cancelado') DEFAULT 'pendente',
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `comprovativo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `aluno_id` (`aluno_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `escola_mensalidades_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_mensalidades_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_mensalidades_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_mensalidades_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_orcamento`;
CREATE TABLE `escola_orcamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `ano` int(11) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `tipo` enum('receita','despesa') NOT NULL,
  `valor_previsto` decimal(15,2) NOT NULL,
  `valor_realizado` decimal(15,2) DEFAULT 0.00,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_orcamento` (`ano`,`categoria`,`tipo`,`escola_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_ano` (`ano`),
  KEY `idx_tipo` (`tipo`),
  CONSTRAINT `escola_orcamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_pagamentos`;
CREATE TABLE `escola_pagamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `forma_pagamento_id` int(11) NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `valor_pago` decimal(15,2) NOT NULL,
  `desconto` decimal(15,2) DEFAULT 0.00,
  `multa` decimal(15,2) DEFAULT 0.00,
  `referencia` varchar(100) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `data_vencimento` date DEFAULT NULL,
  `data_pagamento` date DEFAULT NULL,
  `status` enum('pendente','pago','parcial','vencido','cancelado') DEFAULT 'pendente',
  `comprovativo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `aluno_id` (`aluno_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `escola_pagamentos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_pagamentos_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_pagamentos_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_pagamentos_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_parametros_sistema`;
CREATE TABLE `escola_parametros_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `parametro` varchar(100) NOT NULL COMMENT 'Ex: lancamento_notas, periodo_matriculas, etc',
  `valor` text DEFAULT NULL,
  `data_abertura` datetime DEFAULT NULL COMMENT 'Data quando o parâmetro foi ativado',
  `data_fechamento` datetime DEFAULT NULL COMMENT 'Data quando o parâmetro foi desativado',
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_parametro_escola` (`parametro`,`escola_id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `escola_parametros_sistema_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `escola_parametros_sistema` VALUES('1','1','lancamento_notas','fechado',NULL,NULL,'2026-04-14 02:08:41','2026-04-14 02:08:41');
INSERT INTO `escola_parametros_sistema` VALUES('2','2','lancamento_notas','aberto','2026-04-23 14:50:17','2026-05-23 14:50:17','2026-04-23 13:50:17','2026-04-14 02:08:41');
INSERT INTO `escola_parametros_sistema` VALUES('10','2','folha_tabela_inss','[{\"faixa\":\"At\\u00e9 100.000 Kz\",\"limite\":100000,\"aliquota\":3,\"deducao\":0},{\"faixa\":\"De 100.001 a 200.000 Kz\",\"limite\":200000,\"aliquota\":6,\"deducao\":3000},{\"faixa\":\"De 200.001 a 350.000 Kz\",\"limite\":350000,\"aliquota\":9,\"deducao\":9000},{\"faixa\":\"Acima de 350.000 Kz\",\"limite\":999999999,\"aliquota\":12,\"deducao\":19500}]',NULL,NULL,'2026-04-16 02:55:19','2026-04-16 02:55:19');
INSERT INTO `escola_parametros_sistema` VALUES('12','2','folha_tabela_irrf','[{\"faixa\":\"At\\u00e9 100.000 Kz\",\"limite\":100000,\"aliquota\":0,\"deducao\":0},{\"faixa\":\"De 100.001 a 200.000 Kz\",\"limite\":200000,\"aliquota\":10,\"deducao\":10000},{\"faixa\":\"De 200.001 a 350.000 Kz\",\"limite\":350000,\"aliquota\":15,\"deducao\":20000},{\"faixa\":\"De 350.001 a 500.000 Kz\",\"limite\":500000,\"aliquota\":20,\"deducao\":37500},{\"faixa\":\"Acima de 500.000 Kz\",\"limite\":999999999,\"aliquota\":25,\"deducao\":62500}]',NULL,NULL,'2026-04-16 02:55:35','2026-04-16 02:55:35');
INSERT INTO `escola_parametros_sistema` VALUES('13','2','folha_vale_transporte_percentual','6',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('14','2','folha_vale_refeicao_valor','15',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('15','2','folha_horas_semanais','44',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('16','2','folha_salario_minimo','100000',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('17','2','folha_decimo_terceiro','1',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('18','2','folha_ferias_proporcionais','1',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('19','2','folha_notificacao_email','',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');
INSERT INTO `escola_parametros_sistema` VALUES('20','2','folha_dias_pagamento','5',NULL,NULL,'2026-04-16 02:55:41','2026-04-16 02:55:41');

DROP TABLE IF EXISTS `escola_parcelas_acordo`;
CREATE TABLE `escola_parcelas_acordo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `acordo_id` int(11) NOT NULL,
  `numero_parcela` int(11) NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `valor_pago` decimal(15,2) DEFAULT 0.00,
  `data_vencimento` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `status` enum('pendente','pago','vencido','parcial') DEFAULT 'pendente',
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `idx_acordo` (`acordo_id`),
  KEY `idx_status` (`status`),
  KEY `idx_vencimento` (`data_vencimento`),
  CONSTRAINT `escola_parcelas_acordo_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_parcelas_acordo_ibfk_2` FOREIGN KEY (`acordo_id`) REFERENCES `escola_acordos_parcelamento` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_parcelas_acordo_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_plano_contas`;
CREATE TABLE `escola_plano_contas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('ativo','passivo','receita','despesa','patrimonio') NOT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `saldo_inicial` decimal(15,2) DEFAULT 0.00,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_codigo` (`codigo`,`escola_id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `escola_plano_contas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `escola_plano_contas` VALUES('1','2','1.1.1','Caixa','ativo','Disponivel','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('2','2','1.1.2','Bancos','ativo','Disponivel','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('3','2','1.2.1','Contas a Receber','ativo','Receber','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('4','2','2.1.1','Contas a Pagar','passivo','Pagar','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('5','2','2.1.2','Salários a Pagar','passivo','Pagar','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('6','2','3.1.1','Mensalidades','receita','Receitas','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('7','2','3.1.2','Matrículas','receita','Receitas','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('8','2','3.1.3','Taxas','receita','Receitas','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('9','2','4.1.1','Salários','despesa','Pessoal','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('10','2','4.1.2','Material Escolar','despesa','Operacional','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('11','2','4.1.3','Utilidades','despesa','Operacional','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('12','2','4.1.4','Manutenção','despesa','Operacional','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('13','2','5.1.1','Capital Social','patrimonio','Patrimonio','0.00','ativo','2026-04-15 23:45:22');
INSERT INTO `escola_plano_contas` VALUES('14','1','1.1.1','Caixa','ativo','Disponivel','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('15','1','1.1.2','Bancos','ativo','Disponivel','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('16','1','1.2.1','Contas a Receber','ativo','Receber','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('17','1','2.1.1','Contas a Pagar','passivo','Pagar','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('18','1','2.1.2','Salários a Pagar','passivo','Pagar','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('19','1','3.1.1','Mensalidades','receita','Receitas','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('20','1','3.1.2','Matrículas','receita','Receitas','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('21','1','3.1.3','Taxas','receita','Receitas','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('22','1','4.1.1','Salários','despesa','Pessoal','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('23','1','4.1.2','Material Escolar','despesa','Operacional','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('24','1','4.1.3','Utilidades','despesa','Operacional','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('25','1','4.1.4','Manutenção','despesa','Operacional','0.00','ativo','2026-04-16 00:02:51');
INSERT INTO `escola_plano_contas` VALUES('26','1','5.1.1','Capital Social','patrimonio','Patrimonio','0.00','ativo','2026-04-16 00:02:51');

DROP TABLE IF EXISTS `escola_recibos`;
CREATE TABLE `escola_recibos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `numero_recibo` varchar(20) NOT NULL,
  `aluno_id` int(11) NOT NULL,
  `tipo` enum('mensalidade','matricula','taxa','outro') DEFAULT 'mensalidade',
  `valor` decimal(15,2) NOT NULL,
  `desconto` decimal(15,2) DEFAULT 0.00,
  `multa` decimal(15,2) DEFAULT 0.00,
  `juros` decimal(15,2) DEFAULT 0.00,
  `valor_total` decimal(15,2) NOT NULL,
  `data_emissao` date NOT NULL,
  `data_pagamento` date DEFAULT NULL,
  `forma_pagamento_id` int(11) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('emitido','cancelado') DEFAULT 'emitido',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_numero_recibo` (`numero_recibo`),
  KEY `escola_id` (`escola_id`),
  KEY `aluno_id` (`aluno_id`),
  KEY `forma_pagamento_id` (`forma_pagamento_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `escola_recibos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_recibos_ibfk_2` FOREIGN KEY (`aluno_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_recibos_ibfk_3` FOREIGN KEY (`forma_pagamento_id`) REFERENCES `escola_formas_pagamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escola_recibos_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escola_transacoes_bancarias`;
CREATE TABLE `escola_transacoes_bancarias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `conta_id` int(11) NOT NULL,
  `tipo` enum('credito','debito','transferencia','pagamento','taxa') NOT NULL,
  `valor` decimal(15,2) NOT NULL,
  `descricao` text DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `data_transacao` date NOT NULL,
  `comprovativo` varchar(255) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `status` enum('pendente','confirmado','cancelado') DEFAULT 'confirmado',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `conta_id` (`conta_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `escola_transacoes_bancarias_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_transacoes_bancarias_ibfk_2` FOREIGN KEY (`conta_id`) REFERENCES `escola_contas_bancarias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escola_transacoes_bancarias_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `escolas`;
CREATE TABLE `escolas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano_id` int(11) DEFAULT NULL,
  `nome` varchar(200) NOT NULL,
  `subdominio` varchar(100) NOT NULL,
  `dominio_personalizado` varchar(200) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `provincia` varchar(50) DEFAULT NULL,
  `municipio` varchar(50) DEFAULT NULL,
  `comuna` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `nuit` varchar(20) DEFAULT NULL,
  `ano_fundacao` year(4) DEFAULT NULL,
  `director` varchar(100) DEFAULT NULL COMMENT 'Nome do Diretor da Escola',
  `director_contato` varchar(20) DEFAULT NULL COMMENT 'Contato do Diretor',
  `director_email` varchar(100) DEFAULT NULL COMMENT 'E-mail do Diretor',
  `director_pedagogico` varchar(100) DEFAULT NULL COMMENT 'Nome do Diretor Pedagógico',
  `director_pedagogico_contato` varchar(20) DEFAULT NULL COMMENT 'Contato do Diretor Pedagógico',
  `director_pedagogico_email` varchar(100) DEFAULT NULL COMMENT 'E-mail do Diretor Pedagógico',
  `secretario` varchar(100) DEFAULT NULL COMMENT 'Nome do Secretário',
  `secretario_contato` varchar(20) DEFAULT NULL COMMENT 'Contato do Secretário',
  `secretario_email` varchar(100) DEFAULT NULL COMMENT 'E-mail do Secretário',
  `responsavel_nome` varchar(100) DEFAULT NULL,
  `responsavel_email` varchar(100) DEFAULT NULL,
  `responsavel_telefone` varchar(20) DEFAULT NULL,
  `status` enum('ativa','suspensa','inativa','trial') DEFAULT 'trial',
  `trial_ate` date DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `subdominio` (`subdominio`),
  UNIQUE KEY `dominio_personalizado` (`dominio_personalizado`),
  KEY `plano_id` (`plano_id`),
  KEY `idx_subdominio` (`subdominio`),
  KEY `idx_status` (`status`),
  KEY `idx_provincia` (`provincia`),
  CONSTRAINT `escolas_ibfk_1` FOREIGN KEY (`plano_id`) REFERENCES `planos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `escolas` VALUES('1','1','Colégio João Paulo-Nº 4042','colegio-joaopaulo4042',NULL,'joaopaulo@gmail.com',NULL,NULL,'Rua do Areio-Casa/nº43','Benguela',NULL,NULL,'logo_1775747072_69d7c00095dd4.png','123653876597443','2000','João Franscico Morais Paulo','943911394','joaofranciscompaulo94@gmail.com','Armanda Pombal','943911384','armandapombal2000@gmail.com','Cristina Gama','938672929','karinagama12@mail.com',NULL,NULL,NULL,'ativa','2026-05-09',NULL,'2026-04-09 16:05:43','2026-04-09 21:08:38');
INSERT INTO `escolas` VALUES('2','1','Colégio Pombal-Nº 4324','colegio-pombal4324',NULL,'armandapombal@gmail.com',NULL,NULL,NULL,'Bengo',NULL,NULL,'logo_1775853347_69d95f2385665.jpg',NULL,'2026','João Paulo',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'ativa','2026-05-10',NULL,'2026-04-10 21:37:14','2026-04-11 16:50:45');

DROP TABLE IF EXISTS `estudantes`;
CREATE TABLE `estudantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `escola_id` int(11) NOT NULL,
  `matricula` varchar(50) NOT NULL,
  `nome` varchar(200) DEFAULT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `bi_data_emissao` date DEFAULT NULL,
  `bi_local_emissao` varchar(100) DEFAULT NULL,
  `nuit` varchar(20) DEFAULT NULL,
  `nacionalidade` varchar(50) DEFAULT '''Angolana''',
  `naturalidade` varchar(100) DEFAULT NULL,
  `provincia` varchar(50) DEFAULT NULL,
  `municipio` varchar(50) DEFAULT NULL,
  `comuna` varchar(50) DEFAULT NULL,
  `pai_nome` varchar(100) DEFAULT NULL,
  `pai_bi` varchar(20) DEFAULT NULL,
  `pai_telefone` varchar(20) DEFAULT NULL,
  `pai_profissao` varchar(100) DEFAULT NULL,
  `mae_nome` varchar(100) DEFAULT NULL,
  `mae_bi` varchar(20) DEFAULT NULL,
  `mae_telefone` varchar(20) DEFAULT NULL,
  `mae_profissao` varchar(100) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `genero` enum('M','F') DEFAULT NULL,
  `pais_id` int(11) DEFAULT NULL,
  `pais_nome` varchar(100) DEFAULT NULL,
  `cidade_id` int(11) DEFAULT NULL,
  `cidade_nome` varchar(100) DEFAULT NULL,
  `provincia_id` int(11) DEFAULT NULL,
  `provincia_nome` varchar(100) DEFAULT NULL,
  `municipio_id` int(11) DEFAULT NULL,
  `municipio_nome` varchar(100) DEFAULT NULL,
  `comuna_id` int(11) DEFAULT NULL,
  `comuna_nome` varchar(100) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `email` varchar(20) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `encarregado_nome` varchar(100) DEFAULT NULL,
  `encarregado_parentesco` varchar(20) DEFAULT NULL,
  `encarregado_bi` varchar(20) DEFAULT NULL,
  `encarregado_telefone` varchar(20) DEFAULT NULL,
  `encarregado_email` varchar(100) DEFAULT NULL,
  `encarregado_endereco` text DEFAULT NULL,
  `ano_letivo` varchar(10) NOT NULL DEFAULT '''2026''',
  `ano_escolar` varchar(20) DEFAULT NULL,
  `classe` varchar(20) DEFAULT NULL,
  `curso` varchar(20) DEFAULT NULL,
  `nivel` varchar(20) DEFAULT NULL,
  `numero_processo` varchar(50) DEFAULT NULL,
  `escola_anterior` varchar(200) DEFAULT NULL,
  `ano_ingresso` year(4) DEFAULT NULL,
  `bi_documento` varchar(255) DEFAULT NULL,
  `certificado_documento` varchar(255) DEFAULT NULL,
  `atestado_documento` varchar(255) DEFAULT NULL,
  `outros_documentos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `declaracao_documento` varchar(255) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `status` enum('ativo','inativo','transferido','concluido') NOT NULL DEFAULT 'ativo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `matricula` (`matricula`),
  KEY `idx_matricula` (`matricula`),
  KEY `idx_escola` (`escola_id`),
  KEY `estudantes_ibfk_1` (`usuario_id`),
  CONSTRAINT `estudantes_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estudantes_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `estudantes` VALUES('8','28','2','2026/002/00001','Manuel Pedro Paulo','005922770BO043',NULL,NULL,NULL,'Angolana',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026',NULL,'5º Ano','Administração','Ensino Fundamental I','2026/002/00001',NULL,NULL,'','','','[]','','avatar_1776515752_69e37aa860e8a.png','2026-04-18 13:35:52','2026-04-18 14:32:42','ativo');
INSERT INTO `estudantes` VALUES('9','29','2','2026/002/00002','Maria Paulo','005922770na054',NULL,NULL,NULL,'\'Angolana\'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026','5ª Classe','5ª Classe',NULL,NULL,'2026/002/00002',NULL,NULL,'','','','[]','','avatar_1776902558_69e9619e49dc6.png','2026-04-23 01:02:38','2026-04-23 01:22:35','ativo');
INSERT INTO `estudantes` VALUES('10','30','2','2026/002/00003','Alice Ribeiro','005922770na052',NULL,NULL,NULL,'\'Angolana\'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026','5ª Classe','5ª Classe',NULL,NULL,'2026/002/00003',NULL,NULL,'','','','[]','','avatar_1776902598_69e961c6c6aa5.png','2026-04-23 01:03:18','2026-04-23 01:21:12','ativo');
INSERT INTO `estudantes` VALUES('11','31','2','2026/002/00004','Eulalia Marisa Paulo','005922770na05',NULL,NULL,NULL,'\'Angolana\'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026','5ª Classe','5ª Classe',NULL,NULL,'2026/002/00004',NULL,NULL,'','','','[]','','avatar_1776902646_69e961f61667d.png','2026-04-23 01:04:06','2026-04-23 01:22:02','ativo');
INSERT INTO `estudantes` VALUES('12','32','2','2026/002/00005','Cristina Gama','005922770na057',NULL,NULL,NULL,'\'Angolana\'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026','5ª Classe','5ª Classe',NULL,NULL,'2026/002/00005',NULL,NULL,'','','','[]','','avatar_1776904120_69e967b86f18d.png','2026-04-23 01:28:40',NULL,'ativo');

DROP TABLE IF EXISTS `faq`;
CREATE TABLE `faq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pergunta` varchar(500) NOT NULL,
  `resposta` text NOT NULL,
  `categoria` enum('geral','sistema','notas','matricula','financeiro','tecnico','academico') DEFAULT 'geral',
  `ordem` int(11) DEFAULT 0,
  `destaque` tinyint(4) DEFAULT 0,
  `ativo` tinyint(4) DEFAULT 1,
  `escola_id` int(11) NOT NULL,
  `visualizacoes` int(11) DEFAULT 0,
  `feedback_util` int(11) DEFAULT 0,
  `feedback_nao_util` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_ordem` (`ordem`),
  KEY `idx_ativo` (`ativo`),
  CONSTRAINT `faq_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `faq` VALUES('1','Como faço para lançar notas dos alunos?','Para lançar notas, acesse o menu \"Notas\" no painel principal. Selecione a turma, disciplina e trimestre desejado. Em seguida, preencha as notas MAC e NPT para cada aluno. O sistema calcula automaticamente a média final. Clique em \"Salvar Notas\" para finalizar.','notas','1','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('2','Como posso visualizar o boletim de um aluno?','Para visualizar o boletim de um aluno, acesse o menu \"Relatórios\" > \"Boletins\". Selecione a turma e o aluno desejado. O sistema exibirá o boletim completo com todas as notas e médias finais.','academico','2','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('3','Como solicitar um vale ou adiantamento salarial?','Acesse seu perfil clicando no seu nome no canto superior direito. Vá até a aba \"Solicitar Vale\". Preencha o valor desejado, número de parcelas e o motivo. O sistema enviará sua solicitação para aprovação da administração.','financeiro','3','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('4','Como alterar minha senha de acesso?','Para alterar sua senha, acesse seu perfil clicando no seu nome no canto superior direito. Vá até a aba \"Segurança\" e preencha os campos: senha atual, nova senha e confirmação. Clique em \"Alterar Senha\" para salvar.','sistema','4','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('5','O sistema não está carregando corretamente. O que fazer?','Tente as seguintes soluções: 1) Limpe o cache do navegador; 2) Atualize a página (F5); 3) Use um navegador atualizado (Chrome, Firefox, Edge); 4) Verifique sua conexão com a internet. Se o problema persistir, abra um chamado de suporte técnico.','tecnico','5','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('6','Como visualizar meu histórico de salários?','Acesse seu perfil e vá até a aba \"Salários\". Lá você encontrará duas seções: \"Salários a Receber\" (proventos pendentes) e \"Histórico de Salários Recebidos\" (últimos 12 meses).','financeiro','6','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('7','Como solicitar férias?','Acesse seu perfil e vá até a aba \"Solicitar Férias\". Selecione a data de início e fim do período desejado. O sistema calcula automaticamente os dias solicitados. Adicione observações se necessário e clique em \"Solicitar Férias\".','','7','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');
INSERT INTO `faq` VALUES('8','Como funciona o sistema de desconto em folha para dívidas?','Dívidas com desconto em folha ativado são automaticamente descontadas do seu salário mensal. Você pode visualizar suas dívidas ativas na aba \"Dívidas\" do seu perfil. O desconto ocorre após o processamento da folha de pagamento.','financeiro','8','0','1','1','0','0','0','2026-05-06 23:00:49','2026-05-06 23:00:49');

DROP TABLE IF EXISTS `faq_feedback`;
CREATE TABLE `faq_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faq_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo` enum('util','nao_util') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_feedback` (`faq_id`,`usuario_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `faq_feedback_ibfk_1` FOREIGN KEY (`faq_id`) REFERENCES `faq` (`id`) ON DELETE CASCADE,
  CONSTRAINT `faq_feedback_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `favoritos_materiais`;
CREATE TABLE `favoritos_materiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `material_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_material_funcionario` (`material_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `favoritos_materiais_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materiais_didaticos` (`id`),
  CONSTRAINT `favoritos_materiais_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `favoritos_materiais` VALUES('1','31','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('2','32','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('3','36','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('4','41','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('5','45','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('6','51','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('7','58','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('8','66','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('9','2','1','2026-04-29 23:16:38');
INSERT INTO `favoritos_materiais` VALUES('10','5','1','2026-04-29 23:16:38');

DROP TABLE IF EXISTS `feriados`;
CREATE TABLE `feriados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `data` date NOT NULL,
  `tipo` enum('nacional','provincial','municipal') DEFAULT 'nacional',
  `descricao` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_data` (`data`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `feriados` VALUES('1','Ano Novo','2026-01-01','nacional','Confraternização Universal','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('2','Dia da Liberdade','2026-01-04','nacional','Fim do regime colonial','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('3','Dia dos Mártires','2026-02-04','nacional','Início da luta armada','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('4','Dia da Mulher Angolana','2026-03-02','nacional','Homenagem à mulher angolana','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('5','Dia da Paz','2026-04-04','nacional','Assinatura dos Acordos de Paz','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('6','Dia do Trabalhador','2026-05-01','nacional','Dia Internacional do Trabalhador','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('7','Dia do Herói Nacional','2026-09-17','nacional','Aniversário de Agostinho Neto','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('8','Dia das Forças Armadas','2026-10-01','nacional','Dia das FAA','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('9','Dia do Herói Nacional','2026-11-11','nacional','Independência Nacional','2026-04-09 14:11:53');
INSERT INTO `feriados` VALUES('10','Natal','2026-12-25','nacional','Natal','2026-04-09 14:11:53');

DROP TABLE IF EXISTS `ferias`;
CREATE TABLE `ferias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `ano_referencia` int(11) NOT NULL,
  `dias_direito` int(11) DEFAULT 22,
  `dias_gozados` int(11) DEFAULT 0,
  `dias_restantes` int(11) DEFAULT 22,
  `periodo_aquisitivo_inicio` date DEFAULT NULL,
  `periodo_aquisitivo_fim` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ferias` (`funcionario_id`,`ano_referencia`),
  CONSTRAINT `ferias_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `ferias_funcionario`;
CREATE TABLE `ferias_funcionario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `ano_referencia` int(11) NOT NULL,
  `dias_totais` int(11) DEFAULT 30,
  `dias_utilizados` int(11) DEFAULT 0,
  `dias_disponiveis` int(11) DEFAULT 30,
  `dias_pendentes` int(11) DEFAULT 0,
  `ultima_atualizacao` date DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_funcionario_ano` (`funcionario_id`,`ano_referencia`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_ano` (`ano_referencia`),
  CONSTRAINT `ferias_funcionario_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `ferias_funcionario` VALUES('1','2','2026','30','0','30','0',NULL,NULL,'2026-04-27 22:48:03','2026-04-27 22:48:03');

DROP TABLE IF EXISTS `ferias_historico`;
CREATE TABLE `ferias_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `solicitacao_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_solicitacao` (`solicitacao_id`),
  KEY `idx_acao` (`acao`),
  CONSTRAINT `ferias_historico_ibfk_1` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes_ferias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `folha_faltas`;
CREATE TABLE `folha_faltas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `data_falta` date NOT NULL,
  `tipo` enum('justificada','injustificada','atestado') DEFAULT 'injustificada',
  `quantidade_dias` decimal(4,2) DEFAULT 1.00,
  `desconto` decimal(10,2) DEFAULT 0.00,
  `justificativa` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tipo_falta` enum('justificada','injustificada','atestado','licenca') DEFAULT 'injustificada',
  `valor_desconto_dia` decimal(10,2) DEFAULT 0.00,
  `percentual_desconto` decimal(5,2) DEFAULT 100.00,
  `aprovado_por` int(11) DEFAULT NULL,
  `data_aprovacao` date DEFAULT NULL,
  `valor_desconto` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor total descontado',
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_folha_faltas_funcionario` (`funcionario_id`),
  KEY `idx_folha_faltas_data` (`data_falta`),
  CONSTRAINT `folha_faltas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_faltas_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_faltas` VALUES('5','2','1','2026-04-17','injustificada','1.00','0.00','','2026-04-17 06:53:22','injustificada','0.00','100.00',NULL,NULL,'454.55');

DROP TABLE IF EXISTS `folha_funcionario_rubricas`;
CREATE TABLE `folha_funcionario_rubricas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `rubrica_id` int(11) NOT NULL,
  `valor_fixo` decimal(10,2) DEFAULT 0.00,
  `percentual` decimal(5,2) DEFAULT 0.00,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `rubrica_id` (`rubrica_id`),
  CONSTRAINT `folha_funcionario_rubricas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_funcionario_rubricas_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_funcionario_rubricas_ibfk_3` FOREIGN KEY (`rubrica_id`) REFERENCES `folha_rubricas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_funcionarios`;
CREATE TABLE `folha_funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `salario_base` decimal(10,2) DEFAULT 0.00,
  `subsidio_transporte` decimal(10,2) DEFAULT 0.00,
  `subsidio_alimentacao` decimal(10,2) DEFAULT 0.00,
  `outros_vencimentos` decimal(10,2) DEFAULT 0.00,
  `desconto_inss` decimal(10,2) DEFAULT 0.00,
  `desconto_irrf` decimal(10,2) DEFAULT 0.00,
  `outros_descontos` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_funcionario` (`escola_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `folha_funcionarios_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_funcionarios_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_funcionarios` VALUES('2','2','1','0.00','0.00','0.00','0.00','0.00','0.00','0.00','2026-04-16 14:47:57','2026-04-16 14:47:57');

DROP TABLE IF EXISTS `folha_funcionarios_config`;
CREATE TABLE `folha_funcionarios_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `salario_base` decimal(10,2) DEFAULT 0.00,
  `tipo_salario` enum('mensal','hora','comissao') DEFAULT 'mensal',
  `carga_horaria_semanal` int(11) DEFAULT 40,
  `valor_hora` decimal(10,2) DEFAULT 0.00,
  `data_ultimo_processamento` date DEFAULT NULL,
  `forma_pagamento` enum('transferencia','numerario','cheque') DEFAULT 'transferencia',
  `banco_id` int(11) DEFAULT NULL,
  `numero_conta` varchar(50) DEFAULT NULL,
  `iban` varchar(50) DEFAULT NULL,
  `status` enum('ativo','inativo','ferias','licenca') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_funcionario` (`escola_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `folha_funcionarios_config_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_funcionarios_config_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_holerites`;
CREATE TABLE `folha_holerites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `processamento_id` int(11) NOT NULL,
  `ano` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `salario_base` decimal(10,2) DEFAULT 0.00,
  `total_vencimentos` decimal(10,2) DEFAULT 0.00,
  `total_descontos` decimal(10,2) DEFAULT 0.00,
  `salario_liquido` decimal(10,2) DEFAULT 0.00,
  `data_emissao` timestamp NOT NULL DEFAULT current_timestamp(),
  `codigo_verificacao` varchar(100) DEFAULT NULL,
  `pdf_gerado` enum('sim','nao') DEFAULT 'nao',
  `caminho_pdf` varchar(500) DEFAULT NULL,
  `status` enum('gerado','pago','cancelado') DEFAULT 'gerado',
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `processamento_id` (`processamento_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_periodo` (`ano`,`mes`),
  CONSTRAINT `folha_holerites_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_holerites_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_holerites_ibfk_3` FOREIGN KEY (`processamento_id`) REFERENCES `folha_processamento_cabecalho` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_holerites` VALUES('1','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:14:00','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('2','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:14:01','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('3','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:23:11','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('4','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:23:12','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('5','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:23:44','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('6','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:23:45','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('7','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:23:59','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('8','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:24:00','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('9','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:30:33','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('10','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:30:34','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('11','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:35:05','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('12','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:35:06','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('13','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:35:52','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('14','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:35:52','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('15','2','1','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:39:45','5f93f983524def3dca464469d2cf9f3e','nao','uploads/holerites/holerite_FUNC/2/2026/0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('16','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-16 23:39:46','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC/2/2026/0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('17','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:47:48','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('18','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:47:49','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('19','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:55:35','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('20','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:55:35','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('21','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:58:50','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('22','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:58:50','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('23','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:58:54','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('24','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:58:54','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('25','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:58:57','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('26','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:58:57','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('27','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:58:59','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('28','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:58:59','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('29','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:59:01','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('30','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:59:01','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('31','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:59:03','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('32','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:59:04','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('33','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:59:06','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('34','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:59:06','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('35','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-16 23:59:35','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('36','2','2','1','2026','4','0.00','0.00','3000.00','-3000.00','2026-04-16 23:59:35','1427940fbf4c506279958f743e14b334','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('37','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-17 00:10:55','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('38','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-17 00:13:01','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('39','2','1','1','2026','4','10000.00','10000.00','6754.55','3245.45','2026-04-17 00:13:23','251ce98006d5a0157fa39e9d0ca7744d','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('40','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-17 00:13:24','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('41','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 06:57:14','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('42','2','2','1','2026','4','0.00','0.00','0.00','0.00','2026-04-17 06:57:15','6f3ef77ac0e3619e98159e9b6febf557','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('43','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 07:00:43','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('44','2','2','1','2026','4','20000.00','35000.00','600.00','34400.00','2026-04-17 07:00:44','2e6312d1a10c4863ad96a6d73a2b2ed7','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('45','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 21:13:08','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('46','2','2','1','2026','4','20000.00','35000.00','600.00','34400.00','2026-04-17 21:13:08','2e6312d1a10c4863ad96a6d73a2b2ed7','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('47','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 21:48:51','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('48','2','2','1','2026','4','20000.00','35000.00','600.00','34400.00','2026-04-17 21:48:51','2e6312d1a10c4863ad96a6d73a2b2ed7','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('49','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 21:55:21','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('50','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:14:57','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('51','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:14:58','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('52','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:14:59','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('53','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:14:59','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('54','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:15:00','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('55','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:15:01','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('56','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:15:01','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('57','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:15:02','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('58','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:15:02','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('59','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:15:03','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('60','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:15:03','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('61','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:15:04','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('62','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:21:47','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('63','2','2','1','2026','4','10000.00','22000.00','300.00','21700.00','2026-04-17 22:21:48','eb6400053a7a653441a17ab7f193f254','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('64','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-17 22:58:54','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('65','2','2','1','2026','4','100000.00','112000.00','13900.00','98100.00','2026-04-17 22:58:55','0b6a4fb03b6317ed1cb421d262625676','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('66','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-18 20:24:13','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('67','2','2','1','2026','4','100000.00','112000.00','3900.00','108100.00','2026-04-18 20:24:14','b67e96c66e9d894785e0b5fe63b815e8','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('68','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-18 20:42:57','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('69','2','2','1','2026','4','100000.00','112000.00','3900.00','108100.00','2026-04-18 20:42:58','b67e96c66e9d894785e0b5fe63b815e8','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('70','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-21 20:28:58','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('71','2','2','1','2026','4','100000.00','112000.00','3900.00','108100.00','2026-04-21 20:28:59','b67e96c66e9d894785e0b5fe63b815e8','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('72','2','1','1','2026','4','10000.00','10000.00','754.55','9245.45','2026-04-23 13:51:10','22cc17a7223fa2c3ae89279a771834e1','nao','uploads/holerites/holerite_FUNC_2_2026_0001_2026_04.pdf','gerado');
INSERT INTO `folha_holerites` VALUES('73','2','2','1','2026','4','100000.00','112000.00','3900.00','108100.00','2026-04-23 13:51:11','b67e96c66e9d894785e0b5fe63b815e8','nao','uploads/holerites/holerite_FUNC_2_2026_0002_2026_04.pdf','gerado');

DROP TABLE IF EXISTS `folha_horas_extras`;
CREATE TABLE `folha_horas_extras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `data_hora` date NOT NULL,
  `quantidade` decimal(5,2) NOT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `aprovado_por` int(11) DEFAULT NULL,
  `status` enum('pendente','aprovado','rejeitado') DEFAULT 'pendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `aprovado_por` (`aprovado_por`),
  CONSTRAINT `folha_horas_extras_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_horas_extras_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_horas_extras_ibfk_3` FOREIGN KEY (`aprovado_por`) REFERENCES `funcionarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_logs`;
CREATE TABLE `folha_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `processamento_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data_log` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `processamento_id` (`processamento_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `folha_logs_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_logs_ibfk_2` FOREIGN KEY (`processamento_id`) REFERENCES `folha_processamento_cabecalho` (`id`) ON DELETE SET NULL,
  CONSTRAINT `folha_logs_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_logs` VALUES('1','2','1','3','processamento','Processamento da folha realizado para /','::1','2026-04-16 15:25:19');

DROP TABLE IF EXISTS `folha_parametros`;
CREATE TABLE `folha_parametros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `parametro` varchar(100) NOT NULL,
  `valor` text DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_parametro` (`escola_id`,`parametro`),
  CONSTRAINT `folha_parametros_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_parametros` VALUES('1','1','DIA_PAGAMENTO','5','Dia do mês para pagamento','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('2','2','DIA_PAGAMENTO','5','Dia do mês para pagamento','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('4','1','SALARIO_MINIMO','100000','Salário mínimo nacional','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('5','2','SALARIO_MINIMO','100000','Salário mínimo nacional','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('7','1','FERIAS_PROPORCIONAIS','sim','Calcular férias proporcionais','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('8','2','FERIAS_PROPORCIONAIS','sim','Calcular férias proporcionais','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('10','1','DECIMO_TERCEIRO','sim','Calcular 13º salário','2026-04-16 15:11:18');
INSERT INTO `folha_parametros` VALUES('11','2','DECIMO_TERCEIRO','sim','Calcular 13º salário','2026-04-16 15:11:18');

DROP TABLE IF EXISTS `folha_parametros_falta`;
CREATE TABLE `folha_parametros_falta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `valor_desconto_dia` decimal(10,2) DEFAULT 0.00,
  `percentual_desconto` decimal(5,2) DEFAULT 0.00,
  `tipo_calculo` enum('valor_fixo','percentual_salario') DEFAULT 'percentual_salario',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `folha_parametros_falta_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_parametros_falta` VALUES('1','1','0.00','100.00','percentual_salario','2026-04-16 16:50:54');
INSERT INTO `folha_parametros_falta` VALUES('2','2','0.00','100.00','percentual_salario','2026-04-16 16:50:54');
INSERT INTO `folha_parametros_falta` VALUES('4','1','0.00','100.00','percentual_salario','2026-04-16 21:51:47');
INSERT INTO `folha_parametros_falta` VALUES('5','2','0.00','100.00','percentual_salario','2026-04-16 21:51:47');

DROP TABLE IF EXISTS `folha_processamento`;
CREATE TABLE `folha_processamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `ano` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `data_processamento` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL,
  `total_funcionarios` int(11) DEFAULT 0,
  `total_vencimentos` decimal(12,2) DEFAULT 0.00,
  `total_descontos` decimal(12,2) DEFAULT 0.00,
  `total_liquido` decimal(12,2) DEFAULT 0.00,
  `status` enum('processado','cancelado','rascunho') DEFAULT 'processado',
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_ano_mes` (`ano`,`mes`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `folha_processamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_processamento_cabecalho`;
CREATE TABLE `folha_processamento_cabecalho` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `ano` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `data_processamento` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL,
  `status` enum('rascunho','processado','fechado','cancelado') DEFAULT 'rascunho',
  `total_funcionarios` int(11) DEFAULT 0,
  `total_vencimentos` decimal(12,2) DEFAULT 0.00,
  `total_descontos` decimal(12,2) DEFAULT 0.00,
  `total_liquido` decimal(12,2) DEFAULT 0.00,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_periodo` (`escola_id`,`ano`,`mes`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data` (`data_processamento`),
  CONSTRAINT `folha_processamento_cabecalho_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_cabecalho_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_processamento_cabecalho` VALUES('1','2','2026','4','2026-04-16 16:06:52','3','fechado','2','111545.45','13754.55','97790.90',NULL);
INSERT INTO `folha_processamento_cabecalho` VALUES('2','2','2026','3','2026-04-16 22:14:48','3','rascunho','0','0.00','0.00','0.00',NULL);
INSERT INTO `folha_processamento_cabecalho` VALUES('3','2','2026','5','2026-04-17 21:53:10','3','rascunho','0','0.00','0.00','0.00',NULL);
INSERT INTO `folha_processamento_cabecalho` VALUES('4','2','2027','5','2026-04-17 21:53:16','3','rascunho','0','0.00','0.00','0.00',NULL);
INSERT INTO `folha_processamento_cabecalho` VALUES('5','2','2027','4','2026-04-17 21:53:20','3','rascunho','0','0.00','0.00','0.00',NULL);

DROP TABLE IF EXISTS `folha_processamento_funcionarios`;
CREATE TABLE `folha_processamento_funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processamento_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `mes_competencia` int(11) DEFAULT NULL COMMENT 'Mês de competência (1-12)',
  `ano_competencia` int(11) DEFAULT NULL COMMENT 'Ano de competência',
  `data_processamento` date DEFAULT NULL COMMENT 'Data do processamento',
  `escola_id` int(11) DEFAULT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) DEFAULT NULL COMMENT 'ID do ano letivo',
  `salario_base` decimal(10,2) DEFAULT 0.00,
  `subsidio_transporte` decimal(10,2) DEFAULT 0.00,
  `subsidio_alimentacao` decimal(10,2) DEFAULT 0.00,
  `gratificacao` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Gratificação adicional',
  `seguro_saude` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Seguro saúde',
  `abono_familiar` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Abono familiar',
  `outros_vencimentos` decimal(10,2) DEFAULT 0.00,
  `total_vencimentos` decimal(10,2) DEFAULT 0.00,
  `faltas_dias` int(11) DEFAULT 0,
  `faltas_valor` decimal(10,2) DEFAULT 0.00,
  `desconto_irps` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto IRPS',
  `desconto_atrasos` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto por atrasos',
  `desconto_emprestimo` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto de empréstimo',
  `desconto_seguranca_social` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto da segurança social',
  `horas_extras` int(11) DEFAULT 0,
  `horas_extras_valor` decimal(10,2) DEFAULT 0.00,
  `base_inss` decimal(10,2) DEFAULT 0.00,
  `valor_inss` decimal(10,2) DEFAULT 0.00,
  `base_irrf` decimal(10,2) DEFAULT 0.00,
  `valor_irrf` decimal(10,2) DEFAULT 0.00,
  `outros_descontos` decimal(10,2) DEFAULT 0.00,
  `total_descontos` decimal(10,2) DEFAULT 0.00,
  `salario_liquido` decimal(10,2) DEFAULT 0.00,
  `observacoes` text DEFAULT NULL,
  `rubrica_tipo` enum('vencimento','desconto','base') DEFAULT 'vencimento',
  `status` enum('pendente','processado','aprovado','pago','cancelado') DEFAULT 'pendente' COMMENT 'Status do processamento',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_funcionario` (`processamento_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `folha_processamento_funcionarios_ibfk_1` FOREIGN KEY (`processamento_id`) REFERENCES `folha_processamento_cabecalho` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_funcionarios_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_processamento_funcionarios` VALUES('3','1','1','4','2026','2026-04-27',NULL,NULL,'10000.00','0.00','0.00','0.00','0.00','0.00','0.00','9545.45','1','454.55','0.00','0.00','0.00','0.00','0','0.00','0.00','300.00','0.00','0.00','0.00','754.55','8790.90',NULL,'vencimento','processado','2026-04-27 16:56:33','2026-04-27 17:05:07');
INSERT INTO `folha_processamento_funcionarios` VALUES('9','1','2','4','2026','2026-04-27',NULL,NULL,'100000.00','10000.00','2000.00','0.00','0.00','0.00','0.00','112000.00','0','0.00','0.00','0.00','0.00','0.00','0','0.00','0.00','3000.00','0.00','900.00','0.00','3900.00','108100.00',NULL,'vencimento','processado','2026-04-27 16:56:33','2026-04-27 17:05:07');

DROP TABLE IF EXISTS `folha_processamento_linhas`;
CREATE TABLE `folha_processamento_linhas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processamento_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `rubrica_id` int(11) NOT NULL,
  `codigo_rubrica` varchar(20) DEFAULT NULL,
  `nome_rubrica` varchar(100) DEFAULT NULL,
  `tipo_rubrica` enum('vencimento','desconto','base_calculo') DEFAULT NULL,
  `quantidade` decimal(10,2) DEFAULT 1.00,
  `valor_unitario` decimal(10,2) DEFAULT 0.00,
  `valor_total` decimal(10,2) DEFAULT 0.00,
  `formula_utilizada` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `processamento_id` (`processamento_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_rubrica` (`rubrica_id`),
  CONSTRAINT `folha_processamento_linhas_ibfk_1` FOREIGN KEY (`processamento_id`) REFERENCES `folha_processamento_cabecalho` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_linhas_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_linhas_ibfk_3` FOREIGN KEY (`rubrica_id`) REFERENCES `folha_rubricas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_processamento_resumo`;
CREATE TABLE `folha_processamento_resumo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processamento_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `total_vencimentos` decimal(10,2) DEFAULT 0.00,
  `total_descontos` decimal(10,2) DEFAULT 0.00,
  `base_inss` decimal(10,2) DEFAULT 0.00,
  `valor_inss` decimal(10,2) DEFAULT 0.00,
  `base_irrf` decimal(10,2) DEFAULT 0.00,
  `valor_irrf` decimal(10,2) DEFAULT 0.00,
  `salario_liquido` decimal(10,2) DEFAULT 0.00,
  `holerite_gerado` enum('sim','nao') DEFAULT 'nao',
  `data_holerite` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_processamento_funcionario` (`processamento_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `folha_processamento_resumo_ibfk_1` FOREIGN KEY (`processamento_id`) REFERENCES `folha_processamento_cabecalho` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folha_processamento_resumo_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `folha_rubricas`;
CREATE TABLE `folha_rubricas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('vencimento','desconto','base_calculo') NOT NULL,
  `natureza` enum('base','subsidio','bonus','desconto_legal','desconto_pessoal','outro') DEFAULT 'base',
  `formula_calculo` text DEFAULT NULL,
  `percentual_aplicado` decimal(5,2) DEFAULT 0.00,
  `valor_fixo` decimal(10,2) DEFAULT 0.00,
  `ordem` int(11) DEFAULT 0,
  `incide_inss` enum('sim','nao') DEFAULT 'sim',
  `incide_irrf` enum('sim','nao') DEFAULT 'sim',
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_codigo` (`escola_id`,`codigo`),
  CONSTRAINT `folha_rubricas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `folha_rubricas` VALUES('1','1','BASE','Salário Base','vencimento','base',NULL,'0.00','0.00','1','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('2','2','BASE','Salário Base','vencimento','base',NULL,'0.00','0.00','1','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('4','1','SUB_TRANSP','Subsídio de Transporte','vencimento','subsidio',NULL,'0.00','0.00','2','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('5','2','SUB_TRANSP','Subsídio de Transporte','vencimento','subsidio',NULL,'0.00','0.00','2','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('7','1','SUB_ALIMENT','Subsídio de Alimentação','vencimento','subsidio',NULL,'0.00','0.00','3','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('8','2','SUB_ALIMENT','Subsídio de Alimentação','vencimento','subsidio',NULL,'0.00','0.00','3','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('10','1','BONUS','Bónus / Prémio','vencimento','bonus',NULL,'0.00','0.00','4','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('11','2','BONUS','Bónus / Prémio','vencimento','bonus',NULL,'0.00','0.00','4','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('13','1','HORA_EXTRA','Horas Extras','vencimento','bonus',NULL,'0.00','0.00','5','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('14','2','HORA_EXTRA','Horas Extras','vencimento','bonus',NULL,'0.00','0.00','5','sim','sim','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('16','1','INSS','INSS - Segurança Social','desconto','desconto_legal',NULL,'0.00','0.00','1','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('17','2','INSS','INSS - Segurança Social','desconto','desconto_legal',NULL,'0.00','0.00','1','nao','nao','ativo','2026-04-16 15:11:17');
INSERT INTO `folha_rubricas` VALUES('19','1','IRRF','IRRF - Imposto de Renda','desconto','desconto_legal',NULL,'0.00','0.00','2','nao','nao','ativo','2026-04-16 15:11:18');
INSERT INTO `folha_rubricas` VALUES('20','2','IRRF','IRRF - Imposto de Renda','desconto','desconto_legal',NULL,'0.00','0.00','2','nao','nao','ativo','2026-04-16 15:11:18');
INSERT INTO `folha_rubricas` VALUES('22','1','DESC_PESSOAL','Desconto Pessoal','desconto','desconto_pessoal',NULL,'0.00','0.00','3','nao','nao','ativo','2026-04-16 15:11:18');
INSERT INTO `folha_rubricas` VALUES('23','2','DESC_PESSOAL','Desconto Pessoal','desconto','desconto_pessoal',NULL,'0.00','0.00','3','nao','nao','ativo','2026-04-16 15:11:18');
INSERT INTO `folha_rubricas` VALUES('25','1','ADTO','Adiantamento','desconto','desconto_pessoal',NULL,'0.00','0.00','4','nao','nao','ativo','2026-04-16 15:11:18');
INSERT INTO `folha_rubricas` VALUES('26','2','ADTO','Adiantamento','desconto','desconto_pessoal',NULL,'0.00','0.00','4','nao','nao','ativo','2026-04-16 15:11:18');

DROP TABLE IF EXISTS `folha_tabelas_impostos`;
CREATE TABLE `folha_tabelas_impostos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `tipo` enum('inss','irrf') NOT NULL,
  `ano` int(11) NOT NULL,
  `faixa_inicio` decimal(10,2) NOT NULL,
  `faixa_fim` decimal(10,2) NOT NULL,
  `aliquota` decimal(5,2) NOT NULL,
  `parcela_deducao` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_tipo_ano` (`tipo`,`ano`),
  CONSTRAINT `folha_tabelas_impostos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `formas_pagamento`;
CREATE TABLE `formas_pagamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `taxa_juros` decimal(5,2) DEFAULT 0.00,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `formas_pagamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `frequencia_mensal`;
CREATE TABLE `frequencia_mensal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `mes` tinyint(2) NOT NULL COMMENT 'Mês (1-12)',
  `ano` year(4) NOT NULL COMMENT 'Ano',
  `total_aulas` int(11) DEFAULT 0 COMMENT 'Total de aulas no período',
  `total_presencas` int(11) DEFAULT 0 COMMENT 'Total de presenças',
  `total_faltas` int(11) DEFAULT 0 COMMENT 'Total de faltas',
  `total_faltas_justificadas` int(11) DEFAULT 0 COMMENT 'Total de faltas justificadas',
  `total_atrasos` int(11) DEFAULT 0 COMMENT 'Total de atrasos',
  `percentual_frequencia` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentual de frequência',
  `status` enum('regular','baixa_frequencia','reprovado_frequencia') DEFAULT 'regular' COMMENT 'Status baseado na frequência',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frequencia_unica` (`estudante_id`,`disciplina_id`,`mes`,`ano`),
  KEY `idx_frequencia_aluno` (`estudante_id`),
  KEY `idx_frequencia_turma` (`turma_id`),
  KEY `idx_frequencia_disciplina` (`disciplina_id`),
  KEY `idx_frequencia_mes_ano` (`mes`,`ano`),
  KEY `idx_frequencia_escola` (`escola_id`),
  KEY `idx_frequencia_ano_letivo` (`ano_letivo_id`),
  CONSTRAINT `fk_frequencia_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumo mensal de frequência dos estudantes';


DROP TABLE IF EXISTS `funcionarios`;
CREATE TABLE `funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `escola_id` int(11) NOT NULL,
  `numero_processo` varchar(50) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `tipo_funcionario` enum('professor','administrativo','auxiliar','seguranca','limpeza','manutencao','motorista','outro') DEFAULT 'professor',
  `cargo` varchar(100) DEFAULT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `bi_emissao` date DEFAULT NULL,
  `bi_validade` date DEFAULT NULL,
  `nuit` varchar(20) DEFAULT NULL,
  `nacionalidade` varchar(50) DEFAULT 'Angolana',
  `naturalidade` varchar(100) DEFAULT NULL,
  `provincia_nome` varchar(100) DEFAULT NULL,
  `municipio_nome` varchar(100) DEFAULT NULL,
  `comuna_nome` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `comuna` varchar(100) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `genero` enum('M','F') DEFAULT NULL,
  `estado_civil` varchar(30) DEFAULT NULL,
  `dependentes` int(11) DEFAULT 0 COMMENT 'Número de dependentes',
  `nome_conjuge` varchar(200) DEFAULT NULL COMMENT 'Nome do cônjuge',
  `contato_emergencia` varchar(50) DEFAULT NULL COMMENT 'Telefone de emergência',
  `nome_pai` varchar(150) DEFAULT NULL,
  `nome_mae` varchar(150) DEFAULT NULL,
  `nivel_escolaridade` varchar(50) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `telefone_emergencia` varchar(20) DEFAULT NULL,
  `nome_emergencia` varchar(150) DEFAULT NULL,
  `parentesco_emergencia` varchar(50) DEFAULT NULL COMMENT 'Parentesco do contato de emergência',
  `numero_bi` varchar(20) DEFAULT NULL COMMENT 'Número do BI',
  `data_emissao_bi` date DEFAULT NULL COMMENT 'Data de emissão do BI',
  `data_validade_bi` date DEFAULT NULL COMMENT 'Data de validade do BI',
  `nif` varchar(20) DEFAULT NULL COMMENT 'Número de Identificação Fiscal',
  `cartao_eleitor` varchar(20) DEFAULT NULL COMMENT 'Número do cartão de eleitor',
  `formacao_academica` varchar(200) DEFAULT NULL COMMENT 'Formação acadêmica',
  `instituicao_ensino` varchar(200) DEFAULT NULL COMMENT 'Instituição de ensino',
  `ano_conclusao` int(11) DEFAULT NULL COMMENT 'Ano de conclusão',
  `agencia_bancaria` varchar(20) DEFAULT NULL COMMENT 'Agência bancária',
  `operacao_conta` varchar(10) DEFAULT NULL COMMENT 'Operação da conta',
  `favorecido` varchar(200) DEFAULT NULL COMMENT 'Nome do favorecido (titular da conta)',
  `ultima_alteracao_senha` datetime DEFAULT NULL COMMENT 'Última alteração de senha',
  `email` varchar(100) DEFAULT NULL,
  `data_admissao` date DEFAULT NULL,
  `tipo_contrato` varchar(50) DEFAULT NULL,
  `data_fim_contrato` date DEFAULT NULL,
  `habilitacao` varchar(100) DEFAULT NULL,
  `formacao` text DEFAULT NULL,
  `formacao_descricao` text DEFAULT NULL,
  `experiencia_anos` decimal(5,2) DEFAULT 0.00,
  `banco_id` int(11) DEFAULT NULL,
  `banco_nome` varchar(100) DEFAULT NULL,
  `banco_codigo` varchar(20) DEFAULT NULL,
  `numero_conta` varchar(50) DEFAULT NULL,
  `digito_conta` varchar(5) DEFAULT NULL COMMENT 'Dígito verificador da conta',
  `numero_agencia` varchar(20) DEFAULT NULL COMMENT 'Número da agência',
  `digito_agencia` varchar(5) DEFAULT NULL COMMENT 'Dígito da agência',
  `conta_bancaria` varchar(100) DEFAULT NULL COMMENT 'Conta bancária completa (formatada)',
  `pix_key` varchar(100) DEFAULT NULL COMMENT 'Chave PIX (CPF/Email/Telefone/Aleatória)',
  `pix_tipo` enum('cpf','email','telefone','aleatoria') DEFAULT NULL COMMENT 'Tipo da chave PIX',
  `banco` varchar(50) DEFAULT NULL,
  `tipo_conta` enum('corrente','poupanca','salario') DEFAULT 'corrente' COMMENT 'Tipo da conta',
  `iban` varchar(50) DEFAULT NULL,
  `swift_code` varchar(20) DEFAULT NULL COMMENT 'Código SWIFT/BIC',
  `conta_principal` tinyint(1) DEFAULT 1 COMMENT '1=Principal, 0=Secundária',
  `swift` varchar(20) DEFAULT NULL,
  `num_seguranca_social` varchar(50) DEFAULT NULL,
  `carteira_profissional` varchar(50) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `status` enum('ativo','inativo','ferias','licenca') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `salario_base` decimal(10,2) DEFAULT 0.00 COMMENT 'Salário base do funcionário',
  `subsidio_transporte` decimal(10,2) DEFAULT 0.00 COMMENT 'Subsídio de transporte',
  `subsidio_alimentacao` decimal(10,2) DEFAULT 0.00 COMMENT 'Subsídio de alimentação',
  `outros_vencimentos` decimal(10,2) DEFAULT 0.00 COMMENT 'Outros vencimentos',
  `valor_inss` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor do INSS calculado',
  `valor_irrf` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor do IRRF calculado',
  `salario_liquido` decimal(10,2) DEFAULT 0.00 COMMENT 'Salário líquido após descontos',
  `total_vencimentos` decimal(10,2) DEFAULT 0.00 COMMENT 'Total de vencimentos',
  `total_descontos` decimal(10,2) DEFAULT 0.00 COMMENT 'Total de descontos',
  `faltas_dias` int(11) DEFAULT 0 COMMENT 'Total de dias de falta no período',
  `faltas_valor` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor descontado por faltas',
  `horas_extras` decimal(5,2) DEFAULT 0.00 COMMENT 'Horas extras trabalhadas',
  `horas_extras_valor` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor das horas extras',
  `ferias_vencidas` int(11) DEFAULT 0,
  `ultimas_ferias_inicio` date DEFAULT NULL,
  `ultimas_ferias_fim` date DEFAULT NULL,
  `carga_horaria_semanal` int(11) DEFAULT 40,
  `regime_trabalho` varchar(50) DEFAULT 'Período Integral',
  `numero_funcionario` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_processo` (`numero_processo`),
  UNIQUE KEY `bi` (`bi`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_bi` (`bi`),
  KEY `idx_numero_processo` (`numero_processo`),
  KEY `idx_tipo_funcionario` (`tipo_funcionario`),
  KEY `idx_status` (`status`),
  KEY `idx_num_seguranca_social` (`num_seguranca_social`),
  KEY `idx_carteira_profissional` (`carteira_profissional`),
  KEY `idx_funcionarios_bi` (`bi`),
  KEY `idx_funcionarios_numero_processo` (`numero_processo`),
  KEY `idx_funcionarios_tipo_funcionario` (`tipo_funcionario`),
  KEY `idx_funcionarios_status` (`status`),
  KEY `idx_funcionarios_telefone` (`telefone`),
  KEY `idx_funcionarios_email` (`email`),
  KEY `idx_funcionarios_escola_id` (`escola_id`),
  KEY `idx_funcionarios_data_nascimento` (`data_nascimento`),
  KEY `idx_funcionarios_data_admissao` (`data_admissao`),
  KEY `idx_funcionarios_salario_base` (`salario_base`),
  KEY `idx_funcionarios_status_salario` (`status`,`salario_base`),
  CONSTRAINT `funcionarios_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `funcionarios_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `funcionarios` VALUES('1','11','2','FUNC/2/2026/0001','Osvaldo Paulo','administrativo','Secretário','005922770BO043',NULL,NULL,NULL,'Angolana',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0',NULL,NULL,NULL,NULL,NULL,'943911384',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-16','Efetivo',NULL,'12ª Classe',NULL,NULL,'0.00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'corrente',NULL,NULL,'1',NULL,NULL,NULL,'1776335146_69e0b92a45e7d.png','ativo','2026-04-16 11:25:46','2026-04-16 21:55:40','10000.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0','0.00','0.00','0.00','0',NULL,NULL,'40','Período Integral',NULL);
INSERT INTO `funcionarios` VALUES('2','12','2','FUNC/2/2026/0002','Tatiana Paulo','professor','Professor Principal','005922770BO044',NULL,NULL,NULL,'Angolana',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'','2026-04-08','',NULL,'0',NULL,NULL,NULL,NULL,NULL,'943911384',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-16','Efetivo',NULL,'12ª Classe','',NULL,'0.00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'BAI','corrente',NULL,NULL,'1',NULL,NULL,NULL,'funcionario_2_1776818526.png','ativo','2026-04-16 22:48:40','2026-04-22 01:42:35','100000.00','10000.00','2000.00','0.00','0.00','0.00','0.00','0.00','0.00','0','0.00','0.00','0.00','0',NULL,NULL,'40','Período Integral',NULL);
INSERT INTO `funcionarios` VALUES('3','33','2','FUNC/2/2026/0003','Armando Alberto João','professor','Professor Principal',NULL,NULL,NULL,NULL,'Angolana',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0',NULL,NULL,NULL,NULL,NULL,'943911384',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-01','Efetivo',NULL,NULL,NULL,NULL,'0.00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'corrente',NULL,NULL,'1',NULL,NULL,NULL,'avatar_func_1777587054_69f3d36e102d5.png','inativo','2026-04-30 23:10:54','2026-05-02 17:19:52','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0','0.00','0.00','0.00','0',NULL,NULL,'40','Período Integral',NULL);

DROP TABLE IF EXISTS `funcionarios_documentos`;
CREATE TABLE `funcionarios_documentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `tipo_documento` enum('bi','certificado_habilitacoes','diploma','certificacao','contrato','atestado_medico','declaracao','outro') NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL,
  `formato_papel` enum('A4','A5','A3','Carta','Outro') DEFAULT 'A4',
  `tamanho_arquivo` int(11) DEFAULT NULL,
  `data_upload` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_tipo` (`tipo_documento`),
  CONSTRAINT `funcionarios_documentos_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `funcionarios_documentos` VALUES('1','1','','carteira_profissional_1776335146_69e0b92a49730.png','uploads/funcionarios/documentos/1/carteira_profissional_1776335146_69e0b92a49730.png','Outro','745524','2026-04-16 11:25:46');

DROP TABLE IF EXISTS `horarios`;
CREATE TABLE `horarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL COMMENT 'ID do professor',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma',
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `dia_semana` enum('segunda','terca','quarta','quinta','sexta','sabado','domingo') NOT NULL COMMENT 'Dia da semana',
  `horario_inicio` time NOT NULL COMMENT 'Horário de início',
  `horario_fim` time NOT NULL COMMENT 'Horário de fim',
  `sala` varchar(50) DEFAULT NULL COMMENT 'Sala atribuída',
  `status` tinyint(1) DEFAULT 1 COMMENT '1-Ativo, 0-Inativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_horarios_professor` (`professor_id`),
  KEY `idx_horarios_disciplina` (`disciplina_id`),
  KEY `idx_horarios_turma` (`turma_id`),
  KEY `idx_horarios_escola` (`escola_id`),
  KEY `idx_horarios_ano_letivo` (`ano_letivo_id`),
  KEY `idx_horarios_dia_semana` (`dia_semana`),
  CONSTRAINT `fk_horarios_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_horarios_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_horarios_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_horarios_professor` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_horarios_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Grade de horários dos professores';

INSERT INTO `horarios` VALUES('2','2','1','9','2','3','sexta','12:35:00','13:00:00','6','1','2026-04-24 13:03:38','2026-04-24 14:16:22');
INSERT INTO `horarios` VALUES('3','2','1','10','2','3','quinta','14:11:00','15:14:00',NULL,'1','2026-04-24 13:12:27','2026-04-24 13:12:27');
INSERT INTO `horarios` VALUES('4','2','1','10','2','3','quinta','14:11:00','15:14:00',NULL,'1','2026-04-24 13:24:07','2026-04-24 13:24:07');
INSERT INTO `horarios` VALUES('5','2','1','9','1','3','segunda','08:00:00','10:00:00',NULL,'1','2026-04-24 14:19:12','2026-04-24 14:19:12');
INSERT INTO `horarios` VALUES('6','2','1','9','1','3','terca','10:00:00','12:00:00',NULL,'1','2026-04-24 14:19:12','2026-04-24 14:19:12');
INSERT INTO `horarios` VALUES('7','2','1','9','1','3','quarta','14:00:00','16:00:00',NULL,'1','2026-04-24 14:19:12','2026-04-24 14:19:12');
INSERT INTO `horarios` VALUES('8','2','1','9','1','3','quinta','09:00:00','11:00:00',NULL,'1','2026-04-24 14:19:12','2026-04-24 14:19:12');
INSERT INTO `horarios` VALUES('9','2','1','9','1','3','sexta','13:00:00','15:00:00',NULL,'1','2026-04-24 14:19:12','2026-04-24 14:19:12');

DROP TABLE IF EXISTS `horarios_coordenacao`;
CREATE TABLE `horarios_coordenacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `professor_id` int(11) DEFAULT NULL,
  `dia_semana` enum('segunda','terca','quarta','quinta','sexta','sabado') NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `sala` varchar(50) DEFAULT NULL,
  `periodo` varchar(20) DEFAULT NULL,
  `ano_letivo` varchar(9) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `turma_id` (`turma_id`),
  KEY `disciplina_id` (`disciplina_id`),
  KEY `professor_id` (`professor_id`),
  CONSTRAINT `horarios_coordenacao_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horarios_coordenacao_ibfk_2` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horarios_coordenacao_ibfk_3` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horarios_coordenacao_ibfk_4` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `inscricoes`;
CREATE TABLE `inscricoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `aluno_nome` varchar(100) NOT NULL,
  `data_nasc` date NOT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `escola_origem` varchar(100) DEFAULT NULL,
  `classe_pretendida` varchar(20) NOT NULL,
  `nome_encarregado` varchar(100) NOT NULL,
  `telefone_encarregado` varchar(20) NOT NULL,
  `ano_letivo` varchar(9) NOT NULL,
  `status` enum('pendente','aprovado','rejeitado','matriculado') DEFAULT 'pendente',
  `data_inscricao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  CONSTRAINT `inscricoes_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `justificativas_falta`;
CREATE TABLE `justificativas_falta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno',
  `chamada_id` int(11) NOT NULL COMMENT 'ID do registo de chamada',
  `tipo_justificativa` enum('medico','familiar','particular','outro') NOT NULL COMMENT 'Tipo de justificativa',
  `descricao` text NOT NULL COMMENT 'Descrição da justificativa',
  `documento` varchar(255) DEFAULT NULL COMMENT 'Documento comprovativo',
  `data_justificativa` date NOT NULL COMMENT 'Data da justificativa',
  `aprovado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que aprovou',
  `status` enum('pendente','aprovado','rejeitado') DEFAULT 'pendente' COMMENT 'Status da justificativa',
  `parecer` text DEFAULT NULL COMMENT 'Parecer da direção',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_justificativa_aluno` (`estudante_id`),
  KEY `idx_justificativa_chamada` (`chamada_id`),
  KEY `idx_justificativa_status` (`status`),
  KEY `idx_justificativa_escola` (`escola_id`),
  CONSTRAINT `fk_justificativa_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_justificativa_chamada` FOREIGN KEY (`chamada_id`) REFERENCES `chamada` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_justificativa_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Justificativas de faltas dos estudantes';


DROP TABLE IF EXISTS `lancamentos_notas`;
CREATE TABLE `lancamentos_notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `atividade_id` int(11) NOT NULL COMMENT 'ID da atividade',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno (estudantes.id)',
  `nota` decimal(5,2) DEFAULT NULL COMMENT 'Nota obtida (convertida para 0-10)',
  `observacao` text DEFAULT NULL COMMENT 'Observação',
  `lancado_por` int(11) NOT NULL COMMENT 'ID do professor que lançou',
  `data_lancamento` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lancamento_unico` (`atividade_id`,`estudante_id`),
  KEY `idx_lancamentos_atividade` (`atividade_id`),
  KEY `idx_lancamentos_aluno` (`estudante_id`),
  KEY `idx_lancamentos_professor` (`lancado_por`),
  CONSTRAINT `fk_lancamentos_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lancamentos_atividade` FOREIGN KEY (`atividade_id`) REFERENCES `atividades` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lancamentos_professor` FOREIGN KEY (`lancado_por`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de lançamentos de notas das atividades';

INSERT INTO `lancamentos_notas` VALUES('1','4','10','7.00',NULL,'2','2026-04-23 21:22:30','2026-04-23 21:22:30','2026-04-23 21:22:53');
INSERT INTO `lancamentos_notas` VALUES('2','4','12','3.00',NULL,'2','2026-04-23 21:22:30','2026-04-23 21:22:30','2026-04-23 21:22:53');
INSERT INTO `lancamentos_notas` VALUES('3','4','11','7.00',NULL,'2','2026-04-23 21:22:30','2026-04-23 21:22:30','2026-04-23 21:22:53');
INSERT INTO `lancamentos_notas` VALUES('4','4','8','9.00',NULL,'2','2026-04-23 21:22:30','2026-04-23 21:22:30','2026-04-23 21:22:53');
INSERT INTO `lancamentos_notas` VALUES('5','4','9','1.00',NULL,'2','2026-04-23 21:22:30','2026-04-23 21:22:30','2026-04-23 21:22:53');

DROP TABLE IF EXISTS `licencas`;
CREATE TABLE `licencas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `tipo_licenca` enum('medica','maternidade','paternidade','luto','casamento','estudo','nao_remunerada','outra') NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `dias_solicitados` int(11) NOT NULL,
  `documento_comprovativo` varchar(500) DEFAULT NULL,
  `status` enum('pendente','aprovado','rejeitado','cancelado') DEFAULT 'pendente',
  `observacoes` text DEFAULT NULL,
  `aprovado_por` int(11) DEFAULT NULL,
  `data_solicitacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `funcionario_id` (`funcionario_id`),
  KEY `aprovado_por` (`aprovado_por`),
  KEY `idx_tipo` (`tipo_licenca`),
  KEY `idx_status` (`status`),
  CONSTRAINT `licencas_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `licencas_ibfk_2` FOREIGN KEY (`aprovado_por`) REFERENCES `funcionarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `livros`;
CREATE TABLE `livros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `autor` varchar(100) DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `capa` varchar(255) DEFAULT NULL,
  `arquivo` varchar(255) NOT NULL,
  `visualizacoes` int(11) DEFAULT 0,
  `downloads` int(11) DEFAULT 0,
  `status` enum('disponivel','indisponivel') DEFAULT 'disponivel',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `livros_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `logs_sistema`;
CREATE TABLE `logs_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `escola_id` int(11) DEFAULT NULL,
  `acao` varchar(100) NOT NULL,
  `tabela` varchar(50) DEFAULT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `dados_antes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dados_antes`)),
  `dados_depois` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dados_depois`)),
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_acao` (`acao`),
  CONSTRAINT `logs_sistema_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `logs_sistema_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `logs_sistema` VALUES('1','1',NULL,'editar_plano','planos','1',NULL,NULL,'127.0.0.1','2026-04-09 18:04:32');
INSERT INTO `logs_sistema` VALUES('2','1',NULL,'renovar_assinatura','assinaturas','1',NULL,NULL,'127.0.0.1','2026-04-09 20:28:38');
INSERT INTO `logs_sistema` VALUES('3','1',NULL,'registrar_pagamento','pagamentos','3',NULL,NULL,'127.0.0.1','2026-04-09 21:12:22');
INSERT INTO `logs_sistema` VALUES('10','1',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-04-10 21:30:32');
INSERT INTO `logs_sistema` VALUES('11','1',NULL,'alterar_acesso_admin','usuarios','3',NULL,'{\"email\":\"armandapombal@gmail.com\",\"senha_alterada\":true}','127.0.0.1','2026-04-11 14:10:58');
INSERT INTO `logs_sistema` VALUES('12','1',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-04-11 14:11:05');
INSERT INTO `logs_sistema` VALUES('13','1',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-11 15:54:23');
INSERT INTO `logs_sistema` VALUES('14','3',NULL,'cadastrar_turma','turmas','9',NULL,NULL,'127.0.0.1','2026-04-11 16:22:01');
INSERT INTO `logs_sistema` VALUES('15','3',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-11 16:44:24');
INSERT INTO `logs_sistema` VALUES('16','1',NULL,'atualizar_config_sistema','configuracoes_sistema',NULL,NULL,NULL,'::1','2026-04-11 16:47:29');
INSERT INTO `logs_sistema` VALUES('17','1',NULL,'atualizar_config_sistema','configuracoes_sistema',NULL,NULL,NULL,'::1','2026-04-11 16:48:49');
INSERT INTO `logs_sistema` VALUES('18','1',NULL,'renovar_assinatura','assinaturas','2',NULL,NULL,'::1','2026-04-11 16:49:45');
INSERT INTO `logs_sistema` VALUES('19','1',NULL,'registrar_pagamento','pagamentos','5',NULL,NULL,'::1','2026-04-11 16:50:47');
INSERT INTO `logs_sistema` VALUES('20','1',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-13 20:10:38');
INSERT INTO `logs_sistema` VALUES('21','1',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-14 22:37:47');
INSERT INTO `logs_sistema` VALUES('22','3',NULL,'folha_pagamento','folha_funcionarios','1',NULL,NULL,'::1','2026-04-16 14:47:09');
INSERT INTO `logs_sistema` VALUES('23','3',NULL,'folha_pagamento','folha_funcionarios','1',NULL,NULL,'::1','2026-04-16 14:47:39');
INSERT INTO `logs_sistema` VALUES('24','3',NULL,'folha_pagamento','folha_funcionarios','1',NULL,NULL,'::1','2026-04-16 14:47:57');
INSERT INTO `logs_sistema` VALUES('25','3',NULL,'cadastrar_aluno','estudantes','8',NULL,NULL,'::1','2026-04-18 13:35:52');
INSERT INTO `logs_sistema` VALUES('26','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-22 01:43:41');
INSERT INTO `logs_sistema` VALUES('27','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-22 02:38:57');
INSERT INTO `logs_sistema` VALUES('28','3',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-22 03:07:17');
INSERT INTO `logs_sistema` VALUES('29','3',NULL,'cadastrar_aluno','estudantes','9',NULL,NULL,'::1','2026-04-23 01:02:38');
INSERT INTO `logs_sistema` VALUES('30','3',NULL,'cadastrar_aluno','estudantes','10',NULL,NULL,'::1','2026-04-23 01:03:18');
INSERT INTO `logs_sistema` VALUES('31','3',NULL,'cadastrar_aluno','estudantes','11',NULL,NULL,'::1','2026-04-23 01:04:06');
INSERT INTO `logs_sistema` VALUES('32','3',NULL,'cadastrar_aluno','estudantes','12',NULL,NULL,'::1','2026-04-23 01:28:40');
INSERT INTO `logs_sistema` VALUES('33','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 10:41:30');
INSERT INTO `logs_sistema` VALUES('34','3',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 10:44:06');
INSERT INTO `logs_sistema` VALUES('35','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 10:56:55');
INSERT INTO `logs_sistema` VALUES('36','2',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 14:16:53');
INSERT INTO `logs_sistema` VALUES('37','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 14:20:56');
INSERT INTO `logs_sistema` VALUES('38','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 14:21:16');
INSERT INTO `logs_sistema` VALUES('39','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:22:30');
INSERT INTO `logs_sistema` VALUES('40','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:23:06');
INSERT INTO `logs_sistema` VALUES('41','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:23:21');
INSERT INTO `logs_sistema` VALUES('42','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:30:56');
INSERT INTO `logs_sistema` VALUES('43','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:31:54');
INSERT INTO `logs_sistema` VALUES('44','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-27 16:32:37');
INSERT INTO `logs_sistema` VALUES('45','3',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-04-28 00:37:48');
INSERT INTO `logs_sistema` VALUES('46','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-04-30 23:12:53');
INSERT INTO `logs_sistema` VALUES('47','33',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-05-01 09:13:28');
INSERT INTO `logs_sistema` VALUES('48','33',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-05-01 09:13:41');
INSERT INTO `logs_sistema` VALUES('49','33',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-05-01 23:31:04');
INSERT INTO `logs_sistema` VALUES('50','3',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-05-01 23:46:36');
INSERT INTO `logs_sistema` VALUES('51','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-05-01 23:52:38');
INSERT INTO `logs_sistema` VALUES('52','12',NULL,'logout',NULL,NULL,NULL,NULL,'::1','2026-05-01 23:53:53');
INSERT INTO `logs_sistema` VALUES('53','1',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-05-02 00:00:36');
INSERT INTO `logs_sistema` VALUES('54','1',NULL,'logout',NULL,NULL,NULL,NULL,'127.0.0.1','2026-05-02 00:01:58');

DROP TABLE IF EXISTS `manuais`;
CREATE TABLE `manuais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `descricao` text NOT NULL,
  `categoria` enum('sistema','notas','matricula','financeiro','relatorios','admin') DEFAULT 'sistema',
  `tipo` enum('pdf','doc','xls','ppt','video','link') DEFAULT 'pdf',
  `url` varchar(500) DEFAULT NULL,
  `arquivo` varchar(255) DEFAULT NULL,
  `downloads` int(11) DEFAULT 0,
  `ordem` int(11) DEFAULT 0,
  `ativo` tinyint(4) DEFAULT 1,
  `escola_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_ativo` (`ativo`),
  KEY `idx_downloads` (`downloads`),
  CONSTRAINT `manuais_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `manuais` VALUES('1','Manual do Sistema SIGE','Manual completo do sistema de gestão escolar','sistema','pdf','../../uploads/manuais/manual_sistema.pdf',NULL,'150','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');
INSERT INTO `manuais` VALUES('2','Guia de Lançamento de Notas','Tutorial passo a passo para lançar notas dos alunos','notas','pdf','../../uploads/manuais/guia_notas.pdf',NULL,'89','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');
INSERT INTO `manuais` VALUES('3','Manual de Matrículas','Como realizar matrículas e rematrículas no sistema','matricula','pdf','../../uploads/manuais/manual_matriculas.pdf',NULL,'67','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');
INSERT INTO `manuais` VALUES('4','Guia Financeiro','Gestão de pagamentos e emissão de recibos','financeiro','pdf','../../uploads/manuais/guia_financeiro.pdf',NULL,'45','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');
INSERT INTO `manuais` VALUES('5','Tutorial de Relatórios','Como emitir boletins e relatórios gerenciais','relatorios','pdf','../../uploads/manuais/tutorial_relatorios.pdf',NULL,'34','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');
INSERT INTO `manuais` VALUES('6','Vídeo Tutorial - Primeiros Passos','Vídeo demonstrativo do sistema','sistema','video','https://www.youtube.com/watch?v=exemplo',NULL,'120','0','1','1','2026-05-06 23:52:35','2026-05-06 23:52:35');

DROP TABLE IF EXISTS `materiais_didaticos`;
CREATE TABLE `materiais_didaticos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `conteudo` longtext DEFAULT NULL,
  `tipo` enum('livro','apostila','video','apresentacao','exercicio','prova','link','pdf','recurso') DEFAULT 'livro',
  `categoria` varchar(50) DEFAULT NULL,
  `disciplina_id` int(11) DEFAULT NULL,
  `ano_letivo_id` int(11) DEFAULT NULL,
  `autor` varchar(100) DEFAULT NULL,
  `editora` varchar(100) DEFAULT NULL,
  `arquivo` varchar(255) DEFAULT NULL,
  `tipo_arquivo` varchar(50) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `link_video` varchar(500) DEFAULT NULL,
  `link_pdf` varchar(500) DEFAULT NULL,
  `link_material` varchar(500) DEFAULT NULL,
  `link_externo` varchar(500) DEFAULT NULL,
  `arquivo_pdf` varchar(255) DEFAULT NULL,
  `arquivo_anexo` varchar(255) DEFAULT NULL,
  `capa` varchar(255) DEFAULT NULL,
  `data_publicacao` date DEFAULT NULL,
  `downloads` int(11) DEFAULT 0,
  `visualizacoes` int(11) DEFAULT 0,
  `avaliacao_media` decimal(3,2) DEFAULT 0.00,
  `destaque` tinyint(4) DEFAULT 0,
  `status` varchar(20) DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `disciplina_id` (`disciplina_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  CONSTRAINT `materiais_didaticos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `materiais_didaticos_ibfk_2` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`),
  CONSTRAINT `materiais_didaticos_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=431 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `materiais_didaticos` VALUES('376','2','Matemática - Volume 1 (PDF)','Livro completo de Matemática para Ensino Médio','Matemática básica e avançada','livro','Matemática','1',NULL,'Prof. João Silva','Editora Educação',NULL,NULL,NULL,NULL,'https://www.educacao.mec.gov.br/livros/matematica_volume1.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'320','1500','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('377','2','Português - Gramática Completa','Gramática da Língua Portuguesa atualizada','Gramática completa','livro','Português','2',NULL,'Maria Santos','Editora Letras',NULL,NULL,NULL,NULL,'https://www.educacao.mec.gov.br/livros/portugues_gramatica.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'280','1200','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('378','2','Física - Mecânica Clássica','Fundamentos da Física Mecânica','Física básica','livro','Física','3',NULL,'Dr. Carlos Alberto','Editora Ciência',NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/livros/fisica_mecanica.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'150','800','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('379','2','Química Geral','Química geral e inorgânica completa','Química básica','livro','Química','4',NULL,'Profa. Ana Paula','Editora Ciência',NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/livros/quimica_geral.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'210','950','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('380','2','História de Angola','História completa de Angola','História angolana','livro','História','5',NULL,'Dr. António Costa','Editora Angola',NULL,NULL,NULL,NULL,'https://www.historia.gov.ao/livros/historia_angola.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'450','2100','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('381','2','Geografia - Geografia Geral','Geografia física e humana','Geografia básica','livro','Geografia','6',NULL,'Profa. Carla Mendes','Editora Terra',NULL,NULL,NULL,NULL,'https://www.geografia.gov.br/livros/geografia_geral.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'120','680','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('382','2','Inglês - English Course','Curso completo de inglês','Inglês básico ao avançado','livro','Inglês','7',NULL,'Prof. Michael Johnson','Oxford Press',NULL,NULL,NULL,NULL,'https://www.oxford.com/livros/ingles_curso.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'380','1420','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('383','2','Biologia Celular','Biologia celular e molecular','Biologia avançada','livro','Biologia','8',NULL,'Profa. Sofia Lima','Editora Vida',NULL,NULL,NULL,NULL,'https://www.biologia.gov.br/livros/biologia_celular.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'180','720','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('384','2','Filosofia - Introdução','Introdução à filosofia ocidental','Filosofia básica','livro','Filosofia','9',NULL,'Prof. Ricardo Alves','Editora Pensar',NULL,NULL,NULL,NULL,'https://www.filosofia.gov.br/livros/filosofia_introducao.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'95','450','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('385','2','Educação Física - Teoria','Fundamentos da educação física','Educação física teórica','livro','Educação Física','10',NULL,'Prof. Carlos Mendes','Editora Esporte',NULL,NULL,NULL,NULL,'https://www.esporte.gov.br/livros/educacao_fisica.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'78','380','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('386','2','Vídeo - Matemática: Equações do 2º Grau','Aprenda a resolver equações do 2º grau','Videoaula completa','video','Matemática','1',NULL,'Prof. João Silva',NULL,NULL,NULL,NULL,'https://www.youtube.com/shorts/gFv-L3VLDMU',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','2850','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 01:05:49');
INSERT INTO `materiais_didaticos` VALUES('387','2','Vídeo - Teorema de Pitágoras na Prática','Demonstração do Teorema de Pitágoras','Videoaula interativa','video','Matemática','1',NULL,'Profa. Maria Santos',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/CAkMUdeB6pI',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1950','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('388','2','Vídeo - Reações Químicas Explicadas','Tipos de reações químicas','Videoaula com exemplos','video','Química','4',NULL,'Dr. Carlos Alberto',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/9nCymUI8ma8',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1680','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('389','2','Vídeo - Independência de Angola','Documentário sobre a independência','Documentário histórico','video','História','5',NULL,'Dr. António Costa',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/9j3YvQZFqIY',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','3420','1.00','1','ativo','2026-04-30 00:58:54','2026-04-30 01:48:41');
INSERT INTO `materiais_didaticos` VALUES('390','2','Vídeo - Simple Past em Inglês','Aula completa sobre passado simples','Videoaula de inglês','video','Inglês','7',NULL,'Prof. Michael Johnson',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/RIiZB9Wr6Wk',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1250','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('391','2','Vídeo - Mitose e Meiose','Processo de divisão celular','Videoaula de biologia','video','Biologia','8',NULL,'Profa. Sofia Lima',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/6NQE5oarR8U',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1890','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('392','2','Vídeo - Funções Matemáticas','Gráficos e propriedades das funções','Videoaula animada','video','Matemática','1',NULL,'Prof. João Silva',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/RIiZB9Wr6Wk',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1570','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('393','2','Vídeo - Leis de Newton','As três leis de Newton explicadas','Videoaula de física','video','Física','3',NULL,'Dr. Carlos Alberto',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/kJwQP2mYq6A',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','2230','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('394','2','Vídeo - Tabela Periódica','Como ler a tabela periódica','Videoaula de química','video','Química','4',NULL,'Profa. Ana Paula',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/9nCymUI8ma8',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','980','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('395','2','Vídeo - Grandes Navegações','História das navegações portuguesas','Documentário histórico','video','História','5',NULL,'Dr. António Costa',NULL,NULL,NULL,NULL,'https://www.youtube.com/embed/9j3YvQZFqIY',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1340','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('396','2','Apostila de Matemática - Exercícios','500 exercícios resolvidos de Matemática','Exercícios progressivos','apostila','Matemática','1',NULL,'Prof. João Paulo',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/apostilas/matematica_exercicios.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'310','980','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('397','2','Apostila de Redação ENEM','Técnicas de redação nota 1000','Redação e gramática','apostila','Português','2',NULL,'Profa. Ana Maria',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/apostilas/redacao_enem.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'420','1560','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('398','2','Apostila de Física - Exercícios','Exercícios de física com resolução','Física aplicada','apostila','Física','3',NULL,'Dr. Roberto Carlos',NULL,NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/apostilas/fisica_exercicios.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'180','650','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('399','2','Apostila de Química - Estequiometria','Cálculos estequiométricos','Química prática','apostila','Química','4',NULL,'Profa. Paula Souza',NULL,NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/apostilas/quimica_estequiometria.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'145','580','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('400','2','Apostila de História - Resumos','Resumos para vestibulares','História resumida','apostila','História','5',NULL,'Dr. António Costa',NULL,NULL,NULL,NULL,NULL,'https://www.historia.gov.br/apostilas/historia_resumos.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'230','820','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('401','2','Apostila de Geografia - Mapas','Mapas e geopolítica mundial','Geografia ilustrada','apostila','Geografia','6',NULL,'Profa. Carla Mendes',NULL,NULL,NULL,NULL,NULL,'https://www.geografia.gov.br/apostilas/geografia_mapas.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'110','490','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('402','2','Apostila de Inglês - Verbos Irregulares','Lista completa de verbos irregulares','Inglês gramática','apostila','Inglês','7',NULL,'Prof. Michael Johnson',NULL,NULL,NULL,NULL,NULL,'https://www.oxford.com/apostilas/ingles_verbos.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'195','720','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('403','2','Apostila de Biologia - Ecologia','Ecologia e meio ambiente','Biologia ambiental','apostila','Biologia','8',NULL,'Profa. Sofia Lima',NULL,NULL,NULL,NULL,NULL,'https://www.biologia.gov.br/apostilas/ecologia.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'98','430','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('404','2','Apostila de Filosofia - Pensadores','Principais filósofos e suas ideias','Filosofia para provas','apostila','Filosofia','9',NULL,'Prof. Ricardo Alves',NULL,NULL,NULL,NULL,NULL,'https://www.filosofia.gov.br/apostilas/pensadores.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'85','380','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('405','2','Apostila de Sociologia','Conceitos fundamentais da sociologia','Sociologia básica','apostila','Sociologia','11',NULL,'Profa. Lúcia Ferreira',NULL,NULL,NULL,NULL,NULL,'https://www.sociologia.gov.br/apostilas/sociologia_basica.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'72','310','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('406','2','Prova - Matemática 1º Bimestre','Prova modelo de Matemática com gabarito','Avaliação bimestral','prova','Matemática','1',NULL,'Prof. João Paulo',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/provas/matematica_1bimestre.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'245','890','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('407','2','Prova - Português 2º Bimestre','Prova de Português com interpretação','Avaliação de português','prova','Português','2',NULL,'Profa. Maria Santos',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/provas/portugues_2bimestre.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'178','620','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('408','2','Prova - Física 3º Bimestre','Prova de Física sobre ondulatória','Avaliação de física','prova','Física','3',NULL,'Dr. Carlos Alberto',NULL,NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/provas/fisica_3bimestre.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'120','450','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('409','2','Prova - História 4º Bimestre','Prova de história contemporânea','Avaliação histórica','prova','História','5',NULL,'Dr. António Costa',NULL,NULL,NULL,NULL,NULL,'https://www.historia.gov.br/provas/historia_4bimestre.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'210','780','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('410','2','Prova - Inglês 1º Bimestre','Prova de inglês com listening','Avaliação de inglês','prova','Inglês','7',NULL,'Prof. Michael Johnson',NULL,NULL,NULL,NULL,NULL,'https://www.oxford.com/provas/ingles_1bimestre.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'145','560','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('411','2','Exercícios - Geometria Plana','50 exercícios de geometria plana resolvidos','Geometria para prática','exercicio','Matemática','1',NULL,'Prof. João Paulo',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/exercicios/geometria_plana.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'210','720','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('412','2','Exercícios - Concordância Verbal','Exercícios de concordância com gabarito','Gramática aplicada','exercicio','Português','2',NULL,'Profa. Maria Santos',NULL,NULL,NULL,NULL,NULL,'https://www.educacao.gov.br/exercicios/concordancia_verbal.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'148','530','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('413','2','Exercícios - Leis de Newton','Questões sobre as três leis de Newton','Física aplicada','exercicio','Física','3',NULL,'Dr. Carlos Alberto',NULL,NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/exercicios/leis_newton.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'115','420','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('414','2','Exercícios - Tabela Periódica','Exercícios sobre elementos químicos','Química aplicada','exercicio','Química','4',NULL,'Profa. Ana Paula',NULL,NULL,NULL,NULL,NULL,'https://www.ciencia.gov.br/exercicios/tabela_periodica.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'98','380','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('415','2','Exercícios - Tempos Verbais','Exercícios de conjugação em inglês','Inglês prático','exercicio','Inglês','7',NULL,'Prof. Michael Johnson',NULL,NULL,NULL,NULL,NULL,'https://www.oxford.com/exercicios/tempos_verbais.pdf',NULL,NULL,NULL,NULL,NULL,NULL,'132','490','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('416','2','Resumo de Matemática - HTML','Resumo completo de Matemática em HTML','<div class=\"resumo\"><h2>📚 Matemática Resumida</h2><h3>Álgebra</h3><p>Equações do 1º e 2º grau...</p><h3>Geometria</h3><p>Áreas e volumes</p></div>','apostila','Matemática','1',NULL,'Prof. João Paulo',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'95','430','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('417','2','Resumo de História - HTML','Resumo de História de Angola em HTML','<div class=\"resumo\"><h2>📚 História de Angola</h2><h3>Período Colonial</h3><p>Colonização portuguesa</p><h3>Independência</h3><p>11 de novembro de 1975</p></div>','apostila','História','5',NULL,'Dr. António Costa',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'120','650','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('418','2','Guia de Redação - HTML','Guia completo para redação nota mil','<div class=\"guia\"><h2>📝 Guia de Redação</h2><h3>Introdução</h3><p>Como começar sua redação</p><h3>Conclusão</h3><p>Fechamento perfeito</p></div>','apostila','Português','2',NULL,'Profa. Ana Maria',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'85','380','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('419','2','Tutorial de Física - HTML','Tutorial interativo de física','<div class=\"tutorial\"><h2>⚡ Física Interativa</h2><h3>Movimento Uniforme</h3><p>Fórmulas e exemplos</p></div>','apostila','Física','3',NULL,'Dr. Carlos Alberto',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'62','310','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('420','2','Guia de Inglês - HTML','Guia rápido de inglês para viagens','<div class=\"guia\"><h2>🌎 English Guide</h2><h3>Basic Phrases</h3><p>Hello, Good morning, Thank you</p><h3>Numbers</h3><p>1 to 100</p></div>','apostila','Inglês','7',NULL,'Prof. Michael Johnson',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'88','420','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('421','2','Portal da Matemática','Site com exercícios interativos de Matemática','','link','Matemática','1',NULL,'MEC',NULL,NULL,NULL,'https://matematica.mec.gov.br',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1250','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('422','2','Biblioteca Nacional','Acervo digital de livros e documentos','','link','História','5',NULL,'Governo',NULL,NULL,NULL,'https://bibliotecanacional.gov.br',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','980','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('423','2','Khan Academy','Plataforma de ensino com videoaulas gratuitas','','link','Geral',NULL,NULL,'Khan Academy',NULL,NULL,NULL,'https://pt.khanacademy.org',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','2450','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('424','2','Google Books','Milhares de livros para consulta online','','link','Geral',NULL,NULL,'Google',NULL,NULL,NULL,'https://books.google.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','1890','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('425','2','Revista Escola','Artigos e materiais pedagógicos','','link','Pedagogia',NULL,NULL,'Editora Moderna',NULL,NULL,NULL,'https://revistaescola.com.br',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','560','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('426','2','Manual do Professor - Matemática','Guia completo para professores de Matemática','Manual pedagógico','livro','Matemática','1',NULL,'Prof. João Silva',NULL,'uploads/materiais/manual_matematica.pdf','pdf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'95','320','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('427','2','Guia do Professor - Português','Metodologias para ensino de Português','Guia pedagógico','livro','Português','2',NULL,'Profa. Maria Santos',NULL,'uploads/materiais/guia_portugues.pdf','pdf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'78','280','0.00','1','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('428','2','Apostila Local - Física','Apostila produzida pela escola','Material interno','apostila','Física','3',NULL,'Dr. Carlos Alberto',NULL,'uploads/materiais/apostila_fisica_local.pdf','pdf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'52','190','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('429','2','Prova Local - Química','Modelo de prova da escola','Avaliação interna','prova','Química','4',NULL,'Profa. Ana Paula',NULL,'uploads/materiais/prova_quimica_local.pdf','pdf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'42','150','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');
INSERT INTO `materiais_didaticos` VALUES('430','2','Exercícios Local - Biologia','Lista de exercícios da escola','Material complementar','exercicio','Biologia','8',NULL,'Profa. Sofia Lima',NULL,'uploads/materiais/exercicios_biologia_local.pdf','pdf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'65','210','0.00','0','ativo','2026-04-30 00:58:54','2026-04-30 00:58:54');

DROP TABLE IF EXISTS `matriculas`;
CREATE TABLE `matriculas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `estudante_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `turno` int(11) DEFAULT NULL,
  `curso` int(11) DEFAULT NULL,
  `sala` int(11) DEFAULT NULL,
  `classe` varchar(50) DEFAULT NULL,
  `nivel` int(11) DEFAULT NULL,
  `ano_letivo` int(11) DEFAULT NULL,
  `data_matricula` date DEFAULT curdate(),
  `status` enum('ativa','transferido','concluido','desistente') DEFAULT 'ativa',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `numero_processo` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `turma_id` (`turma_id`),
  KEY `idx_estudante` (`estudante_id`),
  KEY `idx_matriculas_turno` (`turno`),
  KEY `idx_matriculas_curso` (`curso`),
  KEY `idx_matriculas_sala` (`sala`),
  KEY `idx_matriculas_nivel` (`nivel`),
  KEY `fk_matriculas_ano_letivo` (`ano_letivo`),
  CONSTRAINT `fk_matriculas_ano_letivo` FOREIGN KEY (`ano_letivo`) REFERENCES `ano_letivo` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `matriculas_ibfk_1` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `matriculas_ibfk_2` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `matriculas` VALUES('3','2','8','9','0','0','0','5º Ano','0','3','2026-04-18','ativa','2026-04-18 13:35:52','2026-05-02 23:29:02','2026/002/00001');
INSERT INTO `matriculas` VALUES('4','2','9','9','0',NULL,'0','5ª Classe',NULL,'3','2026-04-23','ativa','2026-04-23 01:02:38','2026-05-02 23:29:02','2026/002/00002');
INSERT INTO `matriculas` VALUES('5','2','10','9','0',NULL,'0','5ª Classe',NULL,'3','2026-04-23','ativa','2026-04-23 01:03:18','2026-05-02 23:29:02','2026/002/00003');
INSERT INTO `matriculas` VALUES('6','2','11','9','0',NULL,'0','5ª Classe',NULL,'3','2026-04-23','ativa','2026-04-23 01:04:06','2026-05-02 23:29:02','2026/002/00004');
INSERT INTO `matriculas` VALUES('7','2','12','9','0',NULL,'0','5ª Classe',NULL,'3','2026-04-23','ativa','2026-04-23 01:28:40','2026-05-02 23:29:02','2026/002/00005');

DROP TABLE IF EXISTS `meses`;
CREATE TABLE `meses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(20) NOT NULL,
  `numero` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_numero` (`numero`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `meses` VALUES('1','Janeiro','1');
INSERT INTO `meses` VALUES('2','Fevereiro','2');
INSERT INTO `meses` VALUES('3','Março','3');
INSERT INTO `meses` VALUES('4','Abril','4');
INSERT INTO `meses` VALUES('5','Maio','5');
INSERT INTO `meses` VALUES('6','Junho','6');
INSERT INTO `meses` VALUES('7','Julho','7');
INSERT INTO `meses` VALUES('8','Agosto','8');
INSERT INTO `meses` VALUES('9','Setembro','9');
INSERT INTO `meses` VALUES('10','Outubro','10');
INSERT INTO `meses` VALUES('11','Novembro','11');
INSERT INTO `meses` VALUES('12','Dezembro','12');

DROP TABLE IF EXISTS `modulos_sistema`;
CREATE TABLE `modulos_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `icone` varchar(50) DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `ativo` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `modulos_sistema` VALUES('1','Dashboard','Painel principal do sistema','tachometer-alt','1','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('2','Académico','Gestão de alunos, turmas, disciplinas','graduation-cap','2','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('3','Notas e Avaliações','Lançamento e consulta de notas','edit','3','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('4','Frequência','Registro de chamada e presenças','calendar-check','4','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('5','Biblioteca','Gestão de acervo e empréstimos','book','5','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('6','Financeiro','Mensalidades, recibos, contas','coins','6','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('7','Recursos Humanos','Gestão de funcionários','users','7','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('8','Secretaria','Matrículas, documentos, certificados','building','8','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('9','Comunicação','Comunicados, notificações','envelope','9','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('10','Relatórios','Geração de relatórios','chart-line','10','1','2026-05-02 01:35:16');
INSERT INTO `modulos_sistema` VALUES('11','Configurações','Configurações do sistema','cogs','11','1','2026-05-02 01:35:16');

DROP TABLE IF EXISTS `niveis`;
CREATE TABLE `niveis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL COMMENT 'Nome do nível (Ex: Educação Infantil, Fundamental I, etc)',
  `sigla` varchar(20) DEFAULT NULL COMMENT 'Sigla (EI, EFI, EFII, EM, ES)',
  `descricao` text DEFAULT NULL COMMENT 'Descrição detalhada do nível',
  `ordem` int(11) DEFAULT 0 COMMENT 'Ordem de apresentação',
  `idade_minima` int(11) DEFAULT NULL COMMENT 'Idade mínima para ingresso',
  `idade_maxima` int(11) DEFAULT NULL COMMENT 'Idade máxima recomendada',
  `duracao_anos` int(11) DEFAULT 1 COMMENT 'Duração em anos',
  `escola_id` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_niveis_status` (`status`),
  KEY `idx_niveis_ordem` (`ordem`),
  KEY `idx_niveis_escola` (`escola_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Níveis de ensino';

INSERT INTO `niveis` VALUES('1','Educação Infantil','EI','Educação para crianças de 3 a 5 anos','1','3','5','3',NULL,'1','2026-04-18 09:08:19',NULL);
INSERT INTO `niveis` VALUES('2','Ensino Fundamental I','EF I','1º ao 5º Ano - Crianças de 6 a 10 anos','2','6','10','5',NULL,'1','2026-04-18 09:08:19',NULL);
INSERT INTO `niveis` VALUES('3','Ensino Fundamental II','EF II','6º ao 9º Ano - Adolescentes de 11 a 14 anos','3','11','14','4',NULL,'1','2026-04-18 09:08:19',NULL);
INSERT INTO `niveis` VALUES('4','Ensino Médio','EM','1ª à 3ª Série - Adolescentes de 15 a 17 anos','4','15','17','3',NULL,'1','2026-04-18 09:08:19',NULL);
INSERT INTO `niveis` VALUES('5','Ensino Superior','ES','Graduação - Nível Universitário','5','18','99','4',NULL,'1','2026-04-18 09:08:19',NULL);
INSERT INTO `niveis` VALUES('6','Educação Profissional','EP','Cursos Técnicos e Profissionalizantes','6','15','99','2',NULL,'1','2026-04-18 09:08:19',NULL);

DROP TABLE IF EXISTS `notas`;
CREATE TABLE `notas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola (multiescola)',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `professor_id` int(11) NOT NULL COMMENT 'ID do professor que lançou',
  `turma_id` int(11) DEFAULT NULL COMMENT 'ID da turma',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `bimestre` tinyint(1) NOT NULL COMMENT '1, 2, 3',
  `mac` decimal(4,2) DEFAULT 0.00 COMMENT 'Média de Avaliação Contínua (0-10)',
  `npt` decimal(4,2) DEFAULT 0.00 COMMENT 'Nota de Participação/Trabalho (0-10)',
  `exame_normal` decimal(4,2) DEFAULT NULL COMMENT 'Exame Normal (0-20)',
  `exame_recurso` decimal(4,2) DEFAULT NULL COMMENT 'Exame de Recurso (0-20)',
  `exame_especial` decimal(4,2) DEFAULT NULL COMMENT 'Exame Especial (0-20)',
  `exame_oral` decimal(4,2) DEFAULT NULL COMMENT 'Exame Oral (0-20)',
  `exame_escrito` decimal(4,2) DEFAULT NULL COMMENT 'Exame Escrito (0-20)',
  `media_parcial` decimal(4,2) DEFAULT NULL COMMENT '(MAC + NPT) / 2',
  `media_final` decimal(4,2) DEFAULT NULL COMMENT 'Média final após exames',
  `status` enum('aprovado','recuperacao','reprovado') DEFAULT NULL COMMENT 'Situação do aluno na disciplina',
  `data_lancamento` datetime DEFAULT NULL COMMENT 'Data do lançamento da nota',
  `lancado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que lançou',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nota_unica` (`estudante_id`,`disciplina_id`,`turma_id`,`bimestre`,`ano_letivo_id`),
  KEY `idx_notas_aluno` (`estudante_id`),
  KEY `idx_notas_disciplina` (`disciplina_id`),
  KEY `idx_notas_professor` (`professor_id`),
  KEY `idx_notas_turma` (`turma_id`),
  KEY `idx_notas_ano_letivo` (`ano_letivo_id`),
  KEY `idx_notas_escola` (`escola_id`),
  KEY `idx_notas_bimestre` (`bimestre`),
  KEY `idx_notas_status` (`status`),
  CONSTRAINT `fk_notas_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notas_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notas_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notas_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notas_professor` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notas_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela de notas dos alunos';

INSERT INTO `notas` VALUES('9','2','8','1','2',NULL,'3','1','9.00','6.00',NULL,NULL,NULL,NULL,NULL,NULL,'7.50','aprovado',NULL,NULL,'2026-04-22 04:13:41','2026-04-30 23:06:52');
INSERT INTO `notas` VALUES('10','2','8','1','2',NULL,'3','3','6.00','6.00',NULL,NULL,NULL,NULL,NULL,NULL,'6.00','aprovado',NULL,NULL,'2026-04-22 04:20:16','2026-04-23 01:14:17');
INSERT INTO `notas` VALUES('12','2','9','1','2',NULL,'3','3','6.00','4.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.00','recuperacao',NULL,NULL,'2026-04-23 01:11:08','2026-04-23 01:14:17');
INSERT INTO `notas` VALUES('13','2','10','1','2',NULL,'3','3','7.00','7.00',NULL,NULL,NULL,NULL,NULL,NULL,'7.00','aprovado',NULL,NULL,'2026-04-23 01:11:08','2026-04-23 01:14:17');
INSERT INTO `notas` VALUES('14','2','11','1','2',NULL,'3','3','2.00','2.00',NULL,NULL,NULL,NULL,NULL,NULL,'2.00','reprovado',NULL,NULL,'2026-04-23 01:11:08','2026-04-23 01:14:17');
INSERT INTO `notas` VALUES('15','2','9','1','2',NULL,'3','1','1.00','3.00',NULL,NULL,NULL,NULL,NULL,NULL,'2.00','reprovado',NULL,NULL,'2026-04-23 01:29:36','2026-04-30 23:06:52');
INSERT INTO `notas` VALUES('16','2','10','1','2',NULL,'3','1','6.00','5.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.50','aprovado',NULL,NULL,'2026-04-23 01:29:36','2026-04-30 23:06:52');
INSERT INTO `notas` VALUES('17','2','11','1','2',NULL,'3','1','7.00','3.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.00','recuperacao',NULL,NULL,'2026-04-23 01:29:36','2026-04-30 23:06:52');
INSERT INTO `notas` VALUES('18','2','12','1','2',NULL,'3','1','3.00','4.00',NULL,NULL,NULL,NULL,NULL,NULL,'3.50','reprovado',NULL,NULL,'2026-04-23 01:29:36','2026-04-30 23:06:52');
INSERT INTO `notas` VALUES('30','2','8','3','3',NULL,'3','1','5.00','6.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.50','aprovado',NULL,NULL,'2026-05-01 09:11:17','2026-05-01 09:11:50');
INSERT INTO `notas` VALUES('31','2','9','3','3',NULL,'3','1','4.00','2.00',NULL,NULL,NULL,NULL,NULL,NULL,'3.00','reprovado',NULL,NULL,'2026-05-01 09:11:17','2026-05-01 09:11:50');
INSERT INTO `notas` VALUES('32','2','10','3','3',NULL,'3','1','3.00','7.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.00','recuperacao',NULL,NULL,'2026-05-01 09:11:17','2026-05-01 09:11:50');
INSERT INTO `notas` VALUES('33','2','11','3','3',NULL,'3','1','8.00','9.00',NULL,NULL,NULL,NULL,NULL,NULL,'8.50','aprovado',NULL,NULL,'2026-05-01 09:11:17','2026-05-01 09:11:50');
INSERT INTO `notas` VALUES('34','2','12','3','3',NULL,'3','1','3.00','7.00',NULL,NULL,NULL,NULL,NULL,NULL,'5.00','recuperacao',NULL,NULL,'2026-05-01 09:11:17','2026-05-01 09:11:50');

DROP TABLE IF EXISTS `notificacoes`;
CREATE TABLE `notificacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `titulo` varchar(200) NOT NULL,
  `mensagem` text NOT NULL,
  `tipo` enum('info','sucesso','aviso','erro') DEFAULT 'info',
  `lida` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_lida` (`lida`),
  CONSTRAINT `notificacoes_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notificacoes_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `pagamentos`;
CREATE TABLE `pagamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `assinatura_id` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `referente` varchar(100) DEFAULT NULL,
  `metodo_pagamento` enum('dinheiro','transferencia','deposito','cartao','multicaixa','paypal') DEFAULT 'transferencia',
  `status` enum('pendente','pago','cancelado','reembolsado') DEFAULT 'pendente',
  `comprovante` varchar(255) DEFAULT NULL,
  `data_pagamento` date DEFAULT NULL,
  `data_vencimento` date DEFAULT NULL,
  `codigo_transacao` varchar(100) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `assinatura_id` (`assinatura_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `pagamentos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pagamentos_ibfk_2` FOREIGN KEY (`assinatura_id`) REFERENCES `assinaturas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pagamentos` VALUES('1','1','1','19900.00','May/2027','transferencia','pendente',NULL,NULL,'2027-05-09',NULL,NULL,'2026-04-09 20:28:38',NULL);
INSERT INTO `pagamentos` VALUES('2','1','1','19.00','Abril/2026','transferencia','pago','comp_1775765318_69d80746152ae.pdf','2026-04-09','2026-05-09','12','','2026-04-09 21:08:38',NULL);
INSERT INTO `pagamentos` VALUES('3','1','1','19.00','Abril/2026','transferencia','pago','comp_1775765540_69d80824298e0.pdf','2026-04-09','2026-05-09','12','','2026-04-09 21:12:20',NULL);
INSERT INTO `pagamentos` VALUES('4','2','2','19900.00','May/2027','transferencia','pendente',NULL,NULL,'2027-05-10',NULL,NULL,'2026-04-11 16:49:45',NULL);
INSERT INTO `pagamentos` VALUES('5','2','2','19.00','Abril/2026','dinheiro','pago','','2026-04-11','2026-05-11','','','2026-04-11 16:50:45',NULL);

DROP TABLE IF EXISTS `pagamentos_historico`;
CREATE TABLE `pagamentos_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `divida_id` int(11) NOT NULL COMMENT 'ID da dívida',
  `valor_pago` decimal(10,2) NOT NULL COMMENT 'Valor pago',
  `data_pagamento` date NOT NULL COMMENT 'Data do pagamento',
  `forma_pagamento` enum('transferencia','deposito','dinheiro','cheque','folha') DEFAULT NULL COMMENT 'Forma de pagamento',
  `observacao` text DEFAULT NULL COMMENT 'Observação',
  `comprovante` varchar(255) DEFAULT NULL COMMENT 'Caminho do comprovante',
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_divida` (`divida_id`),
  CONSTRAINT `pagamentos_historico_ibfk_1` FOREIGN KEY (`divida_id`) REFERENCES `dividas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pagamentos_historico` VALUES('1','1','150000.00','2026-04-27','transferencia','',NULL,'2026-04-27 18:21:11',NULL);

DROP TABLE IF EXISTS `paises`;
CREATE TABLE `paises` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `paises` VALUES('1','Angola','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('2','Portugal','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('3','Brasil','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('4','Cabo Verde','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('5','São Tomé e Príncipe','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('6','Moçambique','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('7','Guiné-Bissau','2026-04-11 16:13:58');
INSERT INTO `paises` VALUES('8','Timor-Leste','2026-04-11 16:13:58');

DROP TABLE IF EXISTS `papeis`;
CREATE TABLE `papeis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo` enum('super_admin','admin_escola','diretor','professor','secretaria','aluno','pai') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `papeis` VALUES('1','Super Administrador','Acesso total ao sistema','super_admin','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('2','Administrador Escola','Gerencia todas as funcionalidades da escola','admin_escola','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('3','Diretor','Gerencia a escola e visualiza relatórios','diretor','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('4','Professor','Lança notas e faz chamada','professor','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('5','Secretaria','Gerencia matrículas e documentos','secretaria','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('6','Aluno','Visualiza notas e frequência','aluno','2026-04-09 18:47:56');
INSERT INTO `papeis` VALUES('7','Pai/Encarregado','Acompanha o desempenho do aluno','pai','2026-04-09 18:47:56');

DROP TABLE IF EXISTS `papel_permissoes`;
CREATE TABLE `papel_permissoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `papel_id` int(11) NOT NULL,
  `permissao_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_papel_permissao` (`papel_id`,`permissao_id`),
  KEY `permissao_id` (`permissao_id`),
  CONSTRAINT `papel_permissoes_ibfk_1` FOREIGN KEY (`papel_id`) REFERENCES `papeis` (`id`) ON DELETE CASCADE,
  CONSTRAINT `papel_permissoes_ibfk_2` FOREIGN KEY (`permissao_id`) REFERENCES `permissoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `periodos`;
CREATE TABLE `periodos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL COMMENT 'Ex: 1º Bimestre, 2º Trimestre',
  `descricao` text DEFAULT NULL,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `ano_letivo` varchar(9) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola_periodos` (`escola_id`),
  KEY `idx_ano_letivo` (`ano_letivo`),
  CONSTRAINT `periodos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `permissoes`;
CREATE TABLE `permissoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `descricao` text DEFAULT NULL,
  `modulo` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissoes` VALUES('1','dashboard_ver','Visualizar Dashboard','dashboard','2026-04-09 18:47:55');
INSERT INTO `permissoes` VALUES('2','dashboard_estatisticas','Ver Estatísticas','dashboard','2026-04-09 18:47:55');
INSERT INTO `permissoes` VALUES('3','escolas_ver','Visualizar Escolas','escolas','2026-04-09 18:47:55');
INSERT INTO `permissoes` VALUES('4','escolas_cadastrar','Cadastrar Escolas','escolas','2026-04-09 18:47:55');
INSERT INTO `permissoes` VALUES('5','escolas_editar','Editar Escolas','escolas','2026-04-09 18:47:55');
INSERT INTO `permissoes` VALUES('6','escolas_excluir','Excluir Escolas','escolas','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('7','planos_ver','Visualizar Planos','planos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('8','planos_cadastrar','Cadastrar Planos','planos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('9','planos_editar','Editar Planos','planos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('10','planos_excluir','Excluir Planos','planos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('11','assinaturas_ver','Visualizar Assinaturas','assinaturas','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('12','assinaturas_renovar','Renovar Assinaturas','assinaturas','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('13','assinaturas_cancelar','Cancelar Assinaturas','assinaturas','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('14','pagamentos_ver','Visualizar Pagamentos','pagamentos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('15','pagamentos_registrar','Registrar Pagamentos','pagamentos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('16','pagamentos_editar','Editar Pagamentos','pagamentos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('17','pagamentos_excluir','Excluir Pagamentos','pagamentos','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('18','comunicacao_ver','Visualizar Comunicação','comunicacao','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('19','comunicacao_enviar','Enviar Comunicados','comunicacao','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('20','tickets_ver','Visualizar Tickets','tickets','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('21','tickets_responder','Responder Tickets','tickets','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('22','tickets_fechar','Fechar Tickets','tickets','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('23','relatorios_ver','Visualizar Relatórios','relatorios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('24','relatorios_exportar','Exportar Relatórios','relatorios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('25','config_ver','Visualizar Configurações','config','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('26','config_editar','Editar Configurações','config','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('27','permissoes_ver','Visualizar Permissões','config','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('28','permissoes_editar','Editar Permissões','config','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('29','usuarios_ver','Visualizar Usuários','usuarios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('30','usuarios_cadastrar','Cadastrar Usuários','usuarios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('31','usuarios_editar','Editar Usuários','usuarios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('32','usuarios_excluir','Excluir Usuários','usuarios','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('33','backup_ver','Visualizar Backups','backup','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('34','backup_criar','Criar Backups','backup','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('35','backup_restaurar','Restaurar Backups','backup','2026-04-09 18:47:56');
INSERT INTO `permissoes` VALUES('36','backup_excluir','Excluir Backups','backup','2026-04-09 18:47:56');

DROP TABLE IF EXISTS `permissoes_padrao`;
CREATE TABLE `permissoes_padrao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_usuario` varchar(50) NOT NULL,
  `modulo_id` int(11) NOT NULL,
  `permissao` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_permissao` (`tipo_usuario`,`modulo_id`,`permissao`),
  KEY `modulo_id` (`modulo_id`),
  CONSTRAINT `permissoes_padrao_ibfk_1` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_sistema` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `permissoes_usuario`;
CREATE TABLE `permissoes_usuario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `modulo_id` int(11) NOT NULL,
  `permissao` varchar(50) NOT NULL,
  `concedido` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_usuario_permissao` (`usuario_id`,`modulo_id`,`permissao`),
  KEY `modulo_id` (`modulo_id`),
  CONSTRAINT `permissoes_usuario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permissoes_usuario_ibfk_2` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_sistema` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissoes_usuario` VALUES('2','30','3','visualizar','1','2026-05-02 01:43:06');
INSERT INTO `permissoes_usuario` VALUES('3','30','3','exportar','1','2026-05-02 01:43:06');
INSERT INTO `permissoes_usuario` VALUES('4','30','3','imprimir','1','2026-05-02 01:43:06');
INSERT INTO `permissoes_usuario` VALUES('5','30','6','visualizar','1','2026-05-02 01:43:06');
INSERT INTO `permissoes_usuario` VALUES('6','30','6','exportar','1','2026-05-02 01:43:06');
INSERT INTO `permissoes_usuario` VALUES('7','30','6','imprimir','1','2026-05-02 01:43:06');

DROP TABLE IF EXISTS `plano_formacao_inscricoes`;
CREATE TABLE `plano_formacao_inscricoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `data_inscricao` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('inscrito','confirmado','concluido','desistente') DEFAULT 'inscrito',
  `nota_final` decimal(5,2) DEFAULT NULL,
  `certificado` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_inscricao` (`plano_id`,`funcionario_id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `plano_formacao_inscricoes_ibfk_1` FOREIGN KEY (`plano_id`) REFERENCES `planos_formacao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plano_formacao_inscricoes_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `planos`;
CREATE TABLE `planos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_mensal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `preco_anual` decimal(10,2) NOT NULL DEFAULT 0.00,
  `limite_alunos` int(11) DEFAULT 0,
  `limite_professores` int(11) DEFAULT 0,
  `limite_turmas` int(11) DEFAULT 0,
  `modulos_disponiveis` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`modulos_disponiveis`)),
  `recursos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recursos`)),
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `planos` VALUES('1','Básico','Plano ideal para pequenas escolas','19900.00','199000.00','100','10','10','[]','{\"suporte\":\"email\",\"armazenamento\":\"10\",\"relatorios_basicos\":false,\"relatorios_avancados\":false,\"api\":false,\"certificado_digital\":false}','ativo','2026-04-09 14:11:53','2026-04-09 18:04:32');
INSERT INTO `planos` VALUES('2','Profissional','Plano completo para escolas em crescimento','39900.00','399000.00','0','0','0',NULL,NULL,'ativo','2026-04-09 14:11:53',NULL);
INSERT INTO `planos` VALUES('3','Empresarial','Plano premium para grandes instituições','79900.00','799000.00','0','0','0',NULL,NULL,'ativo','2026-04-09 14:11:53',NULL);

DROP TABLE IF EXISTS `planos_formacao`;
CREATE TABLE `planos_formacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `objetivos` text DEFAULT NULL,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `carga_horaria` int(11) DEFAULT NULL,
  `local` varchar(200) DEFAULT NULL,
  `formador` varchar(150) DEFAULT NULL,
  `custo` decimal(10,2) DEFAULT NULL,
  `status` enum('planejado','em_andamento','concluido','cancelado') DEFAULT 'planejado',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_status` (`status`),
  KEY `idx_datas` (`data_inicio`,`data_fim`),
  CONSTRAINT `planos_formacao_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `presencas`;
CREATE TABLE `presencas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `matricula_id` int(11) NOT NULL,
  `data` date NOT NULL,
  `presente` tinyint(1) DEFAULT 1,
  `justificativa` text DEFAULT NULL,
  `tipo_falta` enum('justificada','injustificada') DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `matricula_id` (`matricula_id`),
  KEY `idx_data` (`data`),
  CONSTRAINT `presencas_ibfk_1` FOREIGN KEY (`matricula_id`) REFERENCES `matriculas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `presencas` VALUES('1','5','2026-05-02','0','','injustificada','2026-05-02 00:03:57');
INSERT INTO `presencas` VALUES('2','7','2026-05-02','1','','injustificada','2026-05-02 00:03:57');
INSERT INTO `presencas` VALUES('3','6','2026-05-02','1','','injustificada','2026-05-02 00:03:57');
INSERT INTO `presencas` VALUES('4','3','2026-05-02','1','','injustificada','2026-05-02 00:03:57');
INSERT INTO `presencas` VALUES('5','4','2026-05-02','1','','injustificada','2026-05-02 00:03:57');
INSERT INTO `presencas` VALUES('6','5','2026-05-03','1','','injustificada','2026-05-03 18:05:06');
INSERT INTO `presencas` VALUES('7','7','2026-05-03','1','','injustificada','2026-05-03 18:05:06');
INSERT INTO `presencas` VALUES('8','6','2026-05-03','1','','injustificada','2026-05-03 18:05:06');
INSERT INTO `presencas` VALUES('9','3','2026-05-03','1','','injustificada','2026-05-03 18:05:06');
INSERT INTO `presencas` VALUES('10','4','2026-05-03','1','','injustificada','2026-05-03 18:05:06');

DROP TABLE IF EXISTS `professor_disciplina_turma`;
CREATE TABLE `professor_disciplina_turma` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL COMMENT 'ID do professor (funcionarios.id)',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma',
  `dia_semana` enum('SEGUNDA','TERCA','QUARTA','QUINTA','SEXTA','SABADO') DEFAULT NULL,
  `horario_inicio` time DEFAULT NULL,
  `horario_fim` time DEFAULT NULL,
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `carga_horaria` int(11) DEFAULT 0 COMMENT 'Carga horária semanal',
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_professor_disciplina_turma` (`professor_id`,`disciplina_id`,`turma_id`,`ano_letivo_id`),
  KEY `idx_professor` (`professor_id`),
  KEY `idx_disciplina` (`disciplina_id`),
  KEY `idx_turma` (`turma_id`),
  KEY `idx_ano_letivo` (`ano_letivo_id`),
  CONSTRAINT `fk_pdt_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pdt_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pdt_professor` FOREIGN KEY (`professor_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pdt_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Associação de professores às disciplinas e turmas';

INSERT INTO `professor_disciplina_turma` VALUES('1','2','1','1',NULL,NULL,NULL,'3','4','1','2026-04-22 00:23:14','2026-04-22 00:23:14');
INSERT INTO `professor_disciplina_turma` VALUES('2','2','1','2',NULL,NULL,NULL,'3','4','1','2026-04-22 00:23:14','2026-04-22 00:23:14');
INSERT INTO `professor_disciplina_turma` VALUES('4','2','1','9',NULL,NULL,NULL,'3','4','1','2026-04-22 02:13:19','2026-04-22 02:13:19');
INSERT INTO `professor_disciplina_turma` VALUES('5','2','1','10',NULL,NULL,NULL,'3','4','1','2026-04-22 02:13:19','2026-04-22 02:13:19');
INSERT INTO `professor_disciplina_turma` VALUES('6','3','3','9',NULL,NULL,NULL,'3','4','1','2026-05-01 08:51:22','2026-05-01 08:51:22');
INSERT INTO `professor_disciplina_turma` VALUES('7','3','14','9',NULL,NULL,NULL,'3','4','1','2026-05-01 08:51:22','2026-05-01 08:51:22');
INSERT INTO `professor_disciplina_turma` VALUES('8','3','2','9',NULL,NULL,NULL,'3','4','1','2026-05-01 08:51:22','2026-05-01 08:51:22');

DROP TABLE IF EXISTS `professores`;
CREATE TABLE `professores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `especialidade` varchar(255) DEFAULT NULL,
  `formacao` text DEFAULT NULL,
  `data_admissao` date DEFAULT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `numero_processo` varchar(50) DEFAULT NULL,
  `bi_emissao` date DEFAULT NULL,
  `bi_validade` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_processo` (`numero_processo`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `professores_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `professores_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `professores_documentos`;
CREATE TABLE `professores_documentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL,
  `tipo_documento` varchar(50) NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL,
  `formato_papel` varchar(10) DEFAULT NULL,
  `tamanho_arquivo` int(11) DEFAULT NULL,
  `data_upload` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `professor_id` (`professor_id`),
  CONSTRAINT `professores_documentos_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `propostas_prova`;
CREATE TABLE `propostas_prova` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL COMMENT 'Professor que submeteu (funcionario)',
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `bimestre` int(11) NOT NULL,
  `tipo_prova` varchar(30) DEFAULT 'normal',
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `conteudo` text NOT NULL,
  `data_prevista` date NOT NULL,
  `duracao` int(11) DEFAULT 60,
  `peso` decimal(5,2) DEFAULT 10.00,
  `anexo` varchar(255) DEFAULT NULL,
  `status` enum('pendente','aprovado','reprovado','revisao') DEFAULT 'pendente',
  `parecer` text DEFAULT NULL,
  `aprovado_por` int(11) DEFAULT NULL COMMENT 'Funcionário que aprovou (coordenador)',
  `data_aprovacao` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  KEY `aprovado_por` (`aprovado_por`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_turma` (`turma_id`),
  KEY `idx_disciplina` (`disciplina_id`),
  KEY `idx_status` (`status`),
  KEY `idx_bimestre` (`bimestre`),
  KEY `idx_data_prevista` (`data_prevista`),
  CONSTRAINT `propostas_prova_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `propostas_prova_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `propostas_prova_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE,
  CONSTRAINT `propostas_prova_ibfk_4` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `propostas_prova_ibfk_5` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `propostas_prova_ibfk_6` FOREIGN KEY (`aprovado_por`) REFERENCES `funcionarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `propostas_prova` VALUES('1','2','2','3','9','1','1','normal','',NULL,'','2026-05-02','60','10.00',NULL,'pendente',NULL,'1','2026-04-29','2026-04-29 20:46:37','2026-04-29 20:46:37');
INSERT INTO `propostas_prova` VALUES('2','2','2','3','9','1','1','normal','normal','normal','normal','2026-04-08','60','20.00','','pendente',NULL,NULL,NULL,'2026-04-29 21:45:02','2026-04-29 21:45:43');

DROP TABLE IF EXISTS `propostas_prova1`;
CREATE TABLE `propostas_prova1` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `turma_id` int(11) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `bimestre` int(11) NOT NULL,
  `tipo_prova` varchar(30) DEFAULT 'normal',
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `conteudo` text NOT NULL,
  `data_prevista` date NOT NULL,
  `duracao` int(11) DEFAULT 60,
  `peso` decimal(5,2) DEFAULT 10.00,
  `anexo` varchar(255) DEFAULT NULL,
  `status` enum('pendente','aprovado','reprovado','revisao') DEFAULT 'pendente',
  `parecer` text DEFAULT NULL,
  `aprovado_por` int(11) DEFAULT NULL,
  `data_aprovacao` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `professor_id` (`professor_id`),
  KEY `escola_id` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  KEY `turma_id` (`turma_id`),
  KEY `disciplina_id` (`disciplina_id`),
  CONSTRAINT `propostas_prova1_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`),
  CONSTRAINT `propostas_prova1_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`),
  CONSTRAINT `propostas_prova1_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`),
  CONSTRAINT `propostas_prova1_ibfk_4` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`),
  CONSTRAINT `propostas_prova1_ibfk_5` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `provas`;
CREATE TABLE `provas` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Identificador único da prova',
  `turma_id` int(11) NOT NULL COMMENT 'ID da turma (FK para turmas)',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina (FK para disciplinas)',
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola (FK para escolas)',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo (FK para ano_letivo)',
  `tipo_prova` int(50) NOT NULL COMMENT 'Tipo da prova: prova_mensal, prova_trimestral, exame_normal, exame_recorrencia, trabalho, teste, avaliacao_continua, recuperacao',
  `titulo` varchar(200) NOT NULL COMMENT 'Título da prova',
  `descricao` text DEFAULT NULL COMMENT 'Descrição detalhada da prova',
  `periodo` varchar(20) NOT NULL COMMENT 'Período: 1º Bimestre, 2º Bimestre, 3º Bimestre, Exame',
  `data_prova` date NOT NULL COMMENT 'Data da realização da prova',
  `hora_inicio` time NOT NULL COMMENT 'Hora de início da prova',
  `hora_fim` time NOT NULL COMMENT 'Hora de término da prova',
  `valor_total` decimal(5,2) NOT NULL DEFAULT 20.00 COMMENT 'Valor total da prova (ex: 20 valores)',
  `sala` varchar(50) DEFAULT NULL COMMENT 'Sala ou local da prova',
  `instrucoes` text DEFAULT NULL COMMENT 'Instruções específicas para os alunos',
  `material_permitido` varchar(255) DEFAULT NULL COMMENT 'Material permitido na prova (calculadora, régua, etc.)',
  `docente_responsavel` int(100) DEFAULT NULL COMMENT 'Nome do docente responsável pela prova',
  `status` enum('agendada','publicada','realizada','cancelada') DEFAULT 'agendada' COMMENT 'Status da prova',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data de criação do registro',
  `data_publicacao` datetime DEFAULT NULL COMMENT 'Data em que a prova foi publicada',
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'Data da última atualização',
  `criado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que criou o registro (FK para usuarios)',
  `atualizado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que atualizou o registro (FK para usuarios)',
  PRIMARY KEY (`id`),
  KEY `criado_por` (`criado_por`),
  KEY `atualizado_por` (`atualizado_por`),
  KEY `idx_turma` (`turma_id`),
  KEY `idx_disciplina` (`disciplina_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_ano_letivo` (`ano_letivo_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data_prova` (`data_prova`),
  KEY `idx_periodo` (`periodo`),
  KEY `idx_tipo_prova` (`tipo_prova`),
  KEY `idx_status_data` (`status`,`data_prova`),
  KEY `idx_turma_periodo` (`turma_id`,`periodo`),
  KEY `idx_escola_ano` (`escola_id`,`ano_letivo_id`),
  KEY `fk_provas_docente_responsavel` (`docente_responsavel`),
  CONSTRAINT `fk_provas_docente_responsavel` FOREIGN KEY (`docente_responsavel`) REFERENCES `funcionarios` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_provas_prova_id` FOREIGN KEY (`tipo_prova`) REFERENCES `tipos_avaliacao` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `provas_ibfk_1` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `provas_ibfk_2` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `provas_ibfk_3` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `provas_ibfk_4` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `provas_ibfk_5` FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `provas_ibfk_6` FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela de provas e avaliações agendadas';

INSERT INTO `provas` VALUES('1','1','1','2','3','1','Prova Trimestral de Matemática','Avaliação dos conteúdos do 1º bimestre','1º Bimestre','2024-04-15','08:00:00','10:00:00','20.00','Sala 3','Leia atentamente todas as questões antes de responder.','Calculadora simples, régua','1','agendada','2026-05-03 22:00:31',NULL,'2026-05-04 00:05:27',NULL,NULL);
INSERT INTO `provas` VALUES('2','9','3','2','3','1','Primeira prova','','1º Bimestre','2026-05-03','01:01:00','01:02:00','20.00','Sala 6','','','2','publicada','2026-05-03 22:37:01','2026-05-04 01:18:48','2026-05-04 01:18:48',NULL,NULL);
INSERT INTO `provas` VALUES('3','9','3','2','3','1','Primeira prova','','1º Bimestre','2026-05-03','01:01:00','01:02:00','20.00','Sala 6','','','2','cancelada','2026-05-03 22:47:54','2026-05-03 22:58:14','2026-05-04 01:11:22',NULL,NULL);
INSERT INTO `provas` VALUES('5','9','1','2','3','1','Nota de Avaliacao continua','','1º Bimestre','2026-05-04','00:00:00','00:07:00','10.00','Sala 6','','','2','publicada','2026-05-04 00:53:00','2026-05-04 01:17:21','2026-05-04 01:17:21',NULL,NULL);

DROP TABLE IF EXISTS `recebimentos_historico`;
CREATE TABLE `recebimentos_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `divida_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `valor_recebido` decimal(10,2) NOT NULL,
  `data_recebimento` date NOT NULL,
  `forma_recebimento` enum('transferencia','deposito','dinheiro','cheque','compensacao','automatico') DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `comprovante` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_divida` (`divida_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  CONSTRAINT `recebimentos_historico_ibfk_1` FOREIGN KEY (`divida_id`) REFERENCES `dividas_a_receber` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recebimentos_historico_ibfk_2` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `recuperacao_senha`;
CREATE TABLE `recuperacao_senha` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expira` datetime NOT NULL,
  `usado` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_token` (`token`),
  CONSTRAINT `recuperacao_senha_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `resumo_frequencia_trimestre`;
CREATE TABLE `resumo_frequencia_trimestre` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL COMMENT 'ID da escola',
  `ano_letivo_id` int(11) NOT NULL COMMENT 'ID do ano letivo',
  `estudante_id` int(11) NOT NULL COMMENT 'ID do aluno',
  `disciplina_id` int(11) NOT NULL COMMENT 'ID da disciplina',
  `trimestre` tinyint(1) NOT NULL COMMENT '1, 2, 3',
  `total_aulas` int(11) DEFAULT 0 COMMENT 'Total de aulas no trimestre',
  `total_presencas` int(11) DEFAULT 0 COMMENT 'Total de presenças',
  `total_faltas` int(11) DEFAULT 0 COMMENT 'Total de faltas',
  `total_faltas_justificadas` int(11) DEFAULT 0 COMMENT 'Total de faltas justificadas',
  `percentual_frequencia` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentual de frequência',
  `status` enum('aprovado','reprovado_por_frequencia','recuperacao') DEFAULT 'aprovado' COMMENT 'Status por frequência',
  `observacao` text DEFAULT NULL COMMENT 'Observações',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resumo_unico` (`estudante_id`,`disciplina_id`,`trimestre`),
  KEY `idx_resumo_aluno` (`estudante_id`),
  KEY `idx_resumo_disciplina` (`disciplina_id`),
  KEY `idx_resumo_escola` (`escola_id`),
  KEY `idx_resumo_ano_letivo` (`ano_letivo_id`),
  CONSTRAINT `fk_resumo_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumo de frequência por trimestre';


DROP TABLE IF EXISTS `reunioes_coordenacao`;
CREATE TABLE `reunioes_coordenacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_reuniao` datetime NOT NULL,
  `duracao` int(11) DEFAULT 60,
  `local` varchar(100) DEFAULT NULL,
  `participantes` text DEFAULT NULL,
  `pauta` text DEFAULT NULL,
  `ata` text DEFAULT NULL,
  `status` enum('agendada','realizada','cancelada') DEFAULT 'agendada',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `reunioes_coordenacao_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reunioes_coordenacao_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reunioes_coordenacao` VALUES('1','2','dsetgfd','fxdgcfvgfv','2026-04-21 19:24:00','60','dxsfxdfx','fdgftf,sdffdcf','','esdfgvyhjgjyjh','realizada','3','2026-04-19 19:24:26');

DROP TABLE IF EXISTS `rh_cargos`;
CREATE TABLE `rh_cargos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `salario_base` decimal(15,2) NOT NULL,
  `bonus_fixo` decimal(15,2) DEFAULT 0.00,
  `vale_transporte` decimal(15,2) DEFAULT 0.00,
  `vale_refeicao` decimal(15,2) DEFAULT 0.00,
  `auxilio_saude` decimal(15,2) DEFAULT 0.00,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `rh_cargos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rh_cargos` VALUES('2','2','Direitor','','100000.00','0.00','0.00','0.00','0.00','ativo','2026-04-16 02:58:30');

DROP TABLE IF EXISTS `rh_configuracoes`;
CREATE TABLE `rh_configuracoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `parametro` varchar(100) NOT NULL,
  `valor` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_parametro` (`escola_id`,`parametro`),
  CONSTRAINT `rh_configuracoes_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rh_configuracoes` VALUES('1','1','salario_minimo','100000','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('2','2','salario_minimo','100000','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('4','1','subsidio_transporte','5000','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('5','2','subsidio_transporte','5000','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('7','1','subsidio_alimentacao','2500','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('8','2','subsidio_alimentacao','2500','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('10','1','dias_ferias','22','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('11','2','dias_ferias','22','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('13','1','decimo_terceiro','sim','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('14','2','decimo_terceiro','sim','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('16','1','ferias_proporcionais','sim','2026-04-16 09:24:02','2026-04-16 09:24:02');
INSERT INTO `rh_configuracoes` VALUES('17','2','ferias_proporcionais','sim','2026-04-16 09:24:02','2026-04-16 09:24:02');

DROP TABLE IF EXISTS `rh_documentos`;
CREATE TABLE `rh_documentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `tipo` enum('contrato','declaracao','avaliacao','formacao','comunicado','regulamento','outro') NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `descricao` text DEFAULT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL,
  `data_upload` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_categoria` (`categoria`),
  CONSTRAINT `rh_documentos_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `rh_folha_itens`;
CREATE TABLE `rh_folha_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `folha_id` int(11) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `cargo_id` int(11) DEFAULT NULL,
  `salario_base` decimal(15,2) NOT NULL,
  `dias_trabalhados` int(11) DEFAULT 30,
  `faltas` int(11) DEFAULT 0,
  `horas_extras_50` decimal(10,2) DEFAULT 0.00,
  `horas_extras_100` decimal(10,2) DEFAULT 0.00,
  `adicional_noturno` decimal(15,2) DEFAULT 0.00,
  `bonus` decimal(15,2) DEFAULT 0.00,
  `vale_transporte` decimal(15,2) DEFAULT 0.00,
  `vale_refeicao` decimal(15,2) DEFAULT 0.00,
  `auxilio_saude` decimal(15,2) DEFAULT 0.00,
  `inss` decimal(15,2) DEFAULT 0.00,
  `irrf` decimal(15,2) DEFAULT 0.00,
  `outros_descontos` decimal(15,2) DEFAULT 0.00,
  `total_proventos` decimal(15,2) DEFAULT 0.00,
  `total_descontos` decimal(15,2) DEFAULT 0.00,
  `valor_liquido` decimal(15,2) DEFAULT 0.00,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `cargo_id` (`cargo_id`),
  KEY `idx_folha` (`folha_id`),
  KEY `idx_funcionario` (`funcionario_id`),
  CONSTRAINT `rh_folha_itens_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_folha_itens_ibfk_2` FOREIGN KEY (`folha_id`) REFERENCES `rh_folhas_pagamento` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_folha_itens_ibfk_3` FOREIGN KEY (`funcionario_id`) REFERENCES `rh_funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_folha_itens_ibfk_4` FOREIGN KEY (`cargo_id`) REFERENCES `rh_cargos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `rh_folhas_pagamento`;
CREATE TABLE `rh_folhas_pagamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `competencia` date NOT NULL,
  `data_processamento` datetime NOT NULL,
  `total_bruto` decimal(15,2) DEFAULT 0.00,
  `total_descontos` decimal(15,2) DEFAULT 0.00,
  `total_liquido` decimal(15,2) DEFAULT 0.00,
  `total_funcionarios` int(11) DEFAULT 0,
  `status` enum('processado','pago','cancelado') DEFAULT 'processado',
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_competencia` (`competencia`,`escola_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_competencia` (`competencia`),
  CONSTRAINT `rh_folhas_pagamento_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_folhas_pagamento_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rh_folhas_pagamento` VALUES('1','2','0000-00-00','2026-04-16 03:42:18','0.00','0.00','0.00','0','processado','3','2026-04-16 03:42:18');

DROP TABLE IF EXISTS `rh_funcionarios`;
CREATE TABLE `rh_funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `cargo_id` int(11) DEFAULT NULL,
  `numero_funcionario` varchar(20) NOT NULL,
  `data_admissao` date NOT NULL,
  `data_demissao` date DEFAULT NULL,
  `tipo_contrato` enum('CLT','PJ','Estagio','Temporario') DEFAULT 'CLT',
  `salario_contratual` decimal(15,2) NOT NULL,
  `banco` varchar(100) DEFAULT NULL,
  `agencia` varchar(20) DEFAULT NULL,
  `conta_bancaria` varchar(50) DEFAULT NULL,
  `pix` varchar(100) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('ativo','inativo','ferias','licenca') DEFAULT 'ativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_numero_funcionario` (`numero_funcionario`),
  KEY `usuario_id` (`usuario_id`),
  KEY `cargo_id` (`cargo_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `rh_funcionarios_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_funcionarios_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rh_funcionarios_ibfk_3` FOREIGN KEY (`cargo_id`) REFERENCES `rh_cargos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rh_funcionarios` VALUES('1','2','11','2','343','2026-04-16',NULL,'Estagio','10000.00','','','','','','ativo','2026-04-16 16:22:44','2026-04-16 16:22:44');

DROP TABLE IF EXISTS `salas`;
CREATE TABLE `salas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL COMMENT 'Nome/Identificação da sala',
  `codigo` varchar(20) DEFAULT NULL COMMENT 'Código da sala (ex: S101, LAB01)',
  `tipo` enum('comum','laboratorio','auditorio','biblioteca','quadra','oficina','outro') DEFAULT 'comum' COMMENT 'Tipo da sala',
  `capacidade` int(11) DEFAULT 0 COMMENT 'Capacidade máxima de alunos',
  `localizacao` varchar(100) DEFAULT NULL COMMENT 'Localização (bloco, andar)',
  `bloco` varchar(50) DEFAULT NULL COMMENT 'Bloco do prédio',
  `andar` int(11) DEFAULT NULL COMMENT 'Número do andar',
  `recursos` text DEFAULT NULL COMMENT 'Recursos disponíveis (projetor, ar-condicionado, etc)',
  `responsavel` varchar(100) DEFAULT NULL COMMENT 'Responsável pela sala',
  `telefone_ramal` varchar(20) DEFAULT NULL,
  `escola_id` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sala_codigo` (`codigo`),
  KEY `idx_salas_status` (`status`),
  KEY `idx_salas_tipo` (`tipo`),
  KEY `idx_salas_escola` (`escola_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Salas de aula';

INSERT INTO `salas` VALUES('1','Sala 01','S101','comum','35','Bloco A - Térreo','A','1','Quadro branco, carteiras, ventilador',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('2','Sala 02','S102','comum','35','Bloco A - Térreo','A','1','Quadro branco, carteiras, ventilador',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('3','Sala 03','S103','comum','40','Bloco A - 1º Andar','A','2','Quadro branco, carteiras, ar-condicionado',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('4','Sala 04','S104','comum','40','Bloco A - 1º Andar','A','2','Quadro branco, carteiras, ar-condicionado',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('5','Laboratório 01','LAB01','laboratorio','25','Bloco B - Térreo','B','1','Computadores, projetor, ar-condicionado',NULL,'2','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('6','Laboratório 02','LAB02','laboratorio','25','Bloco B - Térreo','B','1','Computadores, projetor, ar-condicionado',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('7','Auditório','AUD01','auditorio','150','Bloco Central','C','1','Palco, projetor, som, ar-condicionado',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('8','Biblioteca','BIB01','biblioteca','80','Bloco Central','C','2','Estantes, mesas de estudo, computadores',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('9','Quadra','QUA01','quadra','100','Bloco D','D','0','Quadra poliesportiva, vestiários',NULL,NULL,'2','1','2026-04-18 09:08:19','2026-04-18 19:42:09');
INSERT INTO `salas` VALUES('10','Sala de Artes','ART01','oficina','30','Bloco E','E','1','Material de arte, pias, cavaletes',NULL,NULL,'2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `salas` VALUES('11','Sala 5','SALA001','comum','30','Ala Norte','Bloco A / 1º Andar / Bloco A - Térreo',NULL,'projector , computadores','João Paulo','943911384','2','1','2026-04-18 19:45:02','2026-04-18 19:45:41');

DROP TABLE IF EXISTS `solicitacoes_ferias`;
CREATE TABLE `solicitacoes_ferias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `dias_solicitados` int(11) NOT NULL,
  `dias_uteis` int(11) DEFAULT NULL,
  `dias_calendario` int(11) DEFAULT NULL,
  `motivo` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `periodo_referencia` varchar(20) DEFAULT NULL,
  `documento_anexo` varchar(255) DEFAULT NULL,
  `carta_gerada` tinyint(4) DEFAULT 0,
  `carta_path` varchar(255) DEFAULT NULL,
  `status` enum('pendente','aprovado','reprovado','cancelado') DEFAULT 'pendente',
  `aprovado_por` int(11) DEFAULT NULL,
  `data_aprovacao` date DEFAULT NULL,
  `observacao_admin` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_datas` (`data_inicio`,`data_fim`),
  KEY `idx_escola` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  KEY `aprovado_por` (`aprovado_por`),
  CONSTRAINT `solicitacoes_ferias_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitacoes_ferias_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitacoes_ferias_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitacoes_ferias_ibfk_4` FOREIGN KEY (`aprovado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `solicitacoes_vale`;
CREATE TABLE `solicitacoes_vale` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL COMMENT 'ID do funcionário solicitante',
  `escola_id` int(11) NOT NULL,
  `ano_letivo_id` int(11) NOT NULL,
  `valor_solicitado` decimal(10,2) NOT NULL COMMENT 'Valor solicitado',
  `valor_aprovado` decimal(10,2) DEFAULT NULL COMMENT 'Valor aprovado',
  `motivo` varchar(255) NOT NULL COMMENT 'Motivo da solicitação',
  `descricao` text DEFAULT NULL COMMENT 'Descrição detalhada',
  `data_solicitacao` date DEFAULT curdate(),
  `data_necessidade` date DEFAULT NULL COMMENT 'Data em que precisa do valor',
  `data_aprovacao` date DEFAULT NULL,
  `data_pagamento` date DEFAULT NULL,
  `parcelas` int(11) DEFAULT 1 COMMENT 'Número de parcelas para desconto',
  `valor_parcela` decimal(10,2) GENERATED ALWAYS AS (`valor_aprovado` / `parcelas`) STORED,
  `status` enum('pendente','aprovado','reprovado','pago','cancelado') DEFAULT 'pendente',
  `observacao_aprovador` text DEFAULT NULL,
  `comprovante` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `carta_gerada` tinyint(1) DEFAULT 0 COMMENT 'Carta modelo gerada',
  `carta_path` varchar(500) DEFAULT NULL COMMENT 'Caminho da carta gerada',
  `divida_id` int(11) DEFAULT NULL COMMENT 'ID da dívida gerada após aprovação',
  `documento_anexo` varchar(255) DEFAULT NULL,
  `forma_recebimento` varchar(50) DEFAULT 'transferencia',
  PRIMARY KEY (`id`),
  KEY `idx_funcionario` (`funcionario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data` (`data_solicitacao`),
  KEY `escola_id` (`escola_id`),
  KEY `ano_letivo_id` (`ano_letivo_id`),
  KEY `idx_divida` (`divida_id`),
  CONSTRAINT `solicitacoes_vale_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitacoes_vale_ibfk_2` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitacoes_vale_ibfk_3` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `ticket_respostas`;
CREATE TABLE `ticket_respostas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `mensagem` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `ticket_respostas_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets_suporte` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_respostas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `tickets_suporte`;
CREATE TABLE `tickets_suporte` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `assunto` varchar(200) NOT NULL,
  `mensagem` text NOT NULL,
  `prioridade` enum('baixa','media','alta','urgente') DEFAULT 'media',
  `status` enum('aberto','em_andamento','respondido','fechado') DEFAULT 'aberto',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_escola` (`escola_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `tickets_suporte_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_suporte_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `tipos_avaliacao`;
CREATE TABLE `tipos_avaliacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL COMMENT 'Nome do tipo de avaliação',
  `codigo` varchar(50) DEFAULT NULL COMMENT 'Código identificador',
  `categoria` enum('prova','trabalho','teste','exame','atividade') NOT NULL,
  `nivel_ensino` varchar(20) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `peso_padrao` decimal(5,2) DEFAULT 10.00 COMMENT 'Peso padrão da avaliação',
  `escala_maxima` int(11) DEFAULT 20,
  `cor` varchar(7) DEFAULT '#006B3E' COMMENT 'Cor para identificação visual',
  `icone` varchar(50) DEFAULT 'fa-file-alt' COMMENT 'Ícone Font Awesome',
  `ordem` int(11) DEFAULT 0 COMMENT 'Ordem de exibição',
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `escola_id` int(11) NOT NULL,
  `data_criacao` datetime DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_status` (`status`),
  KEY `idx_ordem` (`ordem`),
  CONSTRAINT `tipos_avaliacao_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tipos_avaliacao` VALUES('1','Mac','TRAB151','trabalho','1ciclo','','10.00','10','#006b3e','fa-tasks','1','ativo','2','2026-05-03 23:42:20',NULL);

DROP TABLE IF EXISTS `tipos_falta`;
CREATE TABLE `tipos_falta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL COMMENT 'Nome do tipo de falta',
  `descricao` text DEFAULT NULL COMMENT 'Descrição',
  `percentual_desconto` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentual de desconto na avaliação',
  `justificavel` tinyint(1) DEFAULT 1 COMMENT 'Pode ser justificada?',
  `prazo_justificacao_dias` int(11) DEFAULT 5 COMMENT 'Prazo para justificar (dias)',
  `status` tinyint(1) DEFAULT 1 COMMENT 'Ativo/Inativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tipos de falta conforme legislação angolana';

INSERT INTO `tipos_falta` VALUES('1','Falta Normal','Falta sem justificação','0.00','1','5','1','2026-04-18 17:02:40');
INSERT INTO `tipos_falta` VALUES('2','Falta Justificada','Falta com comprovativo médico ou outro','0.00','1','10','1','2026-04-18 17:02:40');
INSERT INTO `tipos_falta` VALUES('3','Falta Grave','Falta sem justificação por mais de 3 dias consecutivos','5.00','0','0','1','2026-04-18 17:02:40');
INSERT INTO `tipos_falta` VALUES('4','Atraso','Chegada após o horário estabelecido','0.00','1','1','1','2026-04-18 17:02:40');
INSERT INTO `tipos_falta` VALUES('5','Dispensa','Afastamento autorizado pela direção','0.00','1','15','1','2026-04-18 17:02:40');
INSERT INTO `tipos_falta` VALUES('6','Falta por Luto','Falta por falecimento de familiar direto','0.00','1','30','1','2026-04-18 17:02:40');

DROP TABLE IF EXISTS `turmas`;
CREATE TABLE `turmas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `ano` varchar(20) NOT NULL,
  `turno` enum('manha','tarde','noite') NOT NULL,
  `ano_letivo` year(4) NOT NULL,
  `capacidade` int(11) DEFAULT 30,
  `sala` varchar(20) DEFAULT NULL,
  `status` enum('ativa','encerrada') DEFAULT 'ativa',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_escola_ano` (`escola_id`,`ano_letivo`),
  CONSTRAINT `turmas_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `turmas` VALUES('1','1','6ª Classe A','6ª Classe','manha','2026','40','Sala 101','ativa','2026-04-11 16:14:58','2026-04-11 16:19:09');
INSERT INTO `turmas` VALUES('2','1','6ª Classe B','6ª Classe','tarde','2026','40','Sala 102','ativa','2026-04-11 16:14:58','2026-04-11 16:19:09');
INSERT INTO `turmas` VALUES('3','1','7ª Classe A','7ª Classe','manha','2025','40','Sala 201','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('4','1','8ª Classe A','8ª Classe','manha','2025','35','Sala 202','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('5','1','9ª Classe A','9ª Classe','tarde','2025','35','Sala 301','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('6','1','10ª Classe A','10ª Classe','manha','2025','30','Sala 401','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('7','1','11ª Classe A','11ª Classe','manha','2025','30','Sala 402','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('8','1','12ª Classe A','12ª Classe','manha','2025','30','Sala 501','ativa','2026-04-11 16:14:58',NULL);
INSERT INTO `turmas` VALUES('9','2','5º ATA/2025-2026','5ª Classe','tarde','2026','30','Sala 6','ativa','2026-04-11 16:22:01','2026-04-18 20:15:10');
INSERT INTO `turmas` VALUES('10','2','6ª ATA/2025-2026','6','manha','2025','30','Sala 6','ativa','2026-04-21 17:13:16',NULL);

DROP TABLE IF EXISTS `turnos`;
CREATE TABLE `turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL COMMENT 'Nome do turno (Manhã, Tarde, Noite, Integral)',
  `sigla` varchar(10) NOT NULL COMMENT 'Sigla do turno (M, T, N, I)',
  `horario_inicio` time DEFAULT NULL COMMENT 'Horário de início das aulas',
  `horario_fim` time DEFAULT NULL COMMENT 'Horário de término das aulas',
  `duracao_aula` int(11) DEFAULT 50 COMMENT 'Duração da aula em minutos',
  `intervalo_inicio` time DEFAULT NULL COMMENT 'Início do intervalo',
  `intervalo_fim` time DEFAULT NULL COMMENT 'Fim do intervalo',
  `dias_semana` varchar(50) DEFAULT 'SEG,TER,QUA,QUI,SEX' COMMENT 'Dias letivos',
  `escola_id` int(11) DEFAULT NULL COMMENT 'ID da escola (multiescola)',
  `status` tinyint(1) DEFAULT 1 COMMENT '1-Ativo, 0-Inativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_turnos_status` (`status`),
  KEY `idx_turnos_escola` (`escola_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Turnos de estudo';

INSERT INTO `turnos` VALUES('2','Tarde','T','13:00:00','17:30:00','50','15:00:00','15:30:00','SEG,TER,QUA,QUI,SEX','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `turnos` VALUES('3','Noite','N','18:30:00','22:00:00','50','20:00:00','20:30:00','SEG,TER,QUA,QUI,SEX','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `turnos` VALUES('4','Integral','I','07:30:00','17:30:00','50','10:00:00','10:30:00','SEG,TER,QUA,QUI,SEX','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `turnos` VALUES('5','Fim de Semana','FS','08:00:00','12:00:00','60',NULL,NULL,'SEG,TER,QUA,QUI,SEX','2','1','2026-04-18 09:08:19',NULL);
INSERT INTO `turnos` VALUES('6','Manhã','M','07:00:00','12:00:00','50','10:00:00','10:15:00','SEG,TER,QUA,QUI,SEX','2','1','2026-04-19 19:19:23',NULL);

DROP TABLE IF EXISTS `tutoriais`;
CREATE TABLE `tutoriais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `descricao` text NOT NULL,
  `categoria` enum('sistema','notas','matricula','financeiro','relatorios','perfil') DEFAULT 'sistema',
  `nivel` enum('iniciante','intermediario','avancado') DEFAULT 'iniciante',
  `url_video` varchar(500) NOT NULL,
  `embed_url` varchar(500) DEFAULT NULL,
  `plataforma` enum('youtube','vimeo','outro') DEFAULT 'youtube',
  `video_id` varchar(100) DEFAULT NULL,
  `duracao` varchar(20) DEFAULT NULL,
  `visualizacoes` int(11) DEFAULT 0,
  `destaque` tinyint(4) DEFAULT 0,
  `ordem` int(11) DEFAULT 0,
  `ativo` tinyint(4) DEFAULT 1,
  `escola_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_nivel` (`nivel`),
  KEY `idx_ativo` (`ativo`),
  KEY `idx_destaque` (`destaque`),
  KEY `idx_visualizacoes` (`visualizacoes`),
  CONSTRAINT `tutoriais_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tutoriais` VALUES('1','Introdução ao Sistema SIGE','Conheça as principais funcionalidades do sistema de gestão escolar','sistema','iniciante','https://www.youtube.com/watch?v=dQw4w9WgXcQ','https://www.youtube.com/embed/dQw4w9WgXcQ','youtube','dQw4w9WgXcQ','5:30','1','1','0','1','2','2026-05-06 23:59:11','2026-05-07 00:08:30');
INSERT INTO `tutoriais` VALUES('2','Como Lançar Notas','Aprenda passo a passo como lançar notas dos alunos','notas','iniciante','https://www.youtube.com/watch?v=dQw4w9WgXcQ','https://www.youtube.com/embed/dQw4w9WgXcQ','youtube','dQw4w9WgXcQ','8:15','0','1','0','1','2','2026-05-06 23:59:11','2026-05-07 00:02:02');
INSERT INTO `tutoriais` VALUES('3','Gerenciar Matrículas','Como realizar matrículas e rematrículas de alunos','matricula','intermediario','https://www.youtube.com/watch?v=dQw4w9WgXcQ','https://www.youtube.com/embed/dQw4w9WgXcQ','youtube','dQw4w9WgXcQ','12:00','0','0','0','1','2','2026-05-06 23:59:11','2026-05-07 00:02:02');
INSERT INTO `tutoriais` VALUES('4','Administração Financeira','Gestão de pagamentos, recibos e relatórios financeiros','financeiro','avancado','https://www.youtube.com/watch?v=dQw4w9WgXcQ','https://www.youtube.com/embed/dQw4w9WgXcQ','youtube','dQw4w9WgXcQ','15:45','0','0','0','1','2','2026-05-06 23:59:11','2026-05-07 00:02:02');
INSERT INTO `tutoriais` VALUES('5','Emissão de Relatórios','Como gerar boletins e relatórios gerenciais','relatorios','intermediario','https://www.youtube.com/watch?v=dQw4w9WgXcQ','https://www.youtube.com/embed/dQw4w9WgXcQ','youtube','dQw4w9WgXcQ','10:20','1','0','0','1','2','2026-05-06 23:59:11','2026-05-07 00:03:07');

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `tipo` enum('super_admin','admin_escola','diretor','professor','secretaria','aluno','pai','funcionario') NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `bi` varchar(20) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `genero` enum('M','F') DEFAULT NULL,
  `status` enum('ativo','inativo','bloqueado') DEFAULT 'ativo',
  `ultimo_acesso` datetime DEFAULT NULL,
  `ultimo_ip` varchar(45) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `permissoes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissoes`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_escola` (`escola_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `usuarios` VALUES('1',NULL,'João Francisco Morais Paulo','joaofranciscompaulo@gmail.com','$2y$10$HNIf.zpIgP51R2l1ldvtEuW.5OppwaZEZ1rzo0dDzC2Kt25YLFoji','super_admin',NULL,NULL,NULL,NULL,NULL,NULL,'ativo','2026-05-02 00:01:45','127.0.0.1',NULL,NULL,'2026-04-09 14:14:41','2026-05-02 00:01:45');
INSERT INTO `usuarios` VALUES('2','1','João Paulo','joaofranciscompaulo94@gmail.com','$2y$10$IMYDy/zWu0tBqCIXSxIwtOBzFDg7QLYy/FcvJCslghuBaMSbeZHhq','admin_escola',NULL,NULL,NULL,NULL,NULL,NULL,'ativo','2026-04-27 14:21:50','::1',NULL,NULL,'2026-04-09 16:05:43','2026-04-27 14:21:50');
INSERT INTO `usuarios` VALUES('3','2','Armanda Pombal','armandapombal@gmail.com','$2y$10$hl1oQh5aFglr9wk.FXIT3uMN6PKrxuZ77u6MVP.fF3NTyMUnwHR7G','admin_escola',NULL,NULL,'usuario_3_1778104217.jpeg',NULL,NULL,NULL,'ativo','2026-05-07 22:41:49','127.0.0.1',NULL,NULL,'2026-04-10 21:37:14','2026-05-07 22:41:49');
INSERT INTO `usuarios` VALUES('11','2','Osvaldo Paulo','FUNC/2/2026/0001@sige.ao','$2y$10$2OHRTED6BwqJPDSRP2O6ceyW1gLkqfXspy9BNTEpEWg.NhHd7UTAi','funcionario','943911384',NULL,NULL,NULL,NULL,NULL,'ativo','2026-04-28 00:38:31','127.0.0.1',NULL,NULL,'2026-04-16 11:25:46','2026-04-28 00:38:31');
INSERT INTO `usuarios` VALUES('12','2','Tatiana Paulo','tatianapaulo@sige.ao','$2y$10$lD.Ddc/KcbIt50wWDjKyOOekm5aVinF53RGXtWbFQfMgMamYaQrLu','professor','943911384',NULL,NULL,NULL,NULL,NULL,'ativo','2026-05-07 22:33:05','::1',NULL,NULL,'2026-04-16 22:48:40','2026-05-07 22:33:05');
INSERT INTO `usuarios` VALUES('28','2','Manuel Pedro','2026/002/00001@aluno.sige.ao','$2y$10$HN94AVzG5ARrTYV032vtA.4vEyFUN5dgW8O6n8mV2flnnR3PKDh1S','aluno','',NULL,NULL,NULL,NULL,NULL,'ativo',NULL,NULL,NULL,NULL,'2026-04-18 13:35:52',NULL);
INSERT INTO `usuarios` VALUES('29','2','Maria Paulo','2026/002/00002@aluno.sige.ao','$2y$10$VYu3kzTmYca1qSOnAyerDe5DKRmRDEnNgU6BCGoTz.FwNVmnEPiUW','aluno','',NULL,NULL,NULL,NULL,NULL,'ativo',NULL,NULL,NULL,NULL,'2026-04-23 01:02:38','2026-04-23 01:22:35');
INSERT INTO `usuarios` VALUES('30','2','Alice Ribeiro','2026/002/00003@aluno.sige.ao','$2y$10$3Guc5A0ZNbuaMhs8nwLoweyJkJIA/S3chTmaHPK7esA6nO/QpR/52','aluno','',NULL,NULL,NULL,NULL,NULL,'ativo',NULL,NULL,NULL,NULL,'2026-04-23 01:03:18','2026-04-23 01:21:12');
INSERT INTO `usuarios` VALUES('31','2','Eulalia Marisa Paulo','2026/002/00004@aluno.sige.ao','$2y$10$t/DBbEV1RX5FJBbnwWRuH.3kZiihNaY8Xz0DGfpF2gULLmFCyZX2m','aluno','',NULL,NULL,NULL,NULL,NULL,'ativo',NULL,NULL,NULL,NULL,'2026-04-23 01:04:06','2026-04-23 01:22:02');
INSERT INTO `usuarios` VALUES('32','2','Cristina Gama','2026/002/00005@aluno.sige.ao','$2y$10$h5MleQyfzBdevH3FmaqW2O8x1QGmPD9p59AGiFz6QgEEjjksxEtgi','aluno','',NULL,NULL,NULL,NULL,NULL,'ativo',NULL,NULL,NULL,NULL,'2026-04-23 01:28:40',NULL);
INSERT INTO `usuarios` VALUES('33','2','Armando Alberto João','FUNC/2/2026/0003@sige.ao','$2y$10$eB5o6VECw6ucMbUb3b2d8OOizjW6lq0C0cAc6AzR8o.WIMlIeNVaC','professor','943911384',NULL,NULL,NULL,NULL,NULL,'ativo','2026-05-01 09:13:53','::1',NULL,NULL,'2026-04-30 23:10:54','2026-05-01 09:13:53');

DROP TABLE IF EXISTS `vagas_emprego`;
CREATE TABLE `vagas_emprego` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escola_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `requisitos` text DEFAULT NULL,
  `tipo_contrato` varchar(50) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `quantidade` int(11) DEFAULT 1,
  `data_abertura` date DEFAULT NULL,
  `data_fecho` date DEFAULT NULL,
  `status` enum('aberta','fechada','cancelada') DEFAULT 'aberta',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escola_id` (`escola_id`),
  KEY `idx_status` (`status`),
  KEY `idx_datas` (`data_abertura`,`data_fecho`),
  CONSTRAINT `vagas_emprego_ibfk_1` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `vale_historico`;
CREATE TABLE `vale_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `solicitacao_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL COMMENT 'solicitado, aprovado, reprovado, pago',
  `usuario_id` int(11) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_solicitacao` (`solicitacao_id`),
  CONSTRAINT `vale_historico_ibfk_1` FOREIGN KEY (`solicitacao_id`) REFERENCES `solicitacoes_vale` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `view_dividas_funcionario`;
;


DROP TABLE IF EXISTS `view_resumo_frequencia_aluno`;
;

INSERT INTO `view_resumo_frequencia_aluno` VALUES('10','Alice Ribeiro','2026/002/00003','9','5º ATA/2025-2026','1','Matemática','2026','3','5ª Classe',NULL,NULL,'3','1','1','0','1','0','33.33','Reprovado por Falta','reprovado_frequencia');
INSERT INTO `view_resumo_frequencia_aluno` VALUES('12','Cristina Gama','2026/002/00005','9','5º ATA/2025-2026','1','Matemática','2026','3','5ª Classe',NULL,NULL,'2','0','0','0','2','0','0.00','Reprovado por Falta','reprovado_frequencia');
INSERT INTO `view_resumo_frequencia_aluno` VALUES('11','Eulalia Marisa Paulo','2026/002/00004','9','5º ATA/2025-2026','1','Matemática','2026','3','5ª Classe',NULL,NULL,'3','1','0','0','2','0','33.33','Reprovado por Falta','reprovado_frequencia');
INSERT INTO `view_resumo_frequencia_aluno` VALUES('8','Manuel Pedro Paulo','2026/002/00001','9','5º ATA/2025-2026','1','Matemática','2026','3','5º Ano','0','0','3','0','0','0','2','0','0.00','Reprovado por Falta','reprovado_frequencia');
INSERT INTO `view_resumo_frequencia_aluno` VALUES('9','Maria Paulo','2026/002/00002','9','5º ATA/2025-2026','1','Matemática','2026','3','5ª Classe',NULL,NULL,'3','1','0','0','1','0','33.33','Reprovado por Falta','reprovado_frequencia');

DROP TABLE IF EXISTS `view_resumo_frequencia_aluno_por_ano`;
;

INSERT INTO `view_resumo_frequencia_aluno_por_ano` VALUES('8','Manuel Pedro Paulo','2026/002/00001','9','5º ATA/2025-2026','5ª Classe','tarde','1','Matemática','3','2026','3','5º Ano','0','0','3','0','0','0','2','0.00');
INSERT INTO `view_resumo_frequencia_aluno_por_ano` VALUES('9','Maria Paulo','2026/002/00002','9','5º ATA/2025-2026','5ª Classe','tarde','1','Matemática','3','2026','3','5ª Classe',NULL,NULL,'3','1','0','0','1','33.33');
INSERT INTO `view_resumo_frequencia_aluno_por_ano` VALUES('10','Alice Ribeiro','2026/002/00003','9','5º ATA/2025-2026','5ª Classe','tarde','1','Matemática','3','2026','3','5ª Classe',NULL,NULL,'3','1','1','0','1','33.33');
INSERT INTO `view_resumo_frequencia_aluno_por_ano` VALUES('11','Eulalia Marisa Paulo','2026/002/00004','9','5º ATA/2025-2026','5ª Classe','tarde','1','Matemática','3','2026','3','5ª Classe',NULL,NULL,'3','1','0','0','2','33.33');
INSERT INTO `view_resumo_frequencia_aluno_por_ano` VALUES('12','Cristina Gama','2026/002/00005','9','5º ATA/2025-2026','5ª Classe','tarde','1','Matemática','3','2026','3','5ª Classe',NULL,NULL,'2','0','0','0','2','0.00');

DROP TABLE IF EXISTS `view_resumo_frequencia_trimestre`;
;

INSERT INTO `view_resumo_frequencia_trimestre` VALUES('8','Manuel Pedro Paulo','2026/002/00001','9','5º ATA/2025-2026','1','Matemática','3','2026','1','3','0','0','0','0.00','Reprovado');
INSERT INTO `view_resumo_frequencia_trimestre` VALUES('9','Maria Paulo','2026/002/00002','9','5º ATA/2025-2026','1','Matemática','3','2026','1','3','1','0','0','33.33','Reprovado');
INSERT INTO `view_resumo_frequencia_trimestre` VALUES('10','Alice Ribeiro','2026/002/00003','9','5º ATA/2025-2026','1','Matemática','3','2026','1','3','1','1','0','33.33','Reprovado');
INSERT INTO `view_resumo_frequencia_trimestre` VALUES('11','Eulalia Marisa Paulo','2026/002/00004','9','5º ATA/2025-2026','1','Matemática','3','2026','1','3','1','0','0','33.33','Reprovado');
INSERT INTO `view_resumo_frequencia_trimestre` VALUES('12','Cristina Gama','2026/002/00005','9','5º ATA/2025-2026','1','Matemática','3','2026','1','2','0','0','0','0.00','Reprovado');

DROP TABLE IF EXISTS `view_vale_completo`;
;



SET FOREIGN_KEY_CHECKS=1;
