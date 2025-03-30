<?php
// inc/cabecalho.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Painel Administrativo - Império Pharma</title>
  <link rel="stylesheet" href="https://loja.imperiopharma.com.py/admin/inc/painel.css">
</head>
<body>

<header class="painel-header">
  <h1>Painel Administrativo - Império Pharma</h1>
</header>

<nav class="painel-nav">
  <!-- Links para as seções do painel -->
  <a href="index.php?page=dashboard">Dashboard</a>
  <a href="index.php?page=pedidos">Pedidos</a>
  <a href="index.php?page=rastreios">Rastreios</a>
  <a href="index.php?page=financeiro">Financeiro</a>
  <a href="index.php?page=financeiro_completo">Financeiro (Completo)</a>
  <a href="index.php?page=cupons">Cupons</a>
  <a href="index.php?page=marcas_produtos">Marcas &amp; Produtos</a>
  <a href="index.php?page=usuarios">Usuários</a>
</nav>

<div class="painel-container">
<?php
// A partir daqui, cada página incluída pelo index.php insere seu conteúdo
?>
