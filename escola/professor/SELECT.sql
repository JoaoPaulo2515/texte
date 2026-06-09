SELECT 
    e.id,
    e.nome,
    e.foto,
    e.bi,
    m.id as matricula_id,
    m.numero_processo,
    t.nome as turma,
    t.ano,
    esc.nome as escola,
    d.nome as disciplina,
    n.bimestre,
    COALESCE(n.media_parcial, 0) as nota_parcial,
    COALESCE(n.media_final, 0) as nota_final,
    CASE 
        WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 10 THEN 'Aprovado'
        WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 7 THEN 'Recuperação'
        ELSE 'Reprovado'
    END as situacao
FROM estudantes e
INNER JOIN matriculas m ON m.estudante_id = e.id
INNER JOIN turmas t ON t.id = m.turma_id
INNER JOIN escolas esc ON esc.id = t.escola_id
INNER JOIN disciplinas d ON d.id = $disciplina_id
LEFT JOIN notas n ON n.estudante_id = e.id 
    AND n.disciplina_id = d.id
    AND n.bimestre =  $bimestre
    AND n.ano_letivo_id = m.ano_letivo
WHERE m.turma_id = $turma_id
    AND t.escola_id = $escola_id
    AND m.status = 'ativa'
    AND m.ano_letivo = $ano_letivo_id
ORDER BY e.nome


SELECT 
    e.id,
    e.nome,
    e.foto,
    e.bi,
    m.id as matricula_id,
    m.numero_processo,
    t.nome as turma,
    t.ano,
    esc.nome as escola,
    d.nome as disciplina,
    n.bimestre,
    COALESCE(n.media_parcial, 0) as nota_parcial,
    COALESCE(n.media_final, 0) as nota_final,
    CASE 
        WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 10 THEN 'Aprovado'
        WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 7 THEN 'Recuperação'
        ELSE 'Reprovado'
    END as situacao
FROM estudantes e
INNER JOIN matriculas m ON m.estudante_id = e.id
INNER JOIN turmas t ON t.id = m.turma_id
INNER JOIN escolas esc ON esc.id = t.escola_id
INNER JOIN disciplinas d ON d.id = :disciplina_id
LEFT JOIN notas n ON n.estudante_id = e.id 
    AND n.disciplina_id = d.id
    AND n.bimestre = :bimestre
    AND n.ano_letivo_id = m.ano_letivo
WHERE m.turma_id = :turma_id
    AND t.escola_id = :escola_id
    AND m.status = 'ativa'
    AND m.ano_letivo = :ano_letivo_id
ORDER BY e.nome




if (isset($_GET['visualizar']) && isset($_GET['id'])) {
    $material_id = (int)$_GET['id'];
    
    // Buscar dados do material
    $sql = "SELECT * FROM materiais_didaticos WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $material_id, ':escola_id' => $escola_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($material) {
        // Atualizar visualizações
        $conn->prepare("UPDATE materiais_didaticos SET visualizacoes = visualizacoes + 1 WHERE id = :id")
            ->execute([':id' => $material_id]);
        
        $tipo = $material['tipo'];
        $conteudo = $material['conteudo'] ?? '';
        $link = $material['link'] ?? '';
        $arquivo = $material['arquivo'] ?? '';
        
        echo '<!DOCTYPE html>
        <html lang="pt-AO">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($material['titulo']) . ' - Visualização</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
            <style>
                body { background: #f5f7fb; font-family: Arial, sans-serif; }
                .header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 15px 20px; margin-bottom: 20px; }
                .content-container { background: white; border-radius: 15px; padding: 30px; margin: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .material-info { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
                .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; }
                .btn-voltar:hover { background: #5a6268; color: white; }
                .material-viewer { min-height: 500px; }
                iframe { width: 100%; height: 600px; border: none; border-radius: 10px; }
                .pdf-viewer { width: 100%; height: 700px; border: 1px solid #ddd; border-radius: 10px; }
                .video-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; }
                .video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
                .text-content { font-size: 16px; line-height: 1.6; text-align: justify; }
                .image-viewer { text-align: center; }
                .image-viewer img { max-width: 100%; max-height: 600px; border-radius: 10px; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="container-fluid">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3><i class="fas fa-book-open"></i> ' . htmlspecialchars($material['titulo']) . '</h3>
                            <small>' . ucfirst($tipo) . ' - ' . htmlspecialchars($material['categoria']) . '</small>
                        </div>
                        <div>
                            <button class="btn btn-light btn-sm me-2" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                            <button class="btn btn-light btn-sm" onclick="window.close()"><i class="fas fa-times"></i> Fechar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-container">
                <div class="material-info">
                    <div class="row">
                        <div class="col-md-8">
                            <p><strong><i class="fas fa-tag"></i> Tipo:</strong> ' . ucfirst($tipo) . '</p>
                            <p><strong><i class="fas fa-user"></i> Autor:</strong> ' . htmlspecialchars($material['autor'] ?? 'Não informado') . '</p>
                            <p><strong><i class="fas fa-calendar"></i> Adicionado:</strong> ' . date('d/m/Y', strtotime($material['created_at'])) . '</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="biblioteca.php?download=' . $material['id'] . '" class="btn btn-success"><i class="fas fa-download"></i> Baixar Material</a>
                        </div>
                    </div>
                </div>
                <div class="material-viewer">';
        
        // VERIFICAR TIPO DE MATERIAL E EXIBIR
        if (!empty($link)) {
            // É um link externo (YouTube, Vimeo, etc.)
            echo '<div class="video-container">
                    <iframe src="' . $link . '" frameborder="0" allowfullscreen></iframe>
                  </div>';
        } 
        elseif (!empty($arquivo)) {
            // É um arquivo local
            $extensao = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
            $caminho_completo = __DIR__ . '/../../' . $arquivo;
            
            if (file_exists($caminho_completo)) {
                if ($extensao == 'pdf') {
                    echo '<iframe src="' . $arquivo . '" class="pdf-viewer"></iframe>';
                } elseif (in_array($extensao, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    echo '<div class="image-viewer">
                            <img src="' . $arquivo . '" alt="' . htmlspecialchars($material['titulo']) . '">
                          </div>';
                } elseif (in_array($extensao, ['mp4', 'webm', 'ogg'])) {
                    echo '<div class="video-container">
                            <video controls style="width:100%; height:100%;">
                                <source src="' . $arquivo . '" type="video/' . $extensao . '">
                                Seu navegador não suporta vídeo.
                            </video>
                          </div>';
                } else {
                    echo '<div class="alert alert-info">
                            <i class="fas fa-file"></i> Este arquivo pode ser baixado clicando no botão acima.
                          </div>';
                }
            } else {
                echo '<div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Arquivo não encontrado.
                      </div>';
            }
        } 
        elseif (!empty($conteudo)) {
            // É conteúdo de texto (HTML)
            echo '<div class="text-content">' . $conteudo . '</div>';
        } 
        else {
            echo '<div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>Este material não está disponível para visualização online</h5>
                    <p>Use o botão "Baixar Material" para fazer o download.</p>
                </div>';
        }
        
        echo '      </div>
            </div>
        </body>
        </html>';
        exit;
    }
}

Erro ao salvar nota