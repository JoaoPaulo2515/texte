<?php
// escola/aluno/provas/listar_provas.php - Lista de Provas Disponíveis

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar provas disponíveis para o aluno
$sql = "SELECT p.*, d.nome as disciplina_nome, 
               CASE 
                   WHEN NOW() < p.data_inicio THEN 'pendente'
                   WHEN NOW() BETWEEN p.data_inicio AND p.data_fim THEN 'disponivel'
                   ELSE 'encerrada'
               END as status_aluno,
               (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND aluno_id = :aluno_id) as tentativas_realizadas,
               (SELECT MAX(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND aluno_id = :aluno_id) as melhor_nota
        FROM online_provas p
        JOIN disciplinas d ON d.id = p.disciplina_id
        WHERE p.escola_id = :escola_id
        AND p.status = 'agendada'
        AND (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND aluno_id = :aluno_id AND status = 'finalizada') < p.tentativas_permitidas
        ORDER BY p.data_inicio ASC";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$provas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- HTML para listar provas -->
<div class="container mt-4">
    <h2>Provas Online Disponíveis</h2>
    
    <?php foreach ($provas as $prova): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h5><?php echo htmlspecialchars($prova['titulo']); ?></h5>
            <p><?php echo htmlspecialchars($prova['descricao']); ?></p>
            <p><strong>Disciplina:</strong> <?php echo $prova['disciplina_nome']; ?></p>
            <p><strong>Duração:</strong> <?php echo $prova['duracao_minutos']; ?> minutos</p>
            <p><strong>Nota Máxima:</strong> <?php echo $prova['nota_maxima']; ?></p>
            <p><strong>Tentativas:</strong> <?php echo $prova['tentativas_realizadas']; ?> / <?php echo $prova['tentativas_permitidas']; ?></p>
            
            <?php if ($prova['melhor_nota']): ?>
            <p><strong>Melhor Nota:</strong> <?php echo $prova['melhor_nota']; ?></p>
            <?php endif; ?>
            
            <a href="realizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-primary">
                Iniciar Prova
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>