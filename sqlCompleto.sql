-- ============================================
-- BANCO DE DADOS: sige_saas
-- SISTEMA INTEGRADO DE GESTÃO ESCOLAR - ANGOLA
-- VERSÃO: 2.0.0
-- DATA: 2026
-- ============================================

-- Criar banco de dados
CREATE DATABASE IF NOT EXISTS `sige_saas` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `sige_saas`;

-- ============================================
-- 1. TABELA: configuracoes_sistema
-- Descrição: Configurações gerais do sistema
-- ============================================
CREATE TABLE IF NOT EXISTS `configuracoes_sistema` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome_sistema` VARCHAR(100) NOT NULL DEFAULT 'SIGE Angola',
    `sigla` VARCHAR(20) DEFAULT 'SIGE',
    `versao` VARCHAR(20) DEFAULT '2.0.0',
    `email_geral` VARCHAR(100) NULL,
    `telefone` VARCHAR(20) NULL,
    `whatsapp` VARCHAR(20) NULL,
    `endereco` TEXT NULL,
    `logo` VARCHAR(255) NULL,
    `favicon` VARCHAR(255) NULL,
    `timezone` VARCHAR(50) DEFAULT 'Africa/Luanda',
    `moeda` VARCHAR(10) DEFAULT 'KZ',
    `moeda_simbolo` VARCHAR(10) DEFAULT 'KZ',
    `ano_letivo_atual` YEAR NULL,
    `bimestre_atual` INT DEFAULT 1,
    `nota_maxima` INT DEFAULT 20,
    `nota_minima_aprovacao` INT DEFAULT 10,
    `permite_recuperacao` BOOLEAN DEFAULT TRUE,
    `limite_faltas` INT DEFAULT 20,
    `enviar_email` BOOLEAN DEFAULT TRUE,
    `email_host` VARCHAR(100) NULL,
    `email_porta` INT DEFAULT 587,
    `email_seguranca` VARCHAR(10) DEFAULT 'tls',
    `email_usuario` VARCHAR(100) NULL,
    `email_senha` VARCHAR(255) NULL,
    `email_remetente` VARCHAR(100) NULL,
    `recaptcha_site_key` VARCHAR(100) NULL,
    `recaptcha_secret_key` VARCHAR(100) NULL,
    `manutencao` BOOLEAN DEFAULT FALSE,
    `manutencao_mensagem` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. TABELA: planos
-- Descrição: Planos/Pacotes disponíveis para assinatura
-- ============================================
CREATE TABLE IF NOT EXISTS `planos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `descricao` TEXT,
    `preco_mensal` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `preco_anual` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `limite_alunos` INT DEFAULT 0 COMMENT '0 = ilimitado',
    `limite_professores` INT DEFAULT 0 COMMENT '0 = ilimitado',
    `limite_turmas` INT DEFAULT 0 COMMENT '0 = ilimitado',
    `modulos_disponiveis` JSON COMMENT 'Módulos incluídos no plano',
    `recursos` JSON COMMENT 'Recursos adicionais',
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_preco (preco_mensal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. TABELA: escolas
-- Descrição: Escolas cadastradas no sistema (Clientes)
-- ============================================
CREATE TABLE IF NOT EXISTS `escolas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `plano_id` INT NULL,
    `nome` VARCHAR(200) NOT NULL,
    `subdominio` VARCHAR(100) UNIQUE NOT NULL,
    `dominio_personalizado` VARCHAR(200) UNIQUE NULL,
    `email` VARCHAR(100) NOT NULL,
    `telefone` VARCHAR(20) NULL,
    `celular` VARCHAR(20) NULL,
    `endereco` TEXT NULL,
    `provincia` VARCHAR(50) NULL,
    `municipio` VARCHAR(50) NULL,
    `comuna` VARCHAR(50) NULL,
    `logo` VARCHAR(255) NULL,
    `nuit` VARCHAR(20) NULL COMMENT 'Número de Identificação Tributária de Angola',
    `ano_fundacao` YEAR NULL,
    `responsavel_nome` VARCHAR(100) NULL,
    `responsavel_email` VARCHAR(100) NULL,
    `responsavel_telefone` VARCHAR(20) NULL,
    `status` ENUM('ativa', 'suspensa', 'inativa', 'trial') DEFAULT 'trial',
    `trial_ate` DATE NULL,
    `config` JSON NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL,
    INDEX idx_subdominio (subdominio),
    INDEX idx_status (status),
    INDEX idx_provincia (provincia),
    INDEX idx_plano (plano_id),
    INDEX idx_email (email),
    INDEX idx_nuit (nuit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. TABELA: usuarios
-- Descrição: Usuários do sistema
-- ============================================
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NULL COMMENT 'NULL = Super Admin',
    `nome` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `senha` VARCHAR(255) NOT NULL,
    `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno', 'pai', 'funcionario') NOT NULL,
    `telefone` VARCHAR(20) NULL,
    `celular` VARCHAR(20) NULL,
    `foto` VARCHAR(255) NULL,
    `bi` VARCHAR(20) NULL COMMENT 'Bilhete de Identidade Angolano',
    `data_nascimento` DATE NULL,
    `genero` ENUM('M', 'F') NULL,
    `status` ENUM('ativo', 'inativo', 'bloqueado') DEFAULT 'ativo',
    `ultimo_acesso` DATETIME NULL,
    `ultimo_ip` VARCHAR(45) NULL,
    `remember_token` VARCHAR(100) NULL,
    `permissoes` JSON NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_email (email),
    INDEX idx_tipo (tipo),
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_bi (bi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. TABELA: recuperacao_senha
-- Descrição: Tokens para recuperação de senha
-- ============================================
CREATE TABLE IF NOT EXISTS `recuperacao_senha` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `usuario_id` INT NOT NULL,
    `token` VARCHAR(100) NOT NULL,
    `expira` DATETIME NOT NULL,
    `usado` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expira (expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. TABELA: assinaturas
-- Descrição: Assinaturas das escolas
-- ============================================
CREATE TABLE IF NOT EXISTS `assinaturas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `plano_id` INT NOT NULL,
    `tipo_cobranca` ENUM('mensal', 'anual', 'trimestral') DEFAULT 'mensal',
    `valor` DECIMAL(10,2) NOT NULL,
    `data_inicio` DATE NOT NULL,
    `data_fim` DATE NOT NULL,
    `data_proxima_cobranca` DATE NULL,
    `status` ENUM('ativa', 'cancelada', 'expirada', 'pendente') DEFAULT 'pendente',
    `auto_renovacao` BOOLEAN DEFAULT TRUE,
    `observacoes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (plano_id) REFERENCES planos(id),
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_data_fim (data_fim),
    INDEX idx_data_inicio (data_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. TABELA: pagamentos
-- Descrição: Registro de pagamentos
-- ============================================
CREATE TABLE IF NOT EXISTS `pagamentos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `assinatura_id` INT NOT NULL,
    `valor` DECIMAL(10,2) NOT NULL,
    `referente` VARCHAR(100) NULL COMMENT 'Mês/Ano de referência',
    `metodo_pagamento` ENUM('dinheiro', 'transferencia', 'deposito', 'cartao', 'multicaixa', 'paypal') DEFAULT 'transferencia',
    `status` ENUM('pendente', 'pago', 'cancelado', 'reembolsado') DEFAULT 'pendente',
    `comprovante` VARCHAR(255) NULL,
    `data_pagamento` DATE NULL,
    `data_vencimento` DATE NULL,
    `codigo_transacao` VARCHAR(100) NULL,
    `observacoes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (assinatura_id) REFERENCES assinaturas(id),
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_data_vencimento (data_vencimento),
    INDEX idx_data_pagamento (data_pagamento),
    INDEX idx_assinatura (assinatura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. TABELA: turmas
-- Descrição: Turmas das escolas
-- ============================================
CREATE TABLE IF NOT EXISTS `turmas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `nome` VARCHAR(50) NOT NULL,
    `ano` VARCHAR(20) NOT NULL COMMENT 'Ex: 10ª Classe, 1º Ano',
    `turno` ENUM('manha', 'tarde', 'noite') NOT NULL,
    `ano_letivo` YEAR NOT NULL,
    `capacidade` INT DEFAULT 30,
    `sala` VARCHAR(20) NULL,
    `status` ENUM('ativa', 'encerrada') DEFAULT 'ativa',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_escola_ano (escola_id, ano_letivo),
    INDEX idx_status (status),
    INDEX idx_turno (turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. TABELA: disciplinas
-- Descrição: Disciplinas oferecidas
-- ============================================
CREATE TABLE IF NOT EXISTS `disciplinas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(20) NULL,
    `carga_horaria` INT NULL COMMENT 'Horas por semana',
    `descricao` TEXT NULL,
    `status` ENUM('ativa', 'inativa') DEFAULT 'ativa',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. TABELA: professores
-- Descrição: Professores vinculados às escolas
-- ============================================
CREATE TABLE IF NOT EXISTS `professores` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `usuario_id` INT NOT NULL,
    `escola_id` INT NOT NULL,
    `especialidade` VARCHAR(255) NULL,
    `formacao` TEXT NULL,
    `data_admissao` DATE NULL,
    `bi` VARCHAR(20) NULL,
    `bi_data_emissao` DATE NULL,
    `bi_local_emissao` VARCHAR(100) NULL,
    `nuit` VARCHAR(20) NULL,
    `nacionalidade` VARCHAR(50) DEFAULT 'Angolana',
    `naturalidade` VARCHAR(100) NULL,
    `provincia` VARCHAR(50) NULL,
    `municipio` VARCHAR(50) NULL,
    `comuna` VARCHAR(50) NULL,
    `endereco` TEXT NULL,
    `data_nascimento` DATE NULL,
    `genero` ENUM('M', 'F') NULL,
    `foto` VARCHAR(255) NULL,
    `bi_documento` VARCHAR(255) NULL,
    `diploma_documento` VARCHAR(255) NULL,
    `certificacoes_documento` VARCHAR(255) NULL,
    `declaracao_documento` VARCHAR(255) NULL,
    `carga_horaria` INT DEFAULT 0,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_usuario_escola (usuario_id, escola_id),
    INDEX idx_escola (escola_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_bi (bi),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. TABELA: alocacoes
-- Descrição: Alocação de professores por disciplina e turma
-- ============================================
CREATE TABLE IF NOT EXISTS `alocacoes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `professor_id` INT NOT NULL,
    `disciplina_id` INT NOT NULL,
    `turma_id` INT NULL COMMENT 'NULL = alocação geral da disciplina',
    `ano_letivo` YEAR NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE CASCADE,
    FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
    FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_alocacao (professor_id, disciplina_id, turma_id, ano_letivo),
    INDEX idx_professor (professor_id),
    INDEX idx_disciplina (disciplina_id),
    INDEX idx_turma (turma_id),
    INDEX idx_ano (ano_letivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. TABELA: estudantes
-- Descrição: Estudantes matriculados
-- ============================================
CREATE TABLE IF NOT EXISTS `estudantes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `usuario_id` INT NOT NULL,
    `escola_id` INT NOT NULL,
    `matricula` VARCHAR(50) UNIQUE NOT NULL,
    `bi` VARCHAR(20) UNIQUE NULL COMMENT 'Bilhete de Identidade',
    `bi_data_emissao` DATE NULL,
    `bi_local_emissao` VARCHAR(100) NULL,
    `nuit` VARCHAR(20) NULL,
    `nacionalidade` VARCHAR(50) DEFAULT 'Angolana',
    `naturalidade` VARCHAR(100) NULL,
    `provincia` VARCHAR(50) NULL,
    `municipio` VARCHAR(50) NULL,
    `comuna` VARCHAR(50) NULL,
    `endereco` TEXT NULL,
    `telefone` VARCHAR(20) NULL,
    `email` VARCHAR(100) NULL,
    `data_nascimento` DATE NULL,
    `genero` ENUM('M', 'F') NULL,
    `foto` VARCHAR(255) NULL,
    `pai_nome` VARCHAR(100) NULL,
    `pai_bi` VARCHAR(20) NULL,
    `pai_telefone` VARCHAR(20) NULL,
    `pai_profissao` VARCHAR(100) NULL,
    `mae_nome` VARCHAR(100) NULL,
    `mae_bi` VARCHAR(20) NULL,
    `mae_telefone` VARCHAR(20) NULL,
    `mae_profissao` VARCHAR(100) NULL,
    `encarregado_nome` VARCHAR(100) NULL,
    `encarregado_parentesco` VARCHAR(50) NULL,
    `encarregado_bi` VARCHAR(20) NULL,
    `encarregado_telefone` VARCHAR(20) NULL,
    `encarregado_email` VARCHAR(100) NULL,
    `encarregado_endereco` TEXT NULL,
    `ano_letivo` YEAR NOT NULL,
    `ano_escolar` VARCHAR(20) NULL,
    `numero_processo` VARCHAR(50) NULL,
    `escola_anterior` VARCHAR(200) NULL,
    `ano_ingresso` YEAR NULL,
    `bi_documento` VARCHAR(255) NULL,
    `certificado_documento` VARCHAR(255) NULL,
    `atestado_documento` VARCHAR(255) NULL,
    `outros_documentos` JSON NULL,
    `declaracao_documento` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_matricula (matricula),
    INDEX idx_escola (escola_id),
    INDEX idx_bi (bi),
    INDEX idx_usuario (usuario_id),
    INDEX idx_encarregado_telefone (encarregado_telefone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 13. TABELA: matriculas
-- Descrição: Matrículas dos estudantes nas turmas
-- ============================================
CREATE TABLE IF NOT EXISTS `matriculas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `estudante_id` INT NOT NULL,
    `turma_id` INT NOT NULL,
    `ano_letivo` YEAR NOT NULL,
    `data_matricula` DATE DEFAULT CURRENT_DATE,
    `numero_matricula` VARCHAR(50) UNIQUE NULL,
    `status` ENUM('ativa', 'transferido', 'concluido', 'desistente', 'reprovado') DEFAULT 'ativa',
    `observacoes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id) ON DELETE CASCADE,
    FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_matricula (estudante_id, turma_id, ano_letivo),
    INDEX idx_estudante (estudante_id),
    INDEX idx_turma (turma_id),
    INDEX idx_status (status),
    INDEX idx_ano (ano_letivo),
    INDEX idx_numero_matricula (numero_matricula)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. TABELA: notas
-- Descrição: Notas dos estudantes
-- ============================================
CREATE TABLE IF NOT EXISTS `notas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `matricula_id` INT NOT NULL,
    `disciplina_id` INT NOT NULL,
    `bimestre` INT NOT NULL COMMENT '1, 2 ou 3',
    `mac` DECIMAL(5,2) NULL COMMENT 'Média de Atividades Contínuas',
    `npt` DECIMAL(5,2) NULL COMMENT 'Nota de Prova Trimestral',
    `exame_normal` DECIMAL(5,2) NULL,
    `exame_recurso` DECIMAL(5,2) NULL,
    `exame_especial` DECIMAL(5,2) NULL,
    `exame_oral` DECIMAL(5,2) NULL,
    `exame_escrito` DECIMAL(5,2) NULL,
    `media` DECIMAL(5,2) NULL,
    `media_final` DECIMAL(5,2) NULL,
    `status` ENUM('aprovado', 'reprovado', 'recuperacao', 'exame', 'dispensado') DEFAULT 'exame',
    `observacoes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE,
    FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_nota (matricula_id, disciplina_id, bimestre),
    INDEX idx_matricula (matricula_id),
    INDEX idx_disciplina (disciplina_id),
    INDEX idx_bimestre (bimestre),
    INDEX idx_status (status),
    INDEX idx_media_final (media_final)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 15. TABELA: presencas (Chamada de Aula)
-- Descrição: Registro de presença dos estudantes nas aulas
-- ============================================
CREATE TABLE IF NOT EXISTS `presencas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `matricula_id` INT NOT NULL,
    `data` DATE NOT NULL,
    `presente` BOOLEAN DEFAULT TRUE,
    `justificativa` TEXT NULL,
    `tipo_falta` ENUM('justificada', 'injustificada') NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_presenca (matricula_id, data),
    INDEX idx_data (data),
    INDEX idx_matricula (matricula_id),
    INDEX idx_presente (presente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 16. TABELA: entrada_saida
-- Descrição: Registro de entrada e saída dos estudantes
-- ============================================
CREATE TABLE IF NOT EXISTS `entrada_saida` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `estudante_id` INT NOT NULL,
    `data` DATE NOT NULL,
    `hora_entrada` TIME NULL,
    `hora_saida` TIME NULL,
    `observacao_entrada` TEXT NULL,
    `observacao_saida` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entrada_saida (estudante_id, data),
    INDEX idx_data (data),
    INDEX idx_estudante (estudante_id),
    INDEX idx_hora_entrada (hora_entrada),
    INDEX idx_hora_saida (hora_saida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 17. TABELA: livros (Biblioteca)
-- Descrição: Livros disponíveis na biblioteca digital
-- ============================================
CREATE TABLE IF NOT EXISTS `livros` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `titulo` VARCHAR(200) NOT NULL,
    `autor` VARCHAR(100) NULL,
    `editora` VARCHAR(100) NULL,
    `ano_publicacao` YEAR NULL,
    `isbn` VARCHAR(20) NULL,
    `categoria` VARCHAR(50) NULL,
    `disciplina_id` INT NULL,
    `descricao` TEXT NULL,
    `capa` VARCHAR(255) NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `visualizacoes` INT DEFAULT 0,
    `downloads` INT DEFAULT 0,
    `status` ENUM('disponivel', 'indisponivel') DEFAULT 'disponivel',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE SET NULL,
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_categoria (categoria),
    INDEX idx_autor (autor),
    INDEX idx_disciplina (disciplina_id),
    FULLTEXT idx_busca (titulo, autor, descricao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 18. TABELA: tickets_suporte
-- Descrição: Tickets de suporte
-- ============================================
CREATE TABLE IF NOT EXISTS `tickets_suporte` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `usuario_id` INT NULL,
    `assunto` VARCHAR(200) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `prioridade` ENUM('baixa', 'media', 'alta', 'urgente') DEFAULT 'media',
    `status` ENUM('aberto', 'em_andamento', 'respondido', 'fechado') DEFAULT 'aberto',
    `categoria` VARCHAR(50) NULL,
    `anexo` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_prioridade (prioridade),
    INDEX idx_categoria (categoria),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 19. TABELA: ticket_respostas
-- Descrição: Respostas dos tickets
-- ============================================
CREATE TABLE IF NOT EXISTS `ticket_respostas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `ticket_id` INT NOT NULL,
    `usuario_id` INT NULL,
    `mensagem` TEXT NOT NULL,
    `anexo` VARCHAR(255) NULL,
    `is_admin` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets_suporte(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_ticket (ticket_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 20. TABELA: notificacoes
-- Descrição: Notificações do sistema
-- ============================================
CREATE TABLE IF NOT EXISTS `notificacoes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NULL COMMENT 'NULL = notificação global',
    `usuario_id` INT NULL COMMENT 'NULL = notificação para escola',
    `titulo` VARCHAR(200) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `tipo` ENUM('info', 'sucesso', 'aviso', 'erro') DEFAULT 'info',
    `prioridade` ENUM('baixa', 'normal', 'alta') DEFAULT 'normal',
    `link` VARCHAR(255) NULL,
    `lida` BOOLEAN DEFAULT FALSE,
    `lida_em` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_escola (escola_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_lida (lida),
    INDEX idx_tipo (tipo),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 21. TABELA: logs_sistema
-- Descrição: Logs de atividades do sistema
-- ============================================
CREATE TABLE IF NOT EXISTS `logs_sistema` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `usuario_id` INT NULL,
    `escola_id` INT NULL,
    `acao` VARCHAR(100) NOT NULL,
    `tabela` VARCHAR(50) NULL,
    `registro_id` INT NULL,
    `dados_antes` JSON NULL,
    `dados_depois` JSON NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE SET NULL,
    INDEX idx_usuario (usuario_id),
    INDEX idx_escola (escola_id),
    INDEX idx_acao (acao),
    INDEX idx_tabela (tabela),
    INDEX idx_created (created_at),
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 22. TABELA: email_queue
-- Descrição: Fila de e-mails para envio
-- ============================================
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `destinatario` VARCHAR(255) NOT NULL,
    `assunto` VARCHAR(255) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `anexos` JSON NULL,
    `status` ENUM('pendente', 'enviado', 'falha') DEFAULT 'pendente',
    `tentativas` INT DEFAULT 0,
    `enviado_em` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 23. TABELA: feriados (Angola)
-- Descrição: Feriados nacionais e municipais de Angola
-- ============================================
CREATE TABLE IF NOT EXISTS `feriados` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `data` DATE NOT NULL,
    `tipo` ENUM('nacional', 'provincial', 'municipal') DEFAULT 'nacional',
    `provincia` VARCHAR(50) NULL,
    `municipio` VARCHAR(50) NULL,
    `descricao` TEXT NULL,
    `ano_letivo` YEAR NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_data (data),
    INDEX idx_tipo (tipo),
    INDEX idx_provincia (provincia),
    INDEX idx_ano (ano_letivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 24. TABELA: configuracoes_escola
-- Descrição: Configurações específicas por escola e ano letivo
-- ============================================
CREATE TABLE IF NOT EXISTS `configuracoes_escola` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `ano_letivo` YEAR NOT NULL,
    `bimestre_atual` INT DEFAULT 1,
    `data_inicio_aulas` DATE NULL,
    `data_fim_aulas` DATE NULL,
    `config` JSON NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    UNIQUE KEY unique_escola_ano (escola_id, ano_letivo),
    INDEX idx_escola (escola_id),
    INDEX idx_ano (ano_letivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 25. TABELA: calendario_escolar
-- Descrição: Eventos do calendário escolar
-- ============================================
CREATE TABLE IF NOT EXISTS `calendario_escolar` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `titulo` VARCHAR(200) NOT NULL,
    `descricao` TEXT NULL,
    `data_inicio` DATE NOT NULL,
    `data_fim` DATE NULL,
    `tipo` ENUM('prova', 'reuniao', 'evento', 'feriado', 'recesso') DEFAULT 'evento',
    `bimestre` INT NULL,
    `cor` VARCHAR(7) DEFAULT '#006B3E',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_escola (escola_id),
    INDEX idx_data (data_inicio),
    INDEX idx_tipo (tipo),
    INDEX idx_bimestre (bimestre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 26. TABELA: angola_provincias
-- Descrição: Províncias de Angola
-- ============================================
CREATE TABLE IF NOT EXISTS `angola_provincias` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL UNIQUE,
    `sigla` VARCHAR(10) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 27. TABELA: angola_municipios
-- Descrição: Municípios de Angola
-- ============================================
CREATE TABLE IF NOT EXISTS `angola_municipios` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `provincia_id` INT NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provincia_id) REFERENCES angola_provincias(id) ON DELETE CASCADE,
    UNIQUE KEY unique_municipio (provincia_id, nome),
    INDEX idx_provincia (provincia_id),
    INDEX idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 28. TABELA: angola_comunas
-- Descrição: Comunas de Angola
-- ============================================
CREATE TABLE IF NOT EXISTS `angola_comunas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `municipio_id` INT NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (municipio_id) REFERENCES angola_municipios(id) ON DELETE CASCADE,
    UNIQUE KEY unique_comuna (municipio_id, nome),
    INDEX idx_municipio (municipio_id),
    INDEX idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 29. TABELA: permissoes
-- Descrição: Permissões do sistema
-- ============================================
CREATE TABLE IF NOT EXISTS `permissoes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(50) NOT NULL,
    `descricao` TEXT NULL,
    `modulo` VARCHAR(50) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nome (nome),
    INDEX idx_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 30. TABELA: papeis
-- Descrição: Papéis de usuário
-- ============================================
CREATE TABLE IF NOT EXISTS `papeis` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(50) NOT NULL,
    `descricao` TEXT NULL,
    `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno', 'pai') NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 31. TABELA: papel_permissoes
-- Descrição: Permissões por papel
-- ============================================
CREATE TABLE IF NOT EXISTS `papel_permissoes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `papel_id` INT NOT NULL,
    `permissao_id` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (papel_id) REFERENCES papeis(id) ON DELETE CASCADE,
    FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_papel_permissao (papel_id, permissao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 32. TABELA: backup_sistema
-- Descrição: Registro de backups realizados
-- ============================================
CREATE TABLE IF NOT EXISTS `backup_sistema` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `descricao` TEXT NULL,
    `tamanho` VARCHAR(50) NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `tipo` ENUM('manual', 'automatico') DEFAULT 'manual',
    `status` ENUM('sucesso', 'falha', 'em_andamento') DEFAULT 'em_andamento',
    `usuario_id` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_tipo (tipo),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DADOS INICIAIS (PLANOS PADRÃO)
-- ============================================

INSERT INTO `planos` (`id`, `nome`, `descricao`, `preco_mensal`, `preco_anual`, `limite_alunos`, `limite_professores`, `limite_turmas`, `modulos_disponiveis`, `recursos`, `status`) VALUES
(1, 'Básico', 'Plano ideal para pequenas escolas', 19900.00, 199000.00, 100, 10, 5, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada"]', '{"suporte": "email", "armazenamento": 10, "relatorios_basicos": true}', 'ativo'),
(2, 'Profissional', 'Plano completo para escolas em crescimento', 39900.00, 399000.00, 500, 30, 20, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada","biblioteca","relatorios"]', '{"suporte": "email_telefone", "armazenamento": 50, "relatorios_avancados": true, "api": true}', 'ativo'),
(3, 'Empresarial', 'Plano premium para grandes instituições', 79900.00, 799000.00, 2000, 100, 50, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada","biblioteca","relatorios","financeiro","comunicados"]', '{"suporte": "dedicado", "armazenamento": 200, "relatorios_personalizados": true, "api": true, "certificado_digital": true}', 'ativo');

-- ============================================
-- DADOS INICIAIS (PROVÍNCIAS DE ANGOLA)
-- ============================================

INSERT INTO `angola_provincias` (`nome`, `sigla`) VALUES
('Bengo', 'BGO'),
('Benguela', 'BGU'),
('Bié', 'BIE'),
('Cabinda', 'CAB'),
('Cuando Cubango', 'CCU'),
('Cuanza Norte', 'CNO'),
('Cuanza Sul', 'CSU'),
('Cunene', 'CNN'),
('Huambo', 'HUA'),
('Huíla', 'HUI'),
('Luanda', 'LAD'),
('Lunda Norte', 'LNO'),
('Lunda Sul', 'LSU'),
('Malanje', 'MAL'),
('Moxico', 'MOX'),
('Namibe', 'NAM'),
('Uíge', 'UIG'),
('Zaire', 'ZAI');

-- ============================================
-- DADOS INICIAIS (FERIADOS NACIONAIS DE ANGOLA)
-- ============================================

INSERT INTO `feriados` (`nome`, `data`, `tipo`, `descricao`) VALUES
('Ano Novo', '2026-01-01', 'nacional', 'Confraternização Universal'),
('Dia da Liberdade', '2026-01-04', 'nacional', 'Fim do regime colonial'),
('Dia dos Mártires da Repressão Colonial', '2026-02-04', 'nacional', 'Início da luta armada'),
('Carnaval', '2026-02-17', 'nacional', 'Carnaval'),
('Dia da Mulher Angolana', '2026-03-02', 'nacional', 'Homenagem à mulher angolana'),
('Sexta-feira Santa', '2026-04-03', 'nacional', 'Paixão de Cristo'),
('Dia da Paz e Reconciliação Nacional', '2026-04-04', 'nacional', 'Assinatura dos Acordos de Paz'),
('Páscoa', '2026-04-05', 'nacional', 'Ressurreição de Cristo'),
('Dia do Trabalhador', '2026-05-01', 'nacional', 'Dia Internacional do Trabalhador'),
('Dia do Fundador da Nação', '2026-09-17', 'nacional', 'Aniversário de António Agostinho Neto'),
('Dia das Forças Armadas', '2026-10-01', 'nacional', 'Dia das FAA'),
('Dia de Todos os Santos', '2026-11-01', 'nacional', 'Dia de Todos os Santos'),
('Dia do Herói Nacional', '2026-11-11', 'nacional', 'Independência Nacional'),
('Natal', '2026-12-25', 'nacional', 'Natal');

-- ============================================
-- DADOS INICIAIS (PERMISSÕES)
-- ============================================

INSERT INTO `permissoes` (`nome`, `descricao`, `modulo`) VALUES
-- Dashboard
('dashboard_ver', 'Visualizar Dashboard', 'dashboard'),
('dashboard_estatisticas', 'Ver Estatísticas', 'dashboard'),
-- Escolas
('escolas_ver', 'Visualizar Escolas', 'escolas'),
('escolas_cadastrar', 'Cadastrar Escolas', 'escolas'),
('escolas_editar', 'Editar Escolas', 'escolas'),
('escolas_excluir', 'Excluir Escolas', 'escolas'),
-- Planos
('planos_ver', 'Visualizar Planos', 'planos'),
('planos_cadastrar', 'Cadastrar Planos', 'planos'),
('planos_editar', 'Editar Planos', 'planos'),
('planos_excluir', 'Excluir Planos', 'planos'),
-- Assinaturas
('assinaturas_ver', 'Visualizar Assinaturas', 'assinaturas'),
('assinaturas_renovar', 'Renovar Assinaturas', 'assinaturas'),
('assinaturas_cancelar', 'Cancelar Assinaturas', 'assinaturas'),
-- Pagamentos
('pagamentos_ver', 'Visualizar Pagamentos', 'pagamentos'),
('pagamentos_registrar', 'Registrar Pagamentos', 'pagamentos'),
('pagamentos_editar', 'Editar Pagamentos', 'pagamentos'),
('pagamentos_excluir', 'Excluir Pagamentos', 'pagamentos'),
-- Alunos
('alunos_ver', 'Visualizar Alunos', 'alunos'),
('alunos_cadastrar', 'Cadastrar Alunos', 'alunos'),
('alunos_editar', 'Editar Alunos', 'alunos'),
('alunos_excluir', 'Excluir Alunos', 'alunos'),
-- Professores
('professores_ver', 'Visualizar Professores', 'professores'),
('professores_cadastrar', 'Cadastrar Professores', 'professores'),
('professores_editar', 'Editar Professores', 'professores'),
('professores_excluir', 'Excluir Professores', 'professores'),
-- Turmas
('turmas_ver', 'Visualizar Turmas', 'turmas'),
('turmas_cadastrar', 'Cadastrar Turmas', 'turmas'),
('turmas_editar', 'Editar Turmas', 'turmas'),
('turmas_excluir', 'Excluir Turmas', 'turmas'),
-- Disciplinas
('disciplinas_ver', 'Visualizar Disciplinas', 'disciplinas'),
('disciplinas_cadastrar', 'Cadastrar Disciplinas', 'disciplinas'),
('disciplinas_editar', 'Editar Disciplinas', 'disciplinas'),
('disciplinas_excluir', 'Excluir Disciplinas', 'disciplinas'),
-- Notas
('notas_ver', 'Visualizar Notas', 'notas'),
('notas_lancar', 'Lançar Notas', 'notas'),
('notas_editar', 'Editar Notas', 'notas'),
-- Chamada
('chamada_ver', 'Visualizar Chamada', 'chamada'),
('chamada_registrar', 'Registrar Chamada', 'chamada'),
('chamada_justificar', 'Justificar Faltas', 'chamada'),
-- Biblioteca
('biblioteca_ver', 'Visualizar Biblioteca', 'biblioteca'),
('biblioteca_cadastrar', 'Cadastrar Livros', 'biblioteca'),
('biblioteca_editar', 'Editar Livros', 'biblioteca'),
('biblioteca_excluir', 'Excluir Livros', 'biblioteca'),
-- Relatórios
('relatorios_ver', 'Visualizar Relatórios', 'relatorios'),
('relatorios_exportar', 'Exportar Relatórios', 'relatorios'),
-- Configurações
('config_ver', 'Visualizar Configurações', 'config'),
('config_editar', 'Editar Configurações', 'config');

-- ============================================
-- DADOS INICIAIS (PAPÉIS)
-- ============================================

INSERT INTO `papeis` (`nome`, `descricao`, `tipo`) VALUES
('Super Administrador', 'Acesso total ao sistema', 'super_admin'),
('Administrador Escola', 'Gerencia todas as funcionalidades da escola', 'admin_escola'),
('Diretor', 'Gerencia a escola e visualiza relatórios', 'diretor'),
('Professor', 'Lança notas e faz chamada', 'professor'),
('Secretaria', 'Gerencia matrículas e documentos', 'secretaria'),
('Aluno', 'Visualiza notas e frequência', 'aluno'),
('Pai/Encarregado', 'Acompanha o desempenho do aluno', 'pai');

-- ============================================
-- CONFIGURAÇÕES PADRÃO DO SISTEMA
-- ============================================

INSERT INTO `configuracoes_sistema` (
    `nome_sistema`, `sigla`, `versao`, `timezone`, `moeda`, `moeda_simbolo`, 
    `ano_letivo_atual`, `bimestre_atual`, `nota_maxima`, `nota_minima_aprovacao`, 
    `permite_recuperacao`, `limite_faltas`, `created_at`
) VALUES (
    'SIGE Angola', 'SIGE', '2.0.0', 'Africa/Luanda', 'KZ', 'KZ',
    YEAR(CURDATE()), 1, 20, 10, 1, 20, NOW()
);

-- ============================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- ============================================

-- Índices para buscas mais comuns
CREATE INDEX idx_escolas_nome ON escolas(nome);
CREATE INDEX idx_usuarios_nome ON usuarios(nome);
CREATE INDEX idx_estudantes_nome ON estudantes(usuario_id);
CREATE INDEX idx_pagamentos_created ON pagamentos(created_at);
CREATE INDEX idx_notas_media ON notas(media);
CREATE INDEX idx_presencas_data ON presencas(data);
CREATE INDEX idx_tickets_created ON tickets_suporte(created_at);
CREATE INDEX idx_logs_acao ON logs_sistema(acao);
CREATE INDEX idx_logs_created ON logs_sistema(created_at);

-- Índices compostos para relatórios
CREATE INDEX idx_pagamentos_status_data ON pagamentos(status, data_pagamento);
CREATE INDEX idx_assinaturas_status_fim ON assinaturas(status, data_fim);
CREATE INDEX idx_matriculas_status_ano ON matriculas(status, ano_letivo);
CREATE INDEX idx_notas_matricula_bimestre ON notas(matricula_id, bimestre);
CREATE INDEX idx_presencas_matricula_data ON presencas(matricula_id, data);
CREATE INDEX idx_entrada_saida_estudante_data ON entrada_saida(estudante_id, data);

-- ============================================
-- VIEWS PARA RELATÓRIOS
-- ============================================

-- View: Resumo financeiro por escola
CREATE OR REPLACE VIEW `vw_resumo_financeiro_escola` AS
SELECT 
    e.id as escola_id,
    e.nome as escola_nome,
    e.subdominio,
    e.status as escola_status,
    COUNT(DISTINCT p.id) as total_pagamentos,
    COALESCE(SUM(p.valor), 0) as total_recebido,
    COUNT(DISTINCT a.id) as total_assinaturas,
    MAX(p.data_pagamento) as ultimo_pagamento,
    MIN(a.data_fim) as proximo_vencimento
FROM escolas e
LEFT JOIN pagamentos p ON p.escola_id = e.id AND p.status = 'pago'
LEFT JOIN assinaturas a ON a.escola_id = e.id AND a.status = 'ativa'
GROUP BY e.id, e.nome, e.subdominio, e.status;

-- View: Resumo de alunos por escola
CREATE OR REPLACE VIEW `vw_resumo_alunos_escola` AS
SELECT 
    e.id as escola_id,
    e.nome as escola_nome,
    COUNT(DISTINCT est.id) as total_alunos,
    COUNT(DISTINCT m.id) as total_matriculas_ativas,
    COUNT(DISTINCT t.id) as total_turmas
FROM escolas e
LEFT JOIN estudantes est ON est.escola_id = e.id
LEFT JOIN matriculas m ON m.estudante_id = est.id AND m.status = 'ativa'
LEFT JOIN turmas t ON t.escola_id = e.id AND t.status = 'ativa'
GROUP BY e.id, e.nome;

-- View: Dashboard de indicadores
CREATE OR REPLACE VIEW `vw_dashboard_indicadores` AS
SELECT 
    (SELECT COUNT(*) FROM escolas) as total_escolas,
    (SELECT COUNT(*) FROM escolas WHERE status = 'ativa') as escolas_ativas,
    (SELECT COUNT(*) FROM usuarios WHERE tipo != 'super_admin') as total_usuarios,
    (SELECT COUNT(*) FROM assinaturas WHERE status = 'ativa') as assinaturas_ativas,
    (SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE status = 'pago' AND MONTH(data_pagamento) = MONTH(CURDATE())) as faturamento_mes,
    (SELECT COUNT(*) FROM tickets_suporte WHERE status != 'fechado') as tickets_abertos,
    (SELECT COUNT(*) FROM notificacoes WHERE lida = 0 AND escola_id IS NULL) as notificacoes_nao_lidas;

-- ============================================
-- FIM DO SCRIPT
-- ============================================