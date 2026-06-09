<?php
require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id<=0){echo json_encode(['success'=>false]); exit;}
$sql_func="SELECT f.id FROM funcionarios f INNER JOIN professores p ON p.usuario_id=f.usuario_id WHERE p.id=:pid";
$stmt_func=$conn->prepare($sql_func); $stmt_func->execute([':pid'=>$professor['professor_id']]);
$func=$stmt_func->fetch(PDO::FETCH_ASSOC); $func_id=$func['id']??0;
$sql="SELECT * FROM dividas_a_receber WHERE id=:id AND funcionario_id=:fid";
$stmt=$conn->prepare($sql); $stmt->execute([':id'=>$id,':fid'=>$func_id]);
$d=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$d){echo json_encode(['success'=>false]); exit;}
echo json_encode(['success'=>true,'descricao'=>$d['descricao'],'referencia'=>$d['referencia'],'tipo'=>$d['tipo'],'devedor_nome'=>$d['devedor_nome'],'valor_original'=>$d['valor_original'],'valor_recebido'=>$d['valor_recebido']??0,'valor_restante'=>($d['valor_original']-($d['valor_recebido']??0)),'data_vencimento'=>$d['data_vencimento'],'status'=>$d['status'],'created_at'=>$d['created_at']]);
?>