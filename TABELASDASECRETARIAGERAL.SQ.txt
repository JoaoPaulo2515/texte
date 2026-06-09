-- ============================================
-- TABELAS DA SECRETARIA GERAL
-- ============================================

-- 1. Estudantes (Alunos)
CREATE TABLE estudantes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    usuario_id INT,
    nome VARCHAR(200),
    nome_pai VARCHAR(200),
    nome_mae VARCHAR(200),
    data_nascimento DATE,
    genero ENUM('M', 'F'),
    bi VARCHAR(20) UNIQUE,
    passaporte VARCHAR(20),
    nif VARCHAR(20),
    numero_processo VARCHAR(20) UNIQUE,
    naturalidade VARCHAR(100),
    nacionalidade VARCHAR(50),
    endereco TEXT,
    bairro VARCHAR(100),
    municipio VARCHAR(100),
    provincia VARCHAR(50),
    telefone VARCHAR(20),
    telefone_alternativo VARCHAR(20),
    email VARCHAR(100),
    email_alternativo VARCHAR(100),
    foto VARCHAR(255),
    data_cadastro DATE,
    status ENUM('ativo', 'inativo', 'transferido', 'cancelado', 'concluido'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id)
);

-- 2. Matrículas
CREATE TABLE matriculas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    turma_id INT,
    ano_letivo_id INT,
    numero_matricula VARCHAR(20) UNIQUE,
    data_matricula DATE,
    tipo ENUM('nova', 'rematricula', 'transferencia', 'reintegracao'),
    status ENUM('ativa', 'trancada', 'cancelada', 'concluida', 'transferida'),
    origem_escola_id INT NULL,
    data_transferencia DATE NULL,
    motivo_cancelamento TEXT,
    observacoes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id),
    FOREIGN KEY (turma_id) REFERENCES turmas(id),
    FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id)
);

-- 3. Responsáveis
CREATE TABLE responsaveis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(200),
    bi VARCHAR(20) UNIQUE,
    nif VARCHAR(20),
    data_nascimento DATE,
    genero ENUM('M', 'F'),
    parentesco ENUM('pai', 'mae', 'tutor', 'avô', 'avó', 'tio', 'tia', 'outro'),
    telefone VARCHAR(20),
    telefone_alternativo VARCHAR(20),
    email VARCHAR(100),
    endereco TEXT,
    profissao VARCHAR(100),
    empresa VARCHAR(100),
    renda_mensal DECIMAL(10,2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- 4. Aluno-Responsável (Relacionamento)
CREATE TABLE aluno_responsavel (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    responsavel_id INT,
    principal BOOLEAN DEFAULT FALSE,
    autorizado_retirada BOOLEAN DEFAULT TRUE,
    receber_comunicados BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id),
    FOREIGN KEY (responsavel_id) REFERENCES responsaveis(id)
);

-- 5. Documentos do Aluno
CREATE TABLE documentos_aluno (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    tipo ENUM('certidao_nascimento', 'bi', 'nif', 'comprovante_residencia', 'atestado_medico', 
              'foto', 'historico_anterior', 'declaracao_vacinacao', 'termo_responsabilidade', 'outro'),
    arquivo VARCHAR(255),
    data_upload DATE,
    validade DATE,
    observacoes TEXT,
    created_at TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id)
);

-- 6. Histórico Escolar
CREATE TABLE historico_escolar (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    escola_id INT,
    ano_letivo VARCHAR(20),
    serie_classe VARCHAR(20),
    status ENUM('cursando', 'aprovado', 'reprovado', 'transferido'),
    media_final DECIMAL(5,2),
    frequencia_final DECIMAL(5,2),
    boletim VARCHAR(255),
    data_emissao DATE,
    created_at TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id)
);

-- 7. Certificados e Declarações
CREATE TABLE certificados_declaracoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    tipo ENUM('certificado_conclusao', 'declaracao_matricula', 'declaracao_frequencia', 
              'declaracao_transferencia', 'atestado', 'histórico'),
    numero_documento VARCHAR(50),
    data_emissao DATE,
    data_validade DATE,
    arquivo VARCHAR(255),
    motivo VARCHAR(200),
    emitido_por INT,
    status ENUM('ativo', 'cancelado'),
    created_at TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id)
);

-- 8. Ocorrências Disciplinares
CREATE TABLE ocorrencias (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estudante_id INT,
    turma_id INT,
    tipo ENUM('advertencia_verbal', 'advertencia_escrita', 'suspensao', 'transferencia', 'expulsao', 'elogio'),
    data_ocorrencia DATE,
    descricao TEXT,
    testemunhas TEXT,
    aplicado_por INT,
    data_reuniao DATE,
    encaminhamentos TEXT,
    status ENUM('aberto', 'analise', 'concluido', 'arquivado'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (estudante_id) REFERENCES estudantes(id),
    FOREIGN KEY (turma_id) REFERENCES turmas(id)
);

-- 9. Protocolo de Documentos
CREATE TABLE protocolo (
    id INT PRIMARY KEY AUTO_INCREMENT,
    numero_protocolo VARCHAR(50) UNIQUE,
    tipo ENUM('entrada', 'saida'),
    interessado VARCHAR(200),
    assunto VARCHAR(255),
    descricao TEXT,
    data_recebimento DATE,
    data_resposta DATE,
    documento VARCHAR(255),
    destinatario VARCHAR(200),
    status ENUM('recebido', 'em_analise', 'respondido', 'arquivado'),
    usuario_responsavel INT,
    observacoes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- 10. Atas de Conselho de Classe
CREATE TABLE atas_conselho (
    id INT PRIMARY KEY AUTO_INCREMENT,
    turma_id INT,
    data_reuniao DATE,
    ano_letivo_id INT,
    bimestre INT,
    pauta TEXT,
    participantes TEXT,
    deliberacoes TEXT,
    encaminhamentos TEXT,
    ata_pdf VARCHAR(255),
    presidente INT,
    secretario INT,
    data_aprovacao DATE,
    status ENUM('rascunho', 'aprovada', 'arquivada'),
    created_at TIMESTAMP,
    FOREIGN KEY (turma_id) REFERENCES turmas(id),
    FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id)
);

-- 11. Calendário Escolar
CREATE TABLE calendario_escolar (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    ano_letivo_id INT,
    titulo VARCHAR(200),
    descricao TEXT,
    tipo ENUM('aula', 'prova', 'reuniao', 'evento', 'feriado', 'recesso'),
    data_inicio DATE,
    data_fim DATE,
    horario TIME,
    publico_alvo VARCHAR(100),
    local VARCHAR(200),
    status ENUM('agendado', 'realizado', 'cancelado'),
    created_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id),
    FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id)
);

-- 12. Configurações da Secretaria
CREATE TABLE config_secretaria (
    id INT PRIMARY KEY AUTO_INCREMENT,
    escola_id INT,
    prefixo_matricula VARCHAR(10),
    mascara_matricula VARCHAR(50),
    prefixo_protocolo VARCHAR(10),
    dias_rematricula INT,
    documentos_obrigatorios TEXT,
    modelos_documentos TEXT,
    assinaturas_eletronicas TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id)
);