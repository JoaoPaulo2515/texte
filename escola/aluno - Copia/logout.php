<?php
// aluno/logout.php - Logout da Área do Aluno

session_start();
session_destroy();
header('Location: login.php');
exit;