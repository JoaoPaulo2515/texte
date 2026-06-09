<?php
// install.php - Instalador do Sistema SIGE Angola (Apenas Super Admin)
session_start();

// ============================================
// CONFIGURAÇÕES INICIAIS
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Africa/Luanda');

// Criar diretórios necessários
$directories = ['config', 'logs', 'uploads', 'uploads/escolas', 'uploads/alunos', 'uploads/alunos/fotos', 'uploads/alunos/documentos', 'uploads/professores', 'uploads/professores/fotos', 'uploads/professores/documentos', 'uploads/livros', 'uploads/livros/capas', 'uploads/livros/arquivos', 'uploads/comprovantes', 'assets/cache', 'assets/images', 'assets/fonts'];
foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
        echo "✓ Diretório criado: {$dir}<br>";
    }
}

// Verificar se já está instalado
$lockFile = __DIR__ . '/config/installed.lock';

if (file_exists($lockFile) && !isset($_GET['force'])) {
    header('Location: index.php');
    exit;
}

// Se veio com force=1, permite reinstalação
if (isset($_GET['force']) && $_GET['force'] == 1) {
    if (file_exists($lockFile)) {
        rename($lockFile, $lockFile . '.bak.' . date('Ymd_His'));
        echo "<div class='alert alert-warning'>Modo de reinstalação ativado. Backup criado.</div>";
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function checkRequirements() {
    $requirements = [];
    
    // PHP Version
    $requirements[] = [
        'name' => 'PHP >= 7.4',
        'status' => version_compare(PHP_VERSION, '7.4', '>='),
        'current' => PHP_VERSION
    ];
    
    // Extensões obrigatórias
    $required_extensions = ['pdo_mysql', 'json', 'mbstring', 'openssl'];
    foreach ($required_extensions as $ext) {
        $requirements[] = [
            'name' => "Extensão: {$ext}",
            'status' => extension_loaded($ext),
            'current' => extension_loaded($ext) ? 'OK' : 'Faltando'
        ];
    }
    
    // Extensões opcionais
    $optional_extensions = ['gd', 'curl', 'zip'];
    foreach ($optional_extensions as $ext) {
        $requirements[] = [
            'name' => "Extensão: {$ext} (Opcional)",
            'status' => extension_loaded($ext) ? true : true,
            'current' => extension_loaded($ext) ? 'OK' : 'Não instalado (funcionalidade limitada)'
        ];
    }
    
    // Permissões de escrita
    $dirs = ['config', 'logs', 'uploads', 'assets/cache'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        $requirements[] = [
            'name' => "Permissão: /{$dir}",
            'status' => is_writable($path),
            'current' => is_writable($path) ? 'Gravável' : 'Sem permissão'
        ];
    }
    
    return $requirements;
}

function saveDatabaseConfig($host, $dbname, $user, $pass) {
    $config = "<?php\n";
    $config .= "// db_config.php - Configuração do banco de dados\n";
    $config .= "// Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "return [\n";
    $config .= "    'host' => '{$host}',\n";
    $config .= "    'dbname' => '{$dbname}',\n";
    $config .= "    'username' => '{$user}',\n";
    $config .= "    'password' => '{$pass}',\n";
    $config .= "    'charset' => 'utf8mb4'\n";
    $config .= "];\n";
    $config .= "?>";
    
    file_put_contents(__DIR__ . '/config/db_config.php', $config);
}

function getInstallSQL() {
    return "
    -- ============================================
    -- TABELAS DO SISTEMA SIGE ANGOLA
    -- ============================================

    -- 1. configuracoes_sistema
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

    -- 2. planos
    CREATE TABLE IF NOT EXISTS `planos` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `descricao` TEXT,
        `preco_mensal` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `preco_anual` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `limite_alunos` INT DEFAULT 0,
        `limite_professores` INT DEFAULT 0,
        `limite_turmas` INT DEFAULT 0,
        `modulos_disponiveis` JSON,
        `recursos` JSON,
        `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 3. escolas
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
        `nuit` VARCHAR(20) NULL,
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
        INDEX idx_provincia (provincia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 4. usuarios
    CREATE TABLE IF NOT EXISTS `usuarios` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NULL,
        `nome` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) UNIQUE NOT NULL,
        `senha` VARCHAR(255) NOT NULL,
        `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno', 'pai', 'funcionario') NOT NULL,
        `telefone` VARCHAR(20) NULL,
        `celular` VARCHAR(20) NULL,
        `foto` VARCHAR(255) NULL,
        `bi` VARCHAR(20) NULL,
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
        INDEX idx_escola (escola_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 5. recuperacao_senha
    CREATE TABLE IF NOT EXISTS `recuperacao_senha` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `usuario_id` INT NOT NULL,
        `token` VARCHAR(100) NOT NULL,
        `expira` DATETIME NOT NULL,
        `usado` BOOLEAN DEFAULT FALSE,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 6. assinaturas
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
        INDEX idx_data_fim (data_fim)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 7. pagamentos
    CREATE TABLE IF NOT EXISTS `pagamentos` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NOT NULL,
        `assinatura_id` INT NOT NULL,
        `valor` DECIMAL(10,2) NOT NULL,
        `referente` VARCHAR(100) NULL,
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
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 8. turmas
    CREATE TABLE IF NOT EXISTS `turmas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NOT NULL,
        `nome` VARCHAR(50) NOT NULL,
        `ano` VARCHAR(20) NOT NULL,
        `turno` ENUM('manha', 'tarde', 'noite') NOT NULL,
        `ano_letivo` YEAR NOT NULL,
        `capacidade` INT DEFAULT 30,
        `sala` VARCHAR(20) NULL,
        `status` ENUM('ativa', 'encerrada') DEFAULT 'ativa',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_escola_ano (escola_id, ano_letivo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 9. disciplinas
    CREATE TABLE IF NOT EXISTS `disciplinas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NOT NULL,
        `nome` VARCHAR(100) NOT NULL,
        `codigo` VARCHAR(20) NULL,
        `carga_horaria` INT NULL,
        `descricao` TEXT NULL,
        `status` ENUM('ativa', 'inativa') DEFAULT 'ativa',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_escola (escola_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 10. professores
    CREATE TABLE IF NOT EXISTS `professores` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `usuario_id` INT NOT NULL,
        `escola_id` INT NOT NULL,
        `especialidade` VARCHAR(255) NULL,
        `formacao` TEXT NULL,
        `data_admissao` DATE NULL,
        `bi` VARCHAR(20) NULL,
        `foto` VARCHAR(255) NULL,
        `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_escola (escola_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 11. alocacoes
    CREATE TABLE IF NOT EXISTS `alocacoes` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `professor_id` INT NOT NULL,
        `disciplina_id` INT NOT NULL,
        `turma_id` INT NULL,
        `ano_letivo` YEAR NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE CASCADE,
        FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
        FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
        INDEX idx_professor (professor_id),
        INDEX idx_disciplina (disciplina_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 12. estudantes
    CREATE TABLE IF NOT EXISTS `estudantes` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `usuario_id` INT NOT NULL,
        `escola_id` INT NOT NULL,
        `matricula` VARCHAR(50) UNIQUE NOT NULL,
        `bi` VARCHAR(20) NULL,
        `data_nascimento` DATE NULL,
        `genero` ENUM('M', 'F') NULL,
        `endereco` TEXT NULL,
        `encarregado_nome` VARCHAR(100) NULL,
        `encarregado_telefone` VARCHAR(20) NULL,
        `encarregado_email` VARCHAR(100) NULL,
        `foto` VARCHAR(255) NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_matricula (matricula),
        INDEX idx_escola (escola_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 13. matriculas
    CREATE TABLE IF NOT EXISTS `matriculas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `estudante_id` INT NOT NULL,
        `turma_id` INT NOT NULL,
        `ano_letivo` YEAR NOT NULL,
        `data_matricula` DATE DEFAULT CURRENT_DATE,
        `status` ENUM('ativa', 'transferido', 'concluido', 'desistente') DEFAULT 'ativa',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (estudante_id) REFERENCES estudantes(id) ON DELETE CASCADE,
        FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
        INDEX idx_estudante (estudante_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 14. notas
    CREATE TABLE IF NOT EXISTS `notas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `matricula_id` INT NOT NULL,
        `disciplina_id` INT NOT NULL,
        `bimestre` INT NOT NULL,
        `mac` DECIMAL(5,2) NULL,
        `npt` DECIMAL(5,2) NULL,
        `exame_normal` DECIMAL(5,2) NULL,
        `exame_recurso` DECIMAL(5,2) NULL,
        `media` DECIMAL(5,2) NULL,
        `media_final` DECIMAL(5,2) NULL,
        `status` ENUM('aprovado', 'reprovado', 'recuperacao', 'exame') DEFAULT 'exame',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE,
        FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
        INDEX idx_matricula (matricula_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 15. presencas
    CREATE TABLE IF NOT EXISTS `presencas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `matricula_id` INT NOT NULL,
        `data` DATE NOT NULL,
        `presente` BOOLEAN DEFAULT TRUE,
        `justificativa` TEXT NULL,
        `tipo_falta` ENUM('justificada', 'injustificada') NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE,
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 16. livros
    CREATE TABLE IF NOT EXISTS `livros` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NOT NULL,
        `titulo` VARCHAR(200) NOT NULL,
        `autor` VARCHAR(100) NULL,
        `categoria` VARCHAR(50) NULL,
        `descricao` TEXT NULL,
        `capa` VARCHAR(255) NULL,
        `arquivo` VARCHAR(255) NOT NULL,
        `visualizacoes` INT DEFAULT 0,
        `downloads` INT DEFAULT 0,
        `status` ENUM('disponivel', 'indisponivel') DEFAULT 'disponivel',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_escola (escola_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 17. tickets_suporte
    CREATE TABLE IF NOT EXISTS `tickets_suporte` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NOT NULL,
        `usuario_id` INT NULL,
        `assunto` VARCHAR(200) NOT NULL,
        `mensagem` TEXT NOT NULL,
        `prioridade` ENUM('baixa', 'media', 'alta', 'urgente') DEFAULT 'media',
        `status` ENUM('aberto', 'em_andamento', 'respondido', 'fechado') DEFAULT 'aberto',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_escola (escola_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 18. ticket_respostas
    CREATE TABLE IF NOT EXISTS `ticket_respostas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `ticket_id` INT NOT NULL,
        `usuario_id` INT NULL,
        `mensagem` TEXT NOT NULL,
        `is_admin` BOOLEAN DEFAULT FALSE,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets_suporte(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 19. notificacoes
    CREATE TABLE IF NOT EXISTS `notificacoes` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NULL,
        `usuario_id` INT NULL,
        `titulo` VARCHAR(200) NOT NULL,
        `mensagem` TEXT NOT NULL,
        `tipo` ENUM('info', 'sucesso', 'aviso', 'erro') DEFAULT 'info',
        `lida` BOOLEAN DEFAULT FALSE,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        INDEX idx_escola (escola_id),
        INDEX idx_lida (lida)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 20. logs_sistema
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
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE SET NULL,
        INDEX idx_usuario (usuario_id),
        INDEX idx_acao (acao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 21. email_queue
    CREATE TABLE IF NOT EXISTS `email_queue` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `destinatario` VARCHAR(255) NOT NULL,
        `assunto` VARCHAR(255) NOT NULL,
        `mensagem` TEXT NOT NULL,
        `status` ENUM('pendente', 'enviado', 'falha') DEFAULT 'pendente',
        `tentativas` INT DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 22. feriados
    CREATE TABLE IF NOT EXISTS `feriados` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `data` DATE NOT NULL,
        `tipo` ENUM('nacional', 'provincial', 'municipal') DEFAULT 'nacional',
        `descricao` TEXT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 23. angola_provincias
    CREATE TABLE IF NOT EXISTS `angola_provincias` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL UNIQUE,
        `sigla` VARCHAR(10) NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 24. angola_municipios
    CREATE TABLE IF NOT EXISTS `angola_municipios` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `provincia_id` INT NOT NULL,
        `nome` VARCHAR(100) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (provincia_id) REFERENCES angola_provincias(id) ON DELETE CASCADE,
        UNIQUE KEY unique_municipio (provincia_id, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 25. angola_comunas
    CREATE TABLE IF NOT EXISTS `angola_comunas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `municipio_id` INT NOT NULL,
        `nome` VARCHAR(100) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (municipio_id) REFERENCES angola_municipios(id) ON DELETE CASCADE,
        UNIQUE KEY unique_comuna (municipio_id, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ============================================
    -- DADOS INICIAIS
    -- ============================================

    -- Planos padrão
    INSERT INTO `planos` (`nome`, `descricao`, `preco_mensal`, `preco_anual`, `status`) VALUES
    ('Básico', 'Plano ideal para pequenas escolas', 19900.00, 199000.00, 'ativo'),
    ('Profissional', 'Plano completo para escolas em crescimento', 39900.00, 399000.00, 'ativo'),
    ('Empresarial', 'Plano premium para grandes instituições', 79900.00, 799000.00, 'ativo');

    -- Configurações padrão do sistema
    INSERT INTO `configuracoes_sistema` (
        `nome_sistema`, `sigla`, `versao`, `timezone`, `moeda`, `moeda_simbolo`,
        `ano_letivo_atual`, `bimestre_atual`, `nota_maxima`, `nota_minima_aprovacao`,
        `permite_recuperacao`, `limite_faltas`
    ) VALUES (
        'SIGE Angola', 'SIGE', '2.0.0', 'Africa/Luanda', 'KZ', 'KZ',
        YEAR(CURDATE()), 1, 20, 10, 1, 20
    );

    -- Províncias de Angola
    INSERT INTO `angola_provincias` (`nome`, `sigla`) VALUES
    ('Bengo', 'BGO'), ('Benguela', 'BGU'), ('Bié', 'BIE'), ('Cabinda', 'CAB'),
    ('Cuando Cubango', 'CCU'), ('Cuanza Norte', 'CNO'), ('Cuanza Sul', 'CSU'),
    ('Cunene', 'CNN'), ('Huambo', 'HUA'), ('Huíla', 'HUI'), ('Luanda', 'LAD'),
    ('Lunda Norte', 'LNO'), ('Lunda Sul', 'LSU'), ('Malanje', 'MAL'),
    ('Moxico', 'MOX'), ('Namibe', 'NAM'), ('Uíge', 'UIG'), ('Zaire', 'ZAI');

    -- Feriados nacionais de Angola
    INSERT INTO `feriados` (`nome`, `data`, `tipo`, `descricao`) VALUES
    ('Ano Novo', '2026-01-01', 'nacional', 'Confraternização Universal'),
    ('Dia da Liberdade', '2026-01-04', 'nacional', 'Fim do regime colonial'),
    ('Dia dos Mártires', '2026-02-04', 'nacional', 'Início da luta armada'),
    ('Dia da Mulher Angolana', '2026-03-02', 'nacional', 'Homenagem à mulher angolana'),
    ('Dia da Paz', '2026-04-04', 'nacional', 'Assinatura dos Acordos de Paz'),
    ('Dia do Trabalhador', '2026-05-01', 'nacional', 'Dia Internacional do Trabalhador'),
    ('Dia do Herói Nacional', '2026-09-17', 'nacional', 'Aniversário de Agostinho Neto'),
    ('Dia das Forças Armadas', '2026-10-01', 'nacional', 'Dia das FAA'),
    ('Dia do Herói Nacional', '2026-11-11', 'nacional', 'Independência Nacional'),
    ('Natal', '2026-12-25', 'nacional', 'Natal');
    ";
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Verificar requisitos
        $requirements = checkRequirements();
        $allOk = true;
        foreach ($requirements as $req) {
            if (!$req['status'] && strpos($req['name'], 'Opcional') === false) {
                $allOk = false;
                break;
            }
        }
        
        if ($allOk) {
            header('Location: install.php?step=2');
            exit;
        } else {
            $error = 'Por favor, corrija os requisitos acima.';
        }
    } elseif ($step == 2) {
        // Configurar banco de dados
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_name = $_POST['db_name'] ?? 'sige_angola';
        $db_user = $_POST['db_user'] ?? 'root';
        $db_pass = $_POST['db_pass'] ?? '';
        
        try {
            // Testar conexão
            $pdo = new PDO("mysql:host={$db_host}", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Criar banco de dados
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");
            
            // Salvar configuração
            saveDatabaseConfig($db_host, $db_name, $db_user, $db_pass);
            
            // Criar tabelas
            $sql = getInstallSQL();
            $pdo->exec($sql);
            
            $_SESSION['db_installed'] = true;
            header('Location: install.php?step=3');
            exit;
            
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif ($step == 3) {
        // Criar Super Administrador
        $admin_name = $_POST['admin_name'] ?? '';
        $admin_email = $_POST['admin_email'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
        
        if ($admin_password !== $admin_password_confirm) {
            $error = 'As senhas não coincidem.';
        } elseif (strlen($admin_password) < 6) {
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } else {
            try {
                require_once __DIR__ . '/config/database.php';
                $db = Database::getInstance();
                $conn = $db->getConnection();
                
                $conn->beginTransaction();
                
                // Criar Super Administrador
                $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO usuarios (nome, email, senha, tipo, status, created_at)
                    VALUES (:nome, :email, :senha, 'super_admin', 'ativo', NOW())
                ");
                $stmt->execute([
                    ':nome' => $admin_name,
                    ':email' => $admin_email,
                    ':senha' => $hashed_password
                ]);
                
                $conn->commit();
                
                // Criar arquivo de lock
                $lockData = [
                    'installed_at' => date('Y-m-d H:i:s'),
                    'version' => '2.0.0',
                    'admin_email' => $admin_email,
                    'admin_name' => $admin_name
                ];
                file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT));
                
                header('Location: install.php?step=4');
                exit;
                
            } catch (Exception $e) {
                if (isset($conn)) $conn->rollBack();
                $error = "Erro: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .install-container {
            max-width: 800px;
            margin: 50px auto;
        }
        .card {
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
        .card-header .logo {
            font-size: 3em;
            margin-bottom: 15px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step .circle {
            width: 40px;
            height: 40px;
            background: #e0e0e0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #666;
        }
        .step.active .circle {
            background: #006B3E;
            color: white;
        }
        .step.completed .circle {
            background: #28a745;
            color: white;
        }
        .step .label {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
        .step.active .label {
            color: #006B3E;
            font-weight: bold;
        }
        .requirement-item {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .requirement-ok {
            background: #d4edda;
            color: #155724;
        }
        .requirement-error {
            background: #f8d7da;
            color: #721c24;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
            padding: 12px 30px;
        }
        .btn-primary:hover {
            background: #004d2d;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="card">
            <div class="card-header">
                <div class="logo">
                    <i class="fas fa-chalkboard-user"></i>
                </div>
                <h2 class="mb-0">SIGE Angola</h2>
                <p class="mb-0 mt-2">Sistema Integrado de Gestão Escolar</p>
            </div>
            <div class="card-body p-4">
                <div class="step-indicator">
                    <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 1 ? '<i class="fas fa-check"></i>' : '1'; ?></div>
                        <div class="label">Requisitos</div>
                    </div>
                    <div class="step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 2 ? '<i class="fas fa-check"></i>' : '2'; ?></div>
                        <div class="label">Banco de Dados</div>
                    </div>
                    <div class="step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 3 ? '<i class="fas fa-check"></i>' : '3'; ?></div>
                        <div class="label">Super Admin</div>
                    </div>
                    <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                        <div class="circle">4</div>
                        <div class="label">Concluído</div>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                    <h4 class="mb-3">Verificação de Requisitos</h4>
                    <?php $requirements = checkRequirements(); ?>
                    <?php foreach ($requirements as $req): ?>
                        <div class="requirement-item <?php echo $req['status'] ? 'requirement-ok' : 'requirement-error'; ?>">
                            <span><?php echo $req['name']; ?></span>
                            <span><?php echo $req['status'] ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>'; ?> <?php echo $req['current']; ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <form method="POST" class="mt-4">
                        <button type="submit" class="btn btn-primary w-100">
                            Continuar <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 2): ?>
                    <h4 class="mb-3">Configuração do Banco de Dados</h4>
                    <form method="POST">
                        <div class="mb-3">
                            <label>Host</label>
                            <input type="text" name="db_host" class="form-control" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label>Nome do Banco</label>
                            <input type="text" name="db_name" class="form-control" value="sige_angola" required>
                        </div>
                        <div class="mb-3">
                            <label>Usuário</label>
                            <input type="text" name="db_user" class="form-control" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label>Senha</label>
                            <input type="password" name="db_pass" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            Testar e Instalar <i class="fas fa-database"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 3): ?>
                    <h4 class="mb-3">Criar Super Administrador</h4>
                    <p class="text-muted mb-4">O Super Administrador terá acesso total ao sistema para gerenciar escolas, planos e configurações.</p>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label>Nome Completo</label>
                            <input type="text" name="admin_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>E-mail</label>
                            <input type="email" name="admin_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Senha</label>
                            <input type="password" name="admin_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Confirmar Senha</label>
                            <input type="password" name="admin_password_confirm" class="form-control" required>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            <strong>Após a instalação:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Faça login com o e-mail e senha cadastrados</li>
                                <li>Acesse o painel Super Admin</li>
                                <li>Crie os planos de assinatura (ou use os padrões)</li>
                                <li>Cadastre as escolas no sistema</li>
                                <li>Cada escola terá seu próprio ambiente</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            Finalizar Instalação <i class="fas fa-check-circle"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 4): ?>
                    <div class="text-center">
                        <i class="fas fa-check-circle fa-5x text-success mb-3"></i>
                        <h4>Instalação Concluída!</h4>
                        <p class="text-muted">O sistema foi instalado com sucesso.</p>
                        
                        <div class="alert alert-info mt-4">
                            <strong>Dados de Acesso do Super Administrador:</strong><br>
                            E-mail: <?php echo htmlspecialchars($admin_email ?? 'admin@sige.ao'); ?><br>
                            Senha: (a que você definiu)
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-shield-alt"></i> <strong>Recomendação de segurança:</strong><br>
                            Renomeie ou remova o arquivo <strong>install.php</strong> após a instalação.
                        </div>
                        
                        <div class="alert alert-success">
                            <i class="fas fa-school"></i> <strong>Próximos passos:</strong>
                            <ul class="text-start mt-2">
                                <li>Faça login no sistema como Super Administrador</li>
                                <li>Configure os planos de assinatura</li>
                                <li>Cadastre as escolas</li>
                                <li>Cada escola terá seu próprio domínio/subdomínio</li>
                                <li>Os administradores das escolas gerirão seus próprios dados</li>
                            </ul>
                        </div>
                        
                        <a href="login.php" class="btn btn-primary btn-lg mt-3">
                            <i class="fas fa-sign-in-alt"></i> Acessar o Sistema
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>