-- ============================================
-- TABELA: chamada (Registo de Presenças/Faltas)
-- ============================================

CREATE TABLE IF NOT EXISTS `chamada` (
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
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chamada_unica` (`estudante_id`, `disciplina_id`, `data_aula`),
  KEY `idx_chamada_aluno` (`estudante_id`),
  KEY `idx_chamada_turma` (`turma_id`),
  KEY `idx_chamada_disciplina` (`disciplina_id`),
  KEY `idx_chamada_professor` (`professor_id`),
  KEY `idx_chamada_data` (`data_aula`),
  KEY `idx_chamada_status` (`status`),
  KEY `idx_chamada_escola` (`escola_id`),
  KEY `idx_chamada_ano_letivo` (`ano_letivo_id`),
  
  CONSTRAINT `fk_chamada_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_professor` FOREIGN KEY (`professor_id`) REFERENCES `professores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chamada_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registo de presenças e faltas dos estudantes';


-- ============================================
-- TABELA: frequencia_mensal (Resumo de Frequência por Mês)
-- ============================================

CREATE TABLE IF NOT EXISTS `frequencia_mensal` (
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
  UNIQUE KEY `uk_frequencia_unica` (`estudante_id`, `disciplina_id`, `mes`, `ano`),
  KEY `idx_frequencia_aluno` (`estudante_id`),
  KEY `idx_frequencia_turma` (`turma_id`),
  KEY `idx_frequencia_disciplina` (`disciplina_id`),
  KEY `idx_frequencia_mes_ano` (`mes`, `ano`),
  KEY `idx_frequencia_escola` (`escola_id`),
  KEY `idx_frequencia_ano_letivo` (`ano_letivo_id`),
  
  CONSTRAINT `fk_frequencia_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frequencia_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumo mensal de frequência dos estudantes';



-- ============================================
-- TABELA: tipos_falta (Classificação de Faltas - Legislação Angolana)
-- ============================================

CREATE TABLE IF NOT EXISTS `tipos_falta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL COMMENT 'Nome do tipo de falta',
  `descricao` text DEFAULT NULL COMMENT 'Descrição',
  `percentual_desconto` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentual de desconto na avaliação',
  `justificavel` tinyint(1) DEFAULT 1 COMMENT 'Pode ser justificada?',
  `prazo_justificacao_dias` int(11) DEFAULT 5 COMMENT 'Prazo para justificar (dias)',
  `status` tinyint(1) DEFAULT 1 COMMENT 'Ativo/Inativo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tipos de falta conforme legislação angolana';

-- Inserir tipos de falta padrão
INSERT INTO `tipos_falta` (`nome`, `descricao`, `percentual_desconto`, `justificavel`, `prazo_justificacao_dias`) VALUES
('Falta Normal', 'Falta sem justificação', 0.00, 1, 5),
('Falta Justificada', 'Falta com comprovativo médico ou outro', 0.00, 1, 10),
('Falta Grave', 'Falta sem justificação por mais de 3 dias consecutivos', 5.00, 0, 0),
('Atraso', 'Chegada após o horário estabelecido', 0.00, 1, 1),
('Dispensa', 'Afastamento autorizado pela direção', 0.00, 1, 15),
('Falta por Luto', 'Falta por falecimento de familiar direto', 0.00, 1, 30);

-- ============================================
-- TABELA: resumo_frequencia_trimestre (Resumo por Trimestre)
-- ============================================

CREATE TABLE IF NOT EXISTS `resumo_frequencia_trimestre` (
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
  UNIQUE KEY `uk_resumo_unico` (`estudante_id`, `disciplina_id`, `trimestre`),
  KEY `idx_resumo_aluno` (`estudante_id`),
  KEY `idx_resumo_disciplina` (`disciplina_id`),
  KEY `idx_resumo_escola` (`escola_id`),
  KEY `idx_resumo_ano_letivo` (`ano_letivo_id`),
  
  CONSTRAINT `fk_resumo_aluno` FOREIGN KEY (`estudante_id`) REFERENCES `estudantes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_escola` FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_resumo_ano_letivo` FOREIGN KEY (`ano_letivo_id`) REFERENCES `ano_letivo` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumo de frequência por trimestre';


-- ============================================
-- TABELA: justificativas_falta (Justificativas de Faltas)
-- ============================================

CREATE TABLE IF NOT EXISTS `justificativas_falta` (
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


-- ============================================
-- VIEW: view_resumo_frequencia_aluno (Resumo de Frequência por Aluno)
-- ============================================
-- ============================================
-- VIEW: view_resumo_frequencia_trimestre (Resumo por Trimestre)
-- ============================================

-- ============================================
-- VIEW: view_resumo_frequencia_aluno (Resumo de Frequência por Aluno)
-- Dados da turma retirados da tabela matriculas
-- ============================================

CREATE OR REPLACE VIEW `view_resumo_frequencia_aluno` AS
SELECT 
    e.id AS estudante_id,
    e.nome AS aluno_nome,
    e.matricula,
    m.turma_id,
    t.nome AS turma_nome,
    d.id AS disciplina_id,
    d.nome AS disciplina_nome,
    al.ano AS ano_letivo,
    m.ano_letivo AS ano_letivo_matricula,
    m.classe AS classe_aluno,
    m.curso AS curso_aluno,
    m.nivel AS nivel_aluno,
    COUNT(c.id) AS total_aulas,
    SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) AS total_presencas,
    SUM(CASE WHEN c.status = 'falta' THEN 1 ELSE 0 END) AS total_faltas,
    SUM(CASE WHEN c.status = 'falta_justificada' THEN 1 ELSE 0 END) AS total_faltas_justificadas,
    SUM(CASE WHEN c.status = 'atraso' THEN 1 ELSE 0 END) AS total_atrasos,
    SUM(CASE WHEN c.status = 'dispensa' THEN 1 ELSE 0 END) AS total_dispensas,
    ROUND(
        CASE 
            WHEN COUNT(c.id) > 0 
            THEN (SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100 
            ELSE 0 
        END, 2
    ) AS percentual_frequencia,
    CASE 
        WHEN ROUND(
            CASE 
                WHEN COUNT(c.id) > 0 
                THEN (SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100 
                ELSE 0 
            END, 2
        ) >= 75 THEN 'Regular'
        WHEN ROUND(
            CASE 
                WHEN COUNT(c.id) > 0 
                THEN (SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100 
                ELSE 0 
            END, 2
        ) >= 50 THEN 'Baixa Frequência'
        ELSE 'Reprovado por Falta'
    END AS status_frequencia,
    CASE 
        WHEN ROUND(
            CASE 
                WHEN COUNT(c.id) > 0 
                THEN (SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100 
                ELSE 0 
            END, 2
        ) < 50 THEN 'reprovado_frequencia'
        WHEN ROUND(
            CASE 
                WHEN COUNT(c.id) > 0 
                THEN (SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100 
                ELSE 0 
            END, 2
        ) < 75 THEN 'alerta'
        ELSE 'regular'
    END AS alerta_frequencia
FROM estudantes e
INNER JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
INNER JOIN turmas t ON t.id = m.turma_id
INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
INNER JOIN disciplinas d ON d.id = dt.disciplina_id
INNER JOIN ano_letivo al ON al.id = m.ano_letivo AND al.ativo = 1
LEFT JOIN chamada c ON c.estudante_id = e.id 
    AND c.disciplina_id = d.id 
    AND c.ano_letivo_id = al.id
    AND c.turma_id = t.id
GROUP BY e.id, e.nome, e.matricula, m.turma_id, t.nome, d.id, d.nome, al.ano, m.ano_letivo, m.classe, m.curso, m.nivel
ORDER BY e.nome, d.nome;

-- ============================================
-- VIEW: view_resumo_frequencia_aluno_por_ano (Com filtro de ano letivo)
-- ============================================

CREATE OR REPLACE VIEW `view_resumo_frequencia_aluno_por_ano` AS
SELECT 
    e.id AS estudante_id,
    e.nome AS aluno_nome,
    e.matricula,
    m.turma_id,
    t.nome AS turma_nome,
    t.ano AS turma_ano,
    t.turno AS turma_turno,
    d.id AS disciplina_id,
    d.nome AS disciplina_nome,
    al.id AS ano_letivo_id,
    al.ano AS ano_letivo,
    m.ano_letivo AS ano_matricula,
    m.classe AS classe_aluno,
    m.curso AS curso_aluno,
    m.nivel AS nivel_aluno,
    COUNT(c.id) AS total_aulas,
    SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) AS total_presencas,
    SUM(CASE WHEN c.status = 'falta' THEN 1 ELSE 0 END) AS total_faltas,
    SUM(CASE WHEN c.status = 'falta_justificada' THEN 1 ELSE 0 END) AS total_faltas_justificadas,
    SUM(CASE WHEN c.status = 'atraso' THEN 1 ELSE 0 END) AS total_atrasos,
    ROUND(COALESCE((SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 0), 2) AS percentual_frequencia
FROM estudantes e
INNER JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
INNER JOIN turmas t ON t.id = m.turma_id
INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
INNER JOIN disciplinas d ON d.id = dt.disciplina_id
INNER JOIN ano_letivo al ON al.id = m.ano_letivo
LEFT JOIN chamada c ON c.estudante_id = e.id 
    AND c.disciplina_id = d.id 
    AND c.ano_letivo_id = al.id
    AND c.turma_id = t.id
GROUP BY e.id, e.nome, e.matricula, m.turma_id, t.nome, t.ano, t.turno, d.id, d.nome, al.id, al.ano, m.ano_letivo, m.classe, m.curso, m.nivel;

-- ============================================
-- VIEW: view_resumo_frequencia_trimestre (Resumo por Trimestre)
-- ============================================

CREATE OR REPLACE VIEW `view_resumo_frequencia_trimestre` AS
SELECT 
    e.id AS estudante_id,
    e.nome AS aluno_nome,
    e.matricula,
    m.turma_id,
    t.nome AS turma_nome,
    d.id AS disciplina_id,
    d.nome AS disciplina_nome,
    al.id AS ano_letivo_id,
    al.ano AS ano_letivo,
    c.bimestre AS trimestre,
    COUNT(c.id) AS total_aulas,
    SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) AS total_presencas,
    SUM(CASE WHEN c.status = 'falta' THEN 1 ELSE 0 END) AS total_faltas,
    SUM(CASE WHEN c.status = 'falta_justificada' THEN 1 ELSE 0 END) AS total_faltas_justificadas,
    ROUND(COALESCE((SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 0), 2) AS percentual_frequencia,
    CASE 
        WHEN ROUND(COALESCE((SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 0), 2) >= 75 THEN 'Aprovado'
        WHEN ROUND(COALESCE((SUM(CASE WHEN c.status = 'presente' THEN 1 ELSE 0 END) / NULLIF(COUNT(c.id), 0)) * 100, 0), 2) >= 50 THEN 'Recuperação'
        ELSE 'Reprovado'
    END AS status_aprovacao
FROM estudantes e
INNER JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
INNER JOIN turmas t ON t.id = m.turma_id
INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
INNER JOIN disciplinas d ON d.id = dt.disciplina_id
INNER JOIN ano_letivo al ON al.id = m.ano_letivo
INNER JOIN chamada c ON c.estudante_id = e.id 
    AND c.disciplina_id = d.id 
    AND c.ano_letivo_id = al.id
    AND c.turma_id = t.id
GROUP BY e.id, e.nome, e.matricula, m.turma_id, t.nome, d.id, d.nome, al.id, al.ano, c.bimestre;
