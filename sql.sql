-- ============================================
-- TABELAS DO SISTEMA SAAS
-- ============================================

-- 1. Tabela de planos/pacotes
CREATE TABLE `planos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `descricao` TEXT,
    `preco_mensal` DECIMAL(10,2) NOT NULL,
    `preco_anual` DECIMAL(10,2) NOT NULL,
    `recursos` JSON, -- Ex: {"max_alunos": 500, "max_professores": 20, "modulos": ["notas", "chamada", "biblioteca"]}
    `limite_alunos` INT DEFAULT 0,
    `limite_professores` INT DEFAULT 0,
    `limite_turmas` INT DEFAULT 0,
    `modulos_disponiveis` JSON,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabela de escolas (clientes)
CREATE TABLE `escolas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `plano_id` INT,
    `nome` VARCHAR(200) NOT NULL,
    `subdominio` VARCHAR(100) UNIQUE NOT NULL,
    `dominio_personalizado` VARCHAR(200) UNIQUE,
    `cnpj` VARCHAR(20),
    `email` VARCHAR(100) NOT NULL,
    `telefone` VARCHAR(20),
    `celular` VARCHAR(20),
    `endereco` TEXT,
    `cidade` VARCHAR(100),
    `estado` VARCHAR(50),
    `cep` VARCHAR(20),
    `logo` VARCHAR(255),
    `responsavel_nome` VARCHAR(100),
    `responsavel_email` VARCHAR(100),
    `responsavel_telefone` VARCHAR(20),
    `data_ativacao` DATE,
    `data_expiracao` DATE,
    `status` ENUM('ativa', 'suspensa', 'inativa', 'trial') DEFAULT 'trial',
    `trial_ate` DATE,
    `observacoes` TEXT,
    `config` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL,
    INDEX idx_subdominio (subdominio),
    INDEX idx_status (status),
    INDEX idx_expiracao (data_expiracao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabela de assinaturas
CREATE TABLE `assinaturas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `plano_id` INT NOT NULL,
    `tipo_cobranca` ENUM('mensal', 'anual', 'trimestral') DEFAULT 'mensal',
    `valor` DECIMAL(10,2) NOT NULL,
    `data_inicio` DATE NOT NULL,
    `data_fim` DATE NOT NULL,
    `data_proxima_cobranca` DATE,
    `status` ENUM('ativa', 'cancelada', 'expirada', 'pendente') DEFAULT 'ativa',
    `auto_renovacao` BOOLEAN DEFAULT TRUE,
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (plano_id) REFERENCES planos(id),
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_data_fim (data_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabela de pagamentos
CREATE TABLE `pagamentos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `assinatura_id` INT NOT NULL,
    `valor` DECIMAL(10,2) NOT NULL,
    `referente` VARCHAR(100), -- Mês/Ano de referência
    `metodo_pagamento` ENUM('dinheiro', 'transferencia', 'deposito', 'cartao', 'paypal', 'mercadopago') DEFAULT 'transferencia',
    `status` ENUM('pendente', 'pago', 'cancelado', 'reembolsado') DEFAULT 'pendente',
    `comprovante` VARCHAR(255),
    `data_pagamento` DATE,
    `data_vencimento` DATE,
    `codigo_transacao` VARCHAR(100),
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (assinatura_id) REFERENCES assinaturas(id),
    INDEX idx_escola (escola_id),
    INDEX idx_status (status),
    INDEX idx_data_vencimento (data_vencimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Tabela de módulos do sistema
CREATE TABLE `modulos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nome` VARCHAR(50) NOT NULL,
    `descricao` TEXT,
    `icone` VARCHAR(50),
    `rota` VARCHAR(100),
    `ordem` INT DEFAULT 0,
    `status` BOOLEAN DEFAULT TRUE,
    INDEX idx_ordem (ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Tabela de tickets de suporte
CREATE TABLE `tickets_suporte` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `usuario_id` INT,
    `assunto` VARCHAR(200) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `prioridade` ENUM('baixa', 'media', 'alta', 'urgente') DEFAULT 'media',
    `status` ENUM('aberto', 'em_andamento', 'respondido', 'fechado') DEFAULT 'aberto',
    `categoria` VARCHAR(50),
    `anexo` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_escola (escola_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Tabela de respostas dos tickets
CREATE TABLE `ticket_respostas` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `ticket_id` INT NOT NULL,
    `usuario_id` INT,
    `mensagem` TEXT NOT NULL,
    `anexo` VARCHAR(255),
    `is_admin` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets_suporte(id) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Tabela de notificações
CREATE TABLE `notificacoes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT,
    `titulo` VARCHAR(200) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `tipo` ENUM('info', 'sucesso', 'aviso', 'erro') DEFAULT 'info',
    `lida` BOOLEAN DEFAULT FALSE,
    `link` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_escola (escola_id),
    INDEX idx_lida (lida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Tabela de logs do sistema
CREATE TABLE `logs_sistema` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `usuario_id` INT,
    `escola_id` INT,
    `acao` VARCHAR(100) NOT NULL,
    `tabela` VARCHAR(50),
    `registro_id` INT,
    `dados_antes` JSON,
    `dados_depois` JSON,
    `ip` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_escola (escola_id),
    INDEX idx_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Tabela de usuários (super admins e admins de escola)
CREATE TABLE `usuarios` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `escola_id` INT NULL, -- NULL para super admins
    `nome` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `senha` VARCHAR(255) NOT NULL,
    `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno', 'pai') NOT NULL,
    `telefone` VARCHAR(20),
    `foto` VARCHAR(255),
    `status` ENUM('ativo', 'inativo', 'bloqueado') DEFAULT 'ativo',
    `ultimo_acesso` DATETIME,
    `permissoes` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    INDEX idx_email (email),
    INDEX idx_tipo (tipo),
    INDEX idx_escola (escola_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserir módulos padrão
INSERT INTO `modulos` (`nome`, `descricao`, `icone`, `rota`, `ordem`) VALUES
('Dashboard', 'Painel de controle principal', 'dashboard', 'dashboard', 1),
('Alunos', 'Gerenciamento de alunos', 'users', 'alunos', 2),
('Professores', 'Gerenciamento de professores', 'chalkboard-user', 'professores', 3),
('Turmas', 'Gerenciamento de turmas', 'users-group', 'turmas', 4),
('Disciplinas', 'Gerenciamento de disciplinas', 'book', 'disciplinas', 5),
('Notas', 'Lançamento de notas', 'graduation-cap', 'notas', 6),
('Chamada', 'Registro de presença', 'calendar-check', 'chamada', 7),
('Biblioteca', 'Biblioteca digital', 'book-open', 'biblioteca', 8),
('Relatórios', 'Relatórios e estatísticas', 'chart-line', 'relatorios', 9),
('Financeiro', 'Gestão financeira', 'money-bill', 'financeiro', 10);

-- Inserir planos padrão
INSERT INTO `planos` (`nome`, `descricao`, `preco_mensal`, `preco_anual`, `limite_alunos`, `limite_professores`, `limite_turmas`, `modulos_disponiveis`, `recursos`) VALUES
('Básico', 'Plano ideal para pequenas escolas', 199.00, 1990.00, 100, 10, 5, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada"]', '{"suporte": "email", "armazenamento": 10, "relatorios_basicos": true}'),
('Profissional', 'Plano completo para escolas em crescimento', 399.00, 3990.00, 500, 30, 20, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada","biblioteca","relatorios"]', '{"suporte": "email_telefone", "armazenamento": 50, "relatorios_avancados": true, "api": true}'),
('Empresarial', 'Plano premium para grandes instituições', 799.00, 7990.00, 2000, 100, 50, '["dashboard","alunos","professores","turmas","disciplinas","notas","chamada","biblioteca","relatorios","financeiro"]', '{"suporte": "dedicado", "armazenamento": 200, "relatorios_personalizados": true, "api": true, "certificado_digital": true}');

-- Inserir super admin padrão (senha: admin123)
INSERT INTO `usuarios` (`nome`, `email`, `senha`, `tipo`, `status`) VALUES
('Super Administrador', 'admin@sige.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'ativo');