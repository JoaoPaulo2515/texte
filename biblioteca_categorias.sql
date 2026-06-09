-- ============================================
-- TABELAS DA BIBLIOTECA
-- ============================================

-- 1. Categorias de Livros
CREATE TABLE biblioteca_categorias (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    nome VARCHAR(100),
    descricao TEXT,
    cor VARCHAR(7),
    status ENUM('ativo', 'inativo'),
    created_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id)
);

-- 2. Gêneros Literários
CREATE TABLE biblioteca_generos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(50),
    descricao TEXT
);

-- 3. Editoras
CREATE TABLE biblioteca_editoras (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100),
    endereco TEXT,
    telefone VARCHAR(20),
    email VARCHAR(100),
    website VARCHAR(200),
    created_at TIMESTAMP
);

-- 4. Autores
CREATE TABLE biblioteca_autores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(150),
    biografia TEXT,
    nacionalidade VARCHAR(50),
    data_nascimento DATE,
    data_falecimento DATE,
    foto VARCHAR(255),
    created_at TIMESTAMP
);

-- 5. Livros (Acervo)
CREATE TABLE biblioteca_livros (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    titulo VARCHAR(255),
    subtitulo VARCHAR(255),
    isbn VARCHAR(20) UNIQUE,
    editora_id INT,
    ano_publicacao YEAR,
    edicao INT,
    numero_paginas INT,
    idioma VARCHAR(30),
    categoria_id INT,
    genero_id INT,
    sinopse TEXT,
    capa VARCHAR(255),
    localizacao VARCHAR(100),
    cdd VARCHAR(50),
    cdu VARCHAR(50),
    quantidade_total INT DEFAULT 1,
    quantidade_disponivel INT DEFAULT 1,
    quantidade_emprestados INT DEFAULT 0,
    quantidade_reservados INT DEFAULT 0,
    status ENUM('disponivel', 'emprestado', 'reservado', 'manutencao', 'extraviado'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id),
    FOREIGN KEY (editora_id) REFERENCES biblioteca_editoras(id),
    FOREIGN KEY (categoria_id) REFERENCES biblioteca_categorias(id),
    FOREIGN KEY (genero_id) REFERENCES biblioteca_generos(id)
);

-- 6. Livro-Autor (Relacionamento N:N)
CREATE TABLE biblioteca_livro_autores (
    livro_id INT,
    autor_id INT,
    PRIMARY KEY (livro_id, autor_id),
    FOREIGN KEY (livro_id) REFERENCES biblioteca_livros(id) ON DELETE CASCADE,
    FOREIGN KEY (autor_id) REFERENCES biblioteca_autores(id) ON DELETE CASCADE
);

-- 7. Exemplares Individuais (Controle Físico)
CREATE TABLE biblioteca_exemplares (
    id INT PRIMARY KEY AUTO_INCREMENT,
    livro_id INT,
    codigo_barras VARCHAR(50) UNIQUE,
    numero_exemplar INT,
    estado ENUM('novo', 'bom', 'regular', 'deteriorado', 'extraviado'),
    observacoes TEXT,
    status ENUM('disponivel', 'emprestado', 'reservado', 'manutencao', 'extraviado'),
    created_at TIMESTAMP,
    FOREIGN KEY (livro_id) REFERENCES biblioteca_livros(id) ON DELETE CASCADE
);

-- 8. Usuários da Biblioteca
CREATE TABLE biblioteca_usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    aluno_id INT NULL,
    funcionario_id INT NULL,
    tipo_usuario ENUM('aluno', 'professor', 'funcionario', 'direcao', 'externo'),
    numero_carteirinha VARCHAR(50) UNIQUE,
    data_cadastro DATE,
    data_validade DATE,
    limite_emprestimos INT DEFAULT 3,
    dias_emprestimo INT DEFAULT 7,
    status ENUM('ativo', 'bloqueado', 'inativo'),
    foto VARCHAR(255),
    created_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id),
    FOREIGN KEY (aluno_id) REFERENCES estudantes(id),
    FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
);

-- 9. Empréstimos
CREATE TABLE biblioteca_emprestimos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    exemplar_id INT,
    data_emprestimo DATETIME,
    data_devolucao_prevista DATE,
    data_devolucao_real DATE,
    status ENUM('ativo', 'devolvido', 'atrasado', 'renovado'),
    renovacoes INT DEFAULT 0,
    observacoes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES biblioteca_usuarios(id),
    FOREIGN KEY (exemplar_id) REFERENCES biblioteca_exemplares(id)
);

-- 10. Reservas
CREATE TABLE biblioteca_reservas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    exemplar_id INT,
    data_reserva DATETIME,
    data_validade DATE,
    data_retirada DATETIME,
    status ENUM('aguardando', 'retirado', 'expirado', 'cancelado'),
    position_fila INT,
    created_at TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES biblioteca_usuarios(id),
    FOREIGN KEY (exemplar_id) REFERENCES biblioteca_exemplares(id)
);

-- 11. Multas
CREATE TABLE biblioteca_multas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    emprestimo_id INT,
    usuario_id INT,
    valor DECIMAL(8,2),
    dias_atraso INT,
    data_geracao DATE,
    data_pagamento DATE,
    status ENUM('pendente', 'pago', 'cancelado'),
    forma_pagamento ENUM('dinheiro', 'transferencia', 'desconto_mensalidade'),
    observacoes TEXT,
    created_at TIMESTAMP,
    FOREIGN KEY (emprestimo_id) REFERENCES biblioteca_emprestimos(id),
    FOREIGN KEY (usuario_id) REFERENCES biblioteca_usuarios(id)
);

-- 12. Configurações da Biblioteca
CREATE TABLE biblioteca_configuracoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    dias_emprestimo_aluno INT DEFAULT 7,
    dias_emprestimo_professor INT DEFAULT 15,
    dias_emprestimo_funcionario INT DEFAULT 10,
    limite_emprestimo_aluno INT DEFAULT 3,
    limite_emprestimo_professor INT DEFAULT 5,
    limite_emprestimo_funcionario INT DEFAULT 3,
    valor_multa_dia DECIMAL(8,2) DEFAULT 50.00,
    dias_reserva_validade INT DEFAULT 3,
    permite_renovacao BOOLEAN DEFAULT TRUE,
    max_renovacoes INT DEFAULT 2,
    notificar_antes_dias INT DEFAULT 3,
    horario_abertura TIME,
    horario_fechamento TIME,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id)
);

-- 13. Log de Atividades da Biblioteca
CREATE TABLE biblioteca_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    acao ENUM('emprestimo', 'devolucao', 'reserva', 'cancelamento', 'multa', 'pagamento'),
    descricao TEXT,
    ip_address VARCHAR(45),
    data_hora DATETIME,
    FOREIGN KEY (usuario_id) REFERENCES biblioteca_usuarios(id)
);

-- 14. Sugestões de Aquisição
CREATE TABLE biblioteca_sugestoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT,
    titulo VARCHAR(255),
    autor VARCHAR(150),
    editora VARCHAR(100),
    ano_publicacao YEAR,
    isbn VARCHAR(20),
    motivo TEXT,
    status ENUM('pendente', 'aprovado', 'rejeitado', 'adquirido'),
    data_sugestao DATETIME,
    data_resposta DATETIME,
    resposta TEXT,
    FOREIGN KEY (usuario_id) REFERENCES biblioteca_usuarios(id)
);