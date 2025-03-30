<?php
session_start();
// if (!isset($_SESSION['admin_logged'])) { header("Location: login.php"); exit; }

// Se o seu cabeçalho global injeta <head> e <body>, você pode requerer aqui:
require_once __DIR__ . '/../inc/cabecalho.php';

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../inc/config.php';

/***************************************************************************
 * VERIFICA SE EXISTEM AS COLUNAS shipped E shipped_at
 * Se não existirem, o sistema ignora a lógica de "já enviado".
 ***************************************************************************/
$hasShippedColumn = false;
try {
    $check = $pdo->query("SHOW COLUMNS FROM orders LIKE 'shipped'");
    if ($check && $check->rowCount() > 0) {
        $hasShippedColumn = true;
    }
} catch(Exception $e) {
    $hasShippedColumn = false;
}

/***************************************************************************
 * FUNÇÕES DE AUXÍLIO
 ***************************************************************************/
function normalize_str($str) {
    if (!$str) return '';
    $s = mb_strtoupper($str, 'UTF-8');
    $map = [
        '/[ÁÀÂÃÄ]/u' => 'A',
        '/[ÉÈÊË]/u'  => 'E',
        '/[ÍÌÎÏ]/u'  => 'I',
        '/[ÓÒÔÕÖ]/u' => 'O',
        '/[ÚÙÛÜ]/u'  => 'U',
        '/[Ç]/u'     => 'C'
    ];
    foreach ($map as $rgx => $rep) {
        $s = preg_replace($rgx, $rep, $s);
    }
    $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function similar_score($a, $b) {
    if (!$a || !$b) return 0.0;
    similar_text($a, $b, $pct);
    return $pct;
}

function cepClean($cep) {
    return substr(preg_replace('/\D/', '', $cep ?? ''), 0, 8);
}

/**
 * matchAllOrders($pdo, $nome, $cidade, $uf, $cep)
 * - Filtra pedidos pelo CEP (sem hífen)
 * - Compara (nome+UF) e (cidade+UF) com (customer_name+state) e (city+state)
 * - Exige sub-score >= 30 e média >= 60
 */
function matchAllOrders($pdo, $nome, $cidade, $uf, $cep) {
    $cp = cepClean($cep);
    if (!$cp) return [];

    $sql = "SELECT * FROM orders
            WHERE REPLACE(cep, '-', '') = :c
            ORDER BY id DESC
            LIMIT 200";
    $stm = $pdo->prepare($sql);
    $stm->execute([':c' => $cp]);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $nomeUf = normalize_str($nome . ' ' . $uf);
    $cityUf = normalize_str($cidade . ' ' . $uf);

    $matches = [];
    foreach ($rows as $r) {
        $dbName  = normalize_str(($r['customer_name'] ?? '') . ' ' . ($r['state'] ?? ''));
        $dbCity  = normalize_str(($r['city'] ?? '') . ' ' . ($r['state'] ?? ''));
        $scoreNome = similar_score($nomeUf, $dbName);
        $scoreCity = similar_score($cityUf, $dbCity);
        $avg       = ($scoreNome + $scoreCity) / 2.0;

        if ($scoreNome >= 30 && $scoreCity >= 30 && $avg >= 60) {
            $r['score_nome']   = $scoreNome;
            $r['score_cidade'] = $scoreCity;
            $r['score_total']  = $avg;
            $matches[] = $r;
        }
    }
    usort($matches, function($a, $b) {
        return $b['score_total'] <=> $a['score_total'];
    });
    return $matches;
}

/**
 * matchOrdersByCEP($pdo, $cep)
 * - Busca pedidos com CEP exato (sem hífen)
 */
function matchOrdersByCEP($pdo, $cep) {
    $cp = cepClean($cep);
    if (!$cp) return [];
    $sql = "SELECT * FROM orders
            WHERE REPLACE(cep, '-', '') = :c
            ORDER BY id DESC
            LIMIT 200";
    $stm = $pdo->prepare($sql);
    $stm->execute([':c' => $cp]);
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * parsePlanilhaLine($line)
 * - Exemplo: [0]=Objeto, [1]=CodigoInterno, [2]=Nome, [8]=Cidade, [9]=UF, [10]=CEP
 */
function parsePlanilhaLine($line) {
    if (stripos($line, 'Objeto') === 0) return null;
    $cols = preg_split('/\t|\s{2,}/', trim($line));
    if (count($cols) < 11) return null;
    return [
        'raw'    => $line,
        'objeto' => trim($cols[0]),
        'codigo' => trim($cols[1]),
        'nome'   => trim($cols[2]),
        'cidade' => trim($cols[8]),
        'uf'     => trim($cols[9]),
        'cep'    => trim($cols[10])
    ];
}

/**
 * parseBlocosTexto($txt):
 * - Formato:
 *   NOME: ...
 *   CIDADE: ... - UF
 *   CEP: ...
 *   OBJETO: ...
 *   CODIGO: ...
 *   ------
 */
function parseBlocosTexto($txt) {
    $ret = [];
    $pieces = explode('------', $txt);
    foreach ($pieces as $bk) {
        $bk = trim($bk);
        if ($bk === '') continue;

        $nome=''; $cid=''; $uf=''; $cp=''; $obj=''; $cod='';
        $lines = preg_split('/\r?\n/', $bk);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if (preg_match('/^NOME:\s*(.*)$/i', $ln, $m)) {
                $nome = $m[1];
            } elseif (preg_match('/^CIDADE:\s*(.*)$/i', $ln, $m)) {
                $tmp = $m[1];
                if (strpos($tmp, '-') !== false) {
                    list($cid, $uf) = array_map('trim', explode('-', $tmp));
                } else {
                    $cid = trim($tmp);
                }
            } elseif (preg_match('/^CEP:\s*(.*)$/i', $ln, $m)) {
                $cp = $m[1];
            } elseif (preg_match('/^OBJETO:\s*(.*)$/i', $ln, $m)) {
                $obj = $m[1];
            } elseif (preg_match('/^CODIGO:\s*(.*)$/i', $ln, $m)) {
                $cod = $m[1];
            }
        }
        $ret[] = [
            'raw'    => $bk,
            'objeto' => $obj,
            'codigo' => $cod,
            'nome'   => $nome,
            'cidade' => $cid,
            'uf'     => $uf,
            'cep'    => $cp
        ];
    }
    return $ret;
}

/***************************************************************************
 * ENVIO VIA WASCRIPT API (WhatsApp)
 ***************************************************************************/
function enviarMensagemWhatsApp($phone, $message) {
    $token = "1741243040070-789f20d337e5e8d6c95621ba5f5807f8";
    $url   = "https://api-whatsapp.wascript.com.br/api/enviar-texto/{$token}";

    $phone = preg_replace('/\D/', '', $phone);
    if (substr($phone, 0, 2) !== '55') {
        $phone = '55' . $phone;
    }
    $payload = [
        "phone"   => $phone,
        "message" => $message
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'response' => $response
    ];
}

/***************************************************************************
 * ROTEAMENTO
 ***************************************************************************/
$view = $_GET['view'] ?? 'list_unmatched';

/***************************************************************************
 * 1) ENVIAR_WHATSAPP (AJAX)
 ***************************************************************************/
if ($view === 'enviar_whatsapp') {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        exit;
    }

    // Se a coluna "shipped" não existir, ignoramos
    $sqlShipped = $hasShippedColumn ? ", shipped" : "";
    $stmt = $pdo->prepare("SELECT phone, customer_name, tracking_code $sqlShipped FROM orders WHERE id=? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Pedido não encontrado']);
        exit;
    }

    // Se shipped existir e estiver marcado, impede reenvio
    if ($hasShippedColumn && !empty($order['shipped'])) {
        echo json_encode(['success' => false, 'message' => 'Mensagem já enviada para este pedido']);
        exit;
    }

    $phone    = $order['phone'] ?? '';
    $nome     = $order['customer_name'] ?? '';
    $tracking = trim($order['tracking_code'] ?? '');
    if (empty($phone) || empty($tracking)) {
        echo json_encode(['success' => false, 'message' => 'Telefone ou tracking não informado']);
        exit;
    }

    $trackingBase = trim($_GET['base'] ?? '');
    $msg  = "📦 *Rastreamento do Seu Pedido* 📦\n\n";
    $msg .= "Olá {$nome},\n\n";
    $msg .= "Segue abaixo o seu código de rastreamento:\n";
    $msg .= "*{$tracking}*\n";
    if (!empty($trackingBase)) {
        $msg .= "Você pode acompanhar o status do seu pedido acessando:\n{$trackingBase}\n\n";
    } else {
        $msg .= "\n";
    }
    $msg .= "Agradecemos por sua preferência e confiança.\n";
    $msg .= "Atenciosamente,\nEquipe Império Pharma";

    $res = enviarMensagemWhatsApp($phone, $msg);

    if ($res['httpCode'] === 200) {
        // Marca como enviado se a coluna existir
        if ($hasShippedColumn) {
            $upd = $pdo->prepare("UPDATE orders SET shipped=1, shipped_at=NOW() WHERE id=? LIMIT 1");
            $upd->execute([$orderId]);
        }
        echo json_encode(['success' => true, 'message' => 'Mensagem enviada com sucesso!']);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Falha ao enviar (HTTP ' . $res['httpCode'] . ')',
            'response'=> $res['response']
        ]);
    }
    exit;
}

/***************************************************************************
 * 2) DESFAZER MATCH => remove tracking e reseta "shipped" (se existir)
 ***************************************************************************/
if ($view === 'undo') {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId > 0) {
        try {
            if ($hasShippedColumn) {
                $stmt = $pdo->prepare("UPDATE orders SET tracking_code='', shipped=0 WHERE id=? LIMIT 1");
            } else {
                $stmt = $pdo->prepare("UPDATE orders SET tracking_code='' WHERE id=? LIMIT 1");
            }
            $stmt->execute([$orderId]);
        } catch (Exception $e) {
            echo "<p>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    header("Location: ?page=rastreios&view=list_matched");
    exit;
}

/***************************************************************************
 * 3) EDIT => Editar tracking
 ***************************************************************************/
if ($view === 'edit') {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        echo "<p>ID inválido.</p>";
        exit;
    }
    try {
        $stm = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
        $stm->execute([$orderId]);
        $order = $stm->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo "<p>Pedido não encontrado!</p>";
            exit;
        }
    } catch (Exception $e) {
        echo "<p>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newTrack = trim($_POST['tracking_code'] ?? '');
        try {
            $up = $pdo->prepare("UPDATE orders SET tracking_code=? WHERE id=? LIMIT 1");
            $up->execute([$newTrack, $orderId]);
            header("Location: ?page=rastreios&view=list_matched");
            exit;
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
        }
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Editar Tracking #<?= (int)$orderId ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:600px;">
  <h2>Editar Tracking do Pedido #<?= (int)$orderId ?></h2>
  <?php if (isset($errMsg)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errMsg) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="mb-3">
      <label class="form-label">Tracking Atual:</label>
      <input type="text" class="form-control"
             value="<?= htmlspecialchars($order['tracking_code'] ?? '') ?>" disabled>
    </div>
    <div class="mb-3">
      <label class="form-label">Novo Tracking (vazio p/ remover):</label>
      <input type="text" name="tracking_code" class="form-control"
             value="<?= htmlspecialchars($order['tracking_code'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="?page=rastreios&view=list_matched" class="btn btn-secondary">Cancelar</a>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

/***************************************************************************
 * 4) UPDATE_MESSAGES => atualiza admin_comments p/ pedidos sem rastreio
 ***************************************************************************/
if ($view === 'update_messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $messages = $_POST['messages'] ?? [];
    $countUpdated = 0;
    foreach ($messages as $orderId => $msg) {
        $orderId = (int)$orderId;
        $msg = trim($msg);
        if ($orderId > 0 && $msg !== '') {
            try {
                $stmGet = $pdo->prepare("SELECT admin_comments FROM orders WHERE id=? LIMIT 1");
                $stmGet->execute([$orderId]);
                $old = '';
                if ($row = $stmGet->fetch(PDO::FETCH_ASSOC)) {
                    $old = $row['admin_comments'] ?? '';
                }
                $sep = $old ? "\n" : "";
                $new = $old . $sep . $msg;
                $stmUpd = $pdo->prepare("UPDATE orders SET admin_comments=? WHERE id=? LIMIT 1");
                $stmUpd->execute([$new, $orderId]);
                $countUpdated++;
            } catch (Exception $e) {
                // log se necessário
            }
        }
    }
    echo "<div class='alert alert-success'>Foram atualizadas mensagens em {$countUpdated} pedidos.</div>";
    echo "<p><a href='?page=rastreios&view=list_unmatched' class='btn btn-secondary'>Voltar</a></p>";
    exit;
}

/***************************************************************************
 * 5) LIST_UNMATCHED => Pedidos sem rastreio
 ***************************************************************************/
if ($view === 'list_unmatched') {
    $status = trim($_GET['status'] ?? '');
    $nome   = trim($_GET['nome'] ?? '');
    $where  = ["(tracking_code IS NULL OR tracking_code='')"];
    $params = [];

    if ($status !== '') {
        $where[] = "status = :st";
        $params[':st'] = $status;
    }
    if ($nome !== '') {
        $where[] = "customer_name LIKE :nm";
        $params[':nm'] = "%$nome%";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT id, customer_name, phone, email, address, number, complement,
                   city, state, cep, final_value, status, created_at
            FROM orders
            $whereSql
            ORDER BY id DESC
            LIMIT 200";
    try {
        $stm = $pdo->prepare($sql);
        $stm->execute($params);
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
        echo "<div class='alert alert-danger'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Pedidos Sem Rastreio</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .details-row { display: none; }
    .details-row td { background: #f9f9f9; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <h2 class="mb-3">Pedidos Sem Rastreio</h2>

  <div class="mb-3">
    <a href="?page=rastreios&view=list_matched" class="btn btn-success me-2">Ver Pedidos Com Rastreio</a>
    <a href="?page=rastreios&view=import" class="btn btn-success me-2">Importar Rastreios (Planilha/Bloco)</a>
    <a href="?page=rastreios&view=import_novopar" class="btn btn-success">Importar Rastreios (Novo Formato)</a>
  </div>

  <p class="text-muted">Utilize os filtros abaixo e insira, se desejar, uma mensagem para o cliente.</p>

  <!-- Filtros -->
  <form method="GET" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="page" value="rastreios">
    <input type="hidden" name="view" value="list_unmatched">
    <div class="col-auto">
      <label for="st" class="form-label">Status:</label>
      <select name="status" id="st" class="form-select">
        <option value="">--Todos--</option>
        <?php
          $possibleSt = ['PENDENTE','CONFIRMADO','EM PROCESSO','CONCLUIDO','CANCELADO'];
          foreach ($possibleSt as $ps) {
              $sel = ($status === $ps) ? 'selected' : '';
              echo "<option value='$ps' $sel>$ps</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-auto">
      <label for="nm" class="form-label">Nome Cliente:</label>
      <input type="text" name="nome" id="nm"
             class="form-control"
             value="<?= htmlspecialchars($nome) ?>"
             placeholder="Ex: José">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <!-- Form p/ atualizar mensagens (admin_comments) -->
  <form method="POST" action="?page=rastreios&view=update_messages">
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Cliente</th>
            <th>Cidade/UF</th>
            <th>Valor Final</th>
            <th>Status</th>
            <th>Data</th>
            <th>Ações</th>
            <th>Mensagem p/ Cliente</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8" class="text-muted text-center">Nenhum pedido encontrado</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $od):
              $pid = (int)$od['id'];
              $cuf = htmlspecialchars($od['city'].'/'.$od['state']);
              $fv  = number_format($od['final_value'] ?? 0, 2, ',', '.');
          ?>
            <tr>
              <td><?= $pid ?></td>
              <td><?= htmlspecialchars($od['customer_name'] ?? '') ?></td>
              <td><?= $cuf ?></td>
              <td>R$ <?= $fv ?></td>
              <td><?= htmlspecialchars($od['status'] ?? '') ?></td>
              <td><?= htmlspecialchars($od['created_at'] ?? '') ?></td>
              <td>
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleDetails(<?= $pid ?>)">Detalhes</button>
                <a href="?page=rastreios&view=edit&id=<?= $pid ?>" class="btn btn-sm btn-primary">Editar Tracking</a>
                <a href="?page=rastreios&view=undo&id=<?= $pid ?>" class="btn btn-sm btn-danger"
                   onclick="return confirm('Deseja remover o tracking deste pedido?');">Desfazer</a>
              </td>
              <td>
                <textarea name="messages[<?= $pid ?>]" rows="2" class="form-control"
                          placeholder="Insira msg p/ o cliente..."></textarea>
              </td>
            </tr>
            <tr id="details_<?= $pid ?>" class="details-row">
              <td colspan="8">
                <div class="row">
                  <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                      <li><strong>Telefone:</strong> <?= htmlspecialchars($od['phone'] ?? '') ?></li>
                      <li><strong>Email:</strong> <?= htmlspecialchars($od['email'] ?? '') ?></li>
                      <li><strong>Endereço:</strong> <?= htmlspecialchars($od['address'] ?? '') ?></li>
                      <li><strong>Número:</strong> <?= htmlspecialchars($od['number'] ?? '') ?></li>
                      <li><strong>Complemento:</strong> <?= htmlspecialchars($od['complement'] ?? '') ?></li>
                      <li><strong>CEP:</strong> <?= htmlspecialchars($od['cep'] ?? '') ?></li>
                    </ul>
                  </div>
                  <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                      <li><strong>Valor Final:</strong> R$ <?= $fv ?></li>
                      <li><strong>Status:</strong> <?= htmlspecialchars($od['status'] ?? '') ?></li>
                      <li><strong>Data:</strong> <?= htmlspecialchars($od['created_at'] ?? '') ?></li>
                    </ul>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="my-3">
      <button type="submit" class="btn btn-primary">Salvar Mensagens</button>
    </div>
  </form>
</div>
<script>
function toggleDetails(id) {
  var row = document.getElementById('details_' + id);
  if (row) {
    row.style.display = (row.style.display === 'none' ? 'table-row' : 'none');
  }
}
</script>
</body>
</html>
<?php
    exit;
}

/***************************************************************************
 * 6) LIST_MATCHED => Pedidos com rastreio (com paginação e destaque)
 ***************************************************************************/
if ($view === 'list_matched') {
    $status  = trim($_GET['status'] ?? '');
    $nome    = trim($_GET['nome'] ?? '');
    $pageNum = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    if ($pageNum < 1) $pageNum = 1;
    $limit  = 100;  // Ajuste se necessário
    $offset = ($pageNum - 1) * $limit;

    $where = ["(tracking_code IS NOT NULL AND tracking_code <> '')"];
    $params = [];
    if ($status !== '') {
        $where[] = "status = :st";
        $params[':st'] = $status;
    }
    if ($nome !== '') {
        $where[] = "customer_name LIKE :nm";
        $params[':nm'] = "%$nome%";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Conta total p/ paginação
    $countSql = "SELECT COUNT(*) FROM orders $whereSql";
    try {
        $stmCount = $pdo->prepare($countSql);
        $stmCount->execute($params);
        $totalRows = (int)$stmCount->fetchColumn();
    } catch (Exception $e) {
        $totalRows = 0;
        echo "<div class='alert alert-danger'>Erro ao contar pedidos: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    // Busca paginada
    $sql = "SELECT * FROM orders
            $whereSql
            ORDER BY updated_at DESC, id DESC
            LIMIT :limit OFFSET :offset";
    try {
        $stm = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stm->bindValue($k, $v);
        }
        $stm->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stm->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stm->execute();
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
        echo "<div class='alert alert-danger'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    $hoje = date('Y-m-d');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Pedidos Com Rastreio</title>
  <!-- Se quiser manter seu painel ou cabeçalho, inclua:
       <link rel="stylesheet" href="../inc/painel.css">
  -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .details-row { display: none; }
    .details-row td { background: #f9f9f9; }
    .today-match { background-color: #e9f9e9 !important; }
    .pagination { margin-top: 1rem; }
  </style>
</head>
<body class="bg-light">
<!-- Se quiser, inclua aqui o seu cabeçalho do painel -->
<!-- require_once __DIR__ . '/../inc/menu.php'; -->

<div class="container my-4">
  <h2 class="mb-3">Pedidos Com Rastreio</h2>

  <div class="mb-3">
    <a href="?page=rastreios&view=list_unmatched" class="btn btn-success me-2">Ver Pedidos Sem Rastreio</a>
    <a href="?page=rastreios&view=import" class="btn btn-success me-2">Importar Rastreios (Planilha/Bloco)</a>
    <a href="?page=rastreios&view=import_novopar" class="btn btn-success">Importar Rastreios (Novo Formato)</a>
  </div>

  <p class="text-muted">
    Filtre por status ou nome. Pedidos ordenados por data de atualização (mais recentes no topo).
    Os atualizados hoje são destacados.
  </p>

  <!-- Filtros -->
  <form method="GET" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="page" value="rastreios">
    <input type="hidden" name="view" value="list_matched">
    <div class="col-auto">
      <label for="st2" class="form-label">Status:</label>
      <select name="status" id="st2" class="form-select">
        <option value="">--Todos--</option>
        <?php
          $possibleSt = ['PENDENTE','CONFIRMADO','EM PROCESSO','CONCLUIDO','CANCELADO'];
          foreach ($possibleSt as $ps) {
              $sel = ($status === $ps) ? 'selected' : '';
              echo "<option value='$ps' $sel>$ps</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-auto">
      <label for="nm2" class="form-label">Nome Cliente:</label>
      <input type="text" name="nome" id="nm2"
             class="form-control"
             value="<?= htmlspecialchars($nome) ?>"
             placeholder="Ex: José">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <!-- Tabela de Pedidos -->
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Cliente</th>
          <th>Cidade/UF</th>
          <th>Valor Final</th>
          <th>Tracking</th>
          <th>Atualizado</th>
          <th>Ações</th>
          <th>Detalhes</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="8" class="text-muted text-center">Nenhum pedido encontrado</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $od):
              $pid        = (int)$od['id'];
              $cuf        = htmlspecialchars($od['city'] . '/' . $od['state']);
              $fv         = number_format($od['final_value'] ?? 0, 2, ',', '.');
              $trk        = htmlspecialchars($od['tracking_code'] ?? '');
              $atualizado = htmlspecialchars($od['updated_at'] ?? $od['created_at'] ?? '');
              $isToday    = (date('Y-m-d', strtotime($atualizado)) === $hoje);
              $rowClass   = $isToday ? 'today-match' : '';
        ?>
          <tr class="<?= $rowClass ?>">
            <td><?= $pid ?></td>
            <td><?= htmlspecialchars($od['customer_name'] ?? '') ?></td>
            <td><?= $cuf ?></td>
            <td>R$ <?= $fv ?></td>
            <td><?= $trk ?></td>
            <td><?= $atualizado ?></td>
            <td>
              <a href="?page=rastreios&view=edit&id=<?= $pid ?>" class="btn btn-sm btn-primary">Editar</a>
              <a href="?page=rastreios&view=undo&id=<?= $pid ?>" class="btn btn-sm btn-danger"
                 onclick="return confirm('Deseja remover o tracking deste pedido?');">Desfazer</a>
              <br>

              <?php if ($hasShippedColumn && !empty($od['shipped'])): ?>
                <!-- Se shipped=1, mostra badge -->
                <span class="badge bg-success mt-1 d-inline-block">WhatsApp Enviado</span>
              <?php else: ?>
                <!-- Caso contrário, mostra o combo e o botão de envio -->
                <select id="tracking_base_<?= $pid ?>" class="form-select form-select-sm d-inline-block mt-1" style="width:220px;">
                  <option value="" selected>-- Selecione --</option>
                  <option value="https://melhorrastreio.com.br/">Melhor Rastreio</option>
                  <option value="https://conecta.log.br/rastreio.php">Onlog</option>
                  <option value="https://www.loggi.com/rastreador/">Loggi</option>
                </select>
                <button type="button" class="btn btn-sm btn-success mt-1" onclick="confirmarEnvio(<?= $pid ?>)">Enviar WhatsApp</button>
              <?php endif; ?>
            </td>
            <td>
              <button type="button" class="btn btn-sm btn-secondary" onclick="toggleDetails(<?= $pid ?>)">Ver Detalhes</button>
            </td>
          </tr>
          <tr id="details_<?= $pid ?>" class="details-row">
            <td colspan="8">
              <div class="row">
                <div class="col-md-6">
                  <ul class="list-unstyled">
                    <li><strong>ID:</strong> <?= $pid ?></li>
                    <li><strong>Cliente:</strong> <?= htmlspecialchars($od['customer_name'] ?? '') ?></li>
                    <?php if (!empty($od['cpf'])): ?>
                      <li><strong>CPF:</strong> <?= htmlspecialchars($od['cpf']) ?></li>
                    <?php endif; ?>
                    <li><strong>Telefone:</strong> <?= htmlspecialchars($od['phone'] ?? '') ?></li>
                    <li><strong>Email:</strong> <?= htmlspecialchars($od['email'] ?? '') ?></li>
                    <li><strong>Endereço:</strong> <?= htmlspecialchars($od['address'] ?? '') ?></li>
                    <li><strong>Número:</strong> <?= htmlspecialchars($od['number'] ?? '') ?></li>
                    <?php if (!empty($od['complement'])): ?>
                      <li><strong>Complemento:</strong> <?= htmlspecialchars($od['complement']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($od['neighborhood'])): ?>
                      <li><strong>Bairro:</strong> <?= htmlspecialchars($od['neighborhood']) ?></li>
                    <?php endif; ?>
                    <li><strong>CEP:</strong> <?= htmlspecialchars($od['cep'] ?? '') ?></li>
                  </ul>
                </div>
                <div class="col-md-6">
                  <ul class="list-unstyled">
                    <li><strong>Cidade/UF:</strong> <?= $cuf ?></li>
                    <?php if (!empty($od['shipping_type'])): ?>
                      <li><strong>Tipo Frete:</strong> <?= htmlspecialchars($od['shipping_type']) ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['shipping_value'])): ?>
                      <li><strong>Valor Frete:</strong> R$ <?= number_format($od['shipping_value'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (!empty($od['payment_method'])): ?>
                      <li><strong>Pagamento:</strong> <?= htmlspecialchars($od['payment_method']) ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['discount_value'])): ?>
                      <li><strong>Desconto:</strong> R$ <?= number_format($od['discount_value'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['card_fee_value'])): ?>
                      <li><strong>Taxa Cartão:</strong> R$ <?= number_format($od['card_fee_value'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['insurance_value'])): ?>
                      <li><strong>Seguro:</strong> R$ <?= number_format($od['insurance_value'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['total'])): ?>
                      <li><strong>Total:</strong> R$ <?= number_format($od['total'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['cost_total'])): ?>
                      <li><strong>Custo Total:</strong> R$ <?= number_format($od['cost_total'], 2, ',', '.') ?></li>
                    <?php endif; ?>
                    <?php if (isset($od['points_earned'])): ?>
                      <li><strong>Pontos:</strong> <?= htmlspecialchars($od['points_earned']) ?></li>
                    <?php endif; ?>
                    <li><strong>Data Criação:</strong> <?= htmlspecialchars($od['created_at'] ?? '') ?></li>
                    <?php if (!empty($od['updated_at'])): ?>
                      <li><strong>Data Atualização:</strong> <?= htmlspecialchars($od['updated_at']) ?></li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginação -->
  <?php if ($totalRows > $limit):
      $totalPages = ceil($totalRows / $limit);
  ?>
  <nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
      <?php if ($pageNum > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?page=rastreios&view=list_matched&p=<?= $pageNum - 1 ?>&status=<?= urlencode($status) ?>&nome=<?= urlencode($nome) ?>" aria-label="Anterior">
            <span aria-hidden="true">&laquo;</span>
          </a>
        </li>
      <?php endif; ?>
      <?php
        $start = max(1, $pageNum - 2);
        $end   = min($totalPages, $pageNum + 2);
        for ($i = $start; $i <= $end; $i++):
      ?>
        <li class="page-item <?= ($i == $pageNum) ? 'active' : '' ?>">
          <a class="page-link" href="?page=rastreios&view=list_matched&p=<?= $i ?>&status=<?= urlencode($status) ?>&nome=<?= urlencode($nome) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
      <?php if ($pageNum < $totalPages): ?>
        <li class="page-item">
          <a class="page-link" href="?page=rastreios&view=list_matched&p=<?= $pageNum + 1 ?>&status=<?= urlencode($status) ?>&nome=<?= urlencode($nome) ?>" aria-label="Próximo">
            <span aria-hidden="true">&raquo;</span>
          </a>
        </li>
      <?php endif; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<script>
function toggleDetails(id) {
  var row = document.getElementById('details_' + id);
  if (row) {
    row.style.display = (row.style.display === 'none' ? 'table-row' : 'none');
  }
}

function confirmarEnvio(orderId) {
  var selectElem = document.getElementById('tracking_base_' + orderId);
  var baseLink = selectElem ? selectElem.value : "https://melhorrastreio.com.br/";
  if (confirm("Deseja enviar o código de rastreio via WhatsApp usando o link:\n" + baseLink + "\n\nContinuar?")) {
      fetch('?page=rastreios&view=enviar_whatsapp&id=' + orderId + '&base=' + encodeURIComponent(baseLink))
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  alert("Mensagem enviada com sucesso!");
              } else {
                  alert("Erro ao enviar: " + data.message + "\\n\\nResposta: " + (data.response || "sem detalhes"));
              }
          })
          .catch(error => {
              alert("Erro na requisição: " + error);
          });
  }
}
</script>
</body>
</html>
<?php
    exit;
}

/***************************************************************************
 * 7) IMPORT => Tela p/ importar rastreios (Planilha/Bloco)
 ***************************************************************************/
if ($view === 'import') {
    // Se POST, processa
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $txt = trim($_POST['textoBruto'] ?? '');
        if ($txt === '') {
            echo "<div class='alert alert-danger'>Texto vazio!</div>";
            echo "<p><a href='?page=rastreios&view=import' class='btn btn-secondary'>Voltar</a></p>";
            exit;
        }
        // Identifica se é bloco ou planilha
        $formato = (stripos($txt, 'NOME:') !== false && stripos($txt, '------') !== false) ? 'bloco' : 'planilha';
        $arrOK = [];
        $arrFail = [];

        if ($formato === 'bloco') {
            $blocos = parseBlocosTexto($txt);
            foreach ($blocos as $bk) {
                if (!$bk['cep'] || !$bk['codigo']) {
                    $arrFail[] = $bk;
                    continue;
                }
                $matches = matchAllOrders($pdo, $bk['nome'], $bk['cidade'], $bk['uf'], $bk['cep']);
                if (!$matches) {
                    $arrFail[] = $bk;
                } else {
                    $bk['matches'] = $matches;
                    $arrOK[] = $bk;
                }
            }
        } else {
            // Planilha
            $lines = preg_split('/\r?\n/', $txt);
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '' || stripos($ln, 'Objeto') === 0) continue;
                $p = parsePlanilhaLine($ln);
                if (!$p || !$p['cep'] || !$p['codigo']) {
                    $arrFail[] = ['raw' => $ln];
                    continue;
                }
                $m = matchAllOrders($pdo, $p['nome'], $p['cidade'], $p['uf'], $p['cep']);
                if (!$m) {
                    $arrFail[] = $p;
                } else {
                    $p['matches'] = $m;
                    $arrOK[] = $p;
                }
            }
        }

        // Exibe resultado
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Importar Rastreios – Matches</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .card-match { margin-bottom: 1rem; }
    .details-match { display: none; margin-top: 8px; background: #f8f9fa; padding: 6px; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:900px;">
  <h2 class="mb-4">Resultado do Import – Possíveis Matches</h2>
  <form method="POST" action="?page=rastreios&view=save">
    <?php if (!empty($arrOK)): ?>
      <div class="alert alert-info mb-3">
        <strong><?= count($arrOK) ?> blocos/linhas com match</strong>
      </div>
      <?php foreach ($arrOK as $iB => $bk):
          $raw = $bk['raw']    ?? '';
          $cp  = $bk['cep']    ?? '';
          $cd  = $bk['codigo'] ?? '';
          $arrM= $bk['matches']?? [];
      ?>
      <div class="card card-match">
        <div class="card-body">
          <h6 class="text-secondary mb-1">Texto de Origem</h6>
          <p class="small">
            <strong>CEP:</strong> <?= htmlspecialchars($cp) ?> /
            <strong>CÓDIGO:</strong> <?= htmlspecialchars($cd) ?>
          </p>
          <pre class="small" style="white-space:pre-wrap;background:#f8f9fa;padding:8px;">
<?= htmlspecialchars($raw) ?>
          </pre>
          <hr>
          <h6 class="text-secondary mb-1">Possíveis Pedidos</h6>
          <div class="row">
          <?php foreach ($arrM as $iM => $md):
              $pid = (int)$md['id'];
              $score = round($md['score_total'] ?? 0, 1);
          ?>
            <div class="col-md-4 mb-2">
              <div class="border p-2">
                <div class="form-check">
                  <input class="form-check-input"
                         type="radio"
                         name="finalMatches[<?= $iB ?>][radio]"
                         id="r_<?= $iB ?>_<?= $iM ?>"
                         value="<?= $pid ?>"
                         <?= $iM === 0 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="r_<?= $iB ?>_<?= $iM ?>">
                    Pedido #<?= $pid ?> (<?= $score ?>%)
                  </label>
                </div>
                <button type="button" class="btn btn-sm btn-secondary mt-1"
                        onclick="toggleMatchDetail('matchD_<?= $iB ?>_<?= $iM ?>')">
                  Ver Detalhes
                </button>
                <div id="matchD_<?= $iB ?>_<?= $iM ?>" class="details-match">
                  <ul class="list-unstyled mb-0 small">
                    <li><strong>ID:</strong> <?= htmlspecialchars($md['id'] ?? '') ?></li>
                    <li><strong>Cliente:</strong> <?= htmlspecialchars($md['customer_name'] ?? '') ?></li>
                    <?php if (!empty($md['cpf'])): ?>
                      <li><strong>CPF:</strong> <?= htmlspecialchars($md['cpf']) ?></li>
                    <?php endif; ?>
                    <li><strong>Telefone:</strong> <?= htmlspecialchars($md['phone'] ?? '') ?></li>
                    <li><strong>Email:</strong> <?= htmlspecialchars($md['email'] ?? '') ?></li>
                    <li><strong>Endereço:</strong> <?= htmlspecialchars($md['address'] ?? '') ?></li>
                    <li><strong>Número:</strong> <?= htmlspecialchars($md['number'] ?? '') ?></li>
                    <?php if (!empty($md['complement'])): ?>
                      <li><strong>Complemento:</strong> <?= htmlspecialchars($md['complement']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($md['neighborhood'])): ?>
                      <li><strong>Bairro:</strong> <?= htmlspecialchars($md['neighborhood']) ?></li>
                    <?php endif; ?>
                    <li><strong>Cidade/UF:</strong> <?= htmlspecialchars(($md['city'] ?? '') . '/' . ($md['state'] ?? '')) ?></li>
                    <li><strong>CEP:</strong> <?= htmlspecialchars($md['cep'] ?? '') ?></li>
                    <li><strong>Valor Final:</strong> R$ <?= isset($md['final_value']) ? number_format($md['final_value'],2,',','.') : '0,00' ?></li>
                    <li><strong>Data:</strong> <?= htmlspecialchars($md['created_at'] ?? '') ?></li>
                    <li><strong>Status:</strong> <?= htmlspecialchars($md['status'] ?? '') ?></li>
                    <?php if (!empty($md['tracking_code'])): ?>
                      <li style="color:green;"><strong>Tracking:</strong> <?= htmlspecialchars($md['tracking_code']) ?> (Já combinado)</li>
                    <?php else: ?>
                      <li style="color:red;"><strong>Tracking:</strong> Não informado</li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
          <div class="mt-2">
            <label class="form-label"><strong>Mensagem para Append (admin_comments):</strong></label>
            <textarea class="form-control" rows="2"
                      name="finalMatches[<?= $iB ?>][msg]"
                      placeholder="Opcional: mensagem para o cliente"></textarea>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox"
                   name="finalMatches[<?= $iB ?>][check]" value="1" checked>
            <label class="form-check-label">Confirmar</label>
          </div>
          <input type="hidden" name="finalMatches[<?= $iB ?>][track]" value="<?= htmlspecialchars($cd) ?>">
        </div>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary">Salvar Rastreios</button>
    <?php else: ?>
      <div class="alert alert-warning">Nenhum match encontrado!</div>
    <?php endif; ?>

    <?php if (!empty($arrFail)): ?>
      <div class="alert alert-secondary mt-4">
        <strong><?= count($arrFail) ?> blocos/linhas sem match</strong>
      </div>
      <ul class="list-group">
        <?php foreach ($arrFail as $ff):
            $r = $ff['raw'] ?? (is_array($ff) ? implode(' ', $ff) : $ff);
        ?>
          <li class="list-group-item" style="white-space: pre-wrap;">
            <?= htmlspecialchars($r ?? '') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </form>
  <p class="mt-3">
    <a href="?page=rastreios&view=import" class="btn btn-secondary">Voltar</a>
  </p>
</div>
<script>
function toggleMatchDetail(id) {
  var el = document.getElementById(id);
  if (el) {
    el.style.display = (el.style.display === 'none' ? 'block' : 'none');
  }
}
</script>
</body>
</html>
<?php
        exit;
    }
    // GET => Form de import
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Importar Rastreios (Planilha/Bloco)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:900px;">
  <h2 class="mb-3">Importar Rastreios (Planilha/Bloco)</h2>
  <p class="text-muted">
    Cole o texto (blocos ou planilha). O sistema identificará os pedidos possíveis para cada bloco/linha.
  </p>
  <form method="POST">
    <div class="mb-3">
      <label for="textoBruto" class="form-label">Texto de Rastreios:</label>
      <textarea name="textoBruto" id="textoBruto" rows="10" class="form-control"
                placeholder="Cole aqui..."></textarea>
    </div>
    <button type="submit" class="btn btn-success">Processar</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

/***************************************************************************
 * 7.1) IMPORT_NOVOPAR => Tela p/ importar rastreios (Novo Formato)
 ***************************************************************************/
if ($view === 'import_novopar') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $txt = trim($_POST['textoBruto'] ?? '');
        if ($txt === '') {
            echo "<div class='alert alert-danger'>Texto vazio!</div>";
            echo "<p><a href='?page=rastreios&view=import_novopar' class='btn btn-secondary'>Voltar</a></p>";
            exit;
        }
        $lines = preg_split('/\r?\n/', $txt);
        $nonEmpty = array_values(array_filter($lines, function($line) {
            return trim($line) !== '';
        }));
        if (count($nonEmpty) % 2 !== 0) {
            echo "<div class='alert alert-danger'>Número de linhas inválido. Cada registro deve ter 2 linhas (CEP e Código).</div>";
            echo "<p><a href='?page=rastreios&view=import_novopar' class='btn btn-secondary'>Voltar</a></p>";
            exit;
        }

        $arrOK = [];
        $arrFail = [];
        for ($i = 0; $i < count($nonEmpty); $i += 2) {
            $cep    = trim($nonEmpty[$i]);
            $codigo = trim($nonEmpty[$i+1]);
            $cepLimpo = cepClean($cep);
            if (strlen($cepLimpo) !== 8 || strlen($codigo) < 8) {
                $arrFail[] = ['raw' => "$cep\n$codigo"];
                continue;
            }
            $matches = matchOrdersByCEP($pdo, $cepLimpo);
            if (!$matches) {
                $arrFail[] = ['raw' => "$cep\n$codigo"];
            } else {
                $arrOK[] = [
                    'raw'    => "$cep\n$codigo",
                    'cep'    => $cepLimpo,
                    'codigo' => $codigo,
                    'matches'=> $matches
                ];
            }
        }
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Importar Rastreios (Novo Formato) – Matches</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .card-match { margin-bottom: 1rem; }
    .details-match { display: none; margin-top: 8px; background: #f8f9fa; padding: 6px; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:900px;">
  <h2 class="mb-4">Resultado do Import (Novo Formato) – Possíveis Matches</h2>
  <form method="POST" action="?page=rastreios&view=save">
    <?php if (!empty($arrOK)): ?>
      <div class="alert alert-info mb-3">
        <strong><?= count($arrOK) ?> registros com match</strong>
      </div>
      <?php foreach ($arrOK as $iB => $bk):
          $raw = $bk['raw'] ?? '';
          $cep = $bk['cep'] ?? '';
          $cd  = $bk['codigo'] ?? '';
          $arrM= $bk['matches'] ?? [];
      ?>
      <div class="card card-match">
        <div class="card-body">
          <h6 class="text-secondary mb-1">Texto de Origem</h6>
          <p class="small">
            <strong>CEP:</strong> <?= htmlspecialchars($cep) ?> /
            <strong>CÓDIGO:</strong> <?= htmlspecialchars($cd) ?>
          </p>
          <pre class="small" style="white-space:pre-wrap;background:#f8f9fa;padding:8px;">
<?= htmlspecialchars($raw) ?>
          </pre>
          <hr>
          <h6 class="text-secondary mb-1">Possíveis Pedidos</h6>
          <div class="row">
          <?php foreach ($arrM as $iM => $md):
              $pid = (int)$md['id'];
          ?>
            <div class="col-md-4 mb-2">
              <div class="border p-2">
                <div class="form-check">
                  <input class="form-check-input"
                         type="radio"
                         name="finalMatches[<?= $iB ?>][radio]"
                         id="r_<?= $iB ?>_<?= $iM ?>"
                         value="<?= $pid ?>"
                         <?= $iM === 0 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="r_<?= $iB ?>_<?= $iM ?>">
                    Pedido #<?= $pid ?>
                  </label>
                </div>
                <button type="button" class="btn btn-sm btn-secondary mt-1"
                        onclick="toggleMatchDetail('matchD_<?= $iB ?>_<?= $iM ?>')">
                  Ver Detalhes
                </button>
                <div id="matchD_<?= $iB ?>_<?= $iM ?>" class="details-match">
                  <ul class="list-unstyled mb-0 small">
                    <li><strong>ID:</strong> <?= htmlspecialchars($md['id'] ?? '') ?></li>
                    <li><strong>Cliente:</strong> <?= htmlspecialchars($md['customer_name'] ?? '') ?></li>
                    <?php if (!empty($md['cpf'])): ?>
                      <li><strong>CPF:</strong> <?= htmlspecialchars($md['cpf']) ?></li>
                    <?php endif; ?>
                    <li><strong>Telefone:</strong> <?= htmlspecialchars($md['phone'] ?? '') ?></li>
                    <li><strong>Email:</strong> <?= htmlspecialchars($md['email'] ?? '') ?></li>
                    <li><strong>Endereço:</strong> <?= htmlspecialchars($md['address'] ?? '') ?></li>
                    <li><strong>Número:</strong> <?= htmlspecialchars($md['number'] ?? '') ?></li>
                    <?php if (!empty($md['complement'])): ?>
                      <li><strong>Complemento:</strong> <?= htmlspecialchars($md['complement']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($md['neighborhood'])): ?>
                      <li><strong>Bairro:</strong> <?= htmlspecialchars($md['neighborhood']) ?></li>
                    <?php endif; ?>
                    <li><strong>Cidade/UF:</strong> <?= htmlspecialchars(($md['city'] ?? '') . '/' . ($md['state'] ?? '')) ?></li>
                    <li><strong>CEP:</strong> <?= htmlspecialchars($md['cep'] ?? '') ?></li>
                    <li><strong>Valor Final:</strong> R$ <?= isset($md['final_value']) ? number_format($md['final_value'],2,',','.') : '0,00' ?></li>
                    <li><strong>Data:</strong> <?= htmlspecialchars($md['created_at'] ?? '') ?></li>
                    <li><strong>Status:</strong> <?= htmlspecialchars($md['status'] ?? '') ?></li>
                    <?php if (!empty($md['tracking_code'])): ?>
                      <li style="color:green;"><strong>Tracking:</strong> <?= htmlspecialchars($md['tracking_code']) ?> (Já combinado)</li>
                    <?php else: ?>
                      <li style="color:red;"><strong>Tracking:</strong> Não informado</li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
          <div class="mt-2">
            <label class="form-label"><strong>Mensagem para Append (admin_comments):</strong></label>
            <textarea class="form-control" rows="2"
                      name="finalMatches[<?= $iB ?>][msg]"
                      placeholder="Opcional: mensagem para o cliente"></textarea>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox"
                   name="finalMatches[<?= $iB ?>][check]" value="1" checked>
            <label class="form-check-label">Confirmar</label>
          </div>
          <input type="hidden" name="finalMatches[<?= $iB ?>][track]" value="<?= htmlspecialchars($cd) ?>">
        </div>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary">Salvar Rastreios</button>
    <?php else: ?>
      <div class="alert alert-warning">Nenhum match encontrado!</div>
    <?php endif; ?>

    <?php if (!empty($arrFail)): ?>
      <div class="alert alert-secondary mt-4">
        <strong><?= count($arrFail) ?> registros sem match</strong>
      </div>
      <ul class="list-group">
        <?php foreach ($arrFail as $ff):
            $r = $ff['raw'] ?? (is_array($ff) ? implode(' ', $ff) : $ff);
        ?>
          <li class="list-group-item" style="white-space:pre-wrap;">
            <?= htmlspecialchars($r ?? '') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </form>
  <p class="mt-3">
    <a href="?page=rastreios&view=import_novopar" class="btn btn-secondary">Voltar</a>
  </p>
</div>
<script>
function toggleMatchDetail(id) {
  var el = document.getElementById(id);
  if (el) {
    el.style.display = (el.style.display === 'none' ? 'block' : 'none');
  }
}
</script>
</body>
</html>
<?php
        exit;
    }
    // GET => Form de import
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Importar Rastreios – Novo Formato</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:900px;">
  <h2 class="mb-3">Importar Rastreios (Novo Formato)</h2>
  <p class="text-muted">
    Cole o texto no formato abaixo, onde cada registro é formado por duas linhas:<br>
    - Linha 1: CEP do cliente (ex.: 06437-240 ou 06437240)<br>
    - Linha 2: Código de rastreio (ex.: AA880346994BR)
  </p>
  <form method="POST">
    <div class="mb-3">
      <label for="textoBruto" class="form-label">Texto de Rastreios:</label>
      <textarea name="textoBruto" id="textoBruto" rows="10" class="form-control"
                placeholder="Cole aqui o texto do novo formato..."></textarea>
    </div>
    <button type="submit" class="btn btn-success">Processar</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

/***************************************************************************
 * 8) SAVE => salva matches do import (tracking e admin_comments)
 ***************************************************************************/
if ($view === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sqlTk = "UPDATE orders SET tracking_code=? WHERE id=? LIMIT 1";
    $stmTk = $pdo->prepare($sqlTk);

    $sqlGetAdm = "SELECT admin_comments FROM orders WHERE id=? LIMIT 1";
    $stmGetAdm = $pdo->prepare($sqlGetAdm);

    $sqlUpdAdm = "UPDATE orders SET admin_comments=? WHERE id=? LIMIT 1";
    $stmUpdAdm = $pdo->prepare($sqlUpdAdm);

    $countOk = 0;

    if (isset($_POST['finalMatches'])) {
        foreach ($_POST['finalMatches'] as $i => $fm) {
            $check = isset($fm['check']);
            $pid   = (int)($fm['radio'] ?? 0);
            $trk   = trim($fm['track'] ?? '');
            if ($check && $pid > 0 && $trk !== '') {
                try {
                    $stmTk->execute([$trk, $pid]);
                    $countOk++;
                } catch (Exception $e) {
                    // log se necessário
                }
            }
            $msg = trim($fm['msg'] ?? '');
            if ($pid > 0 && $msg !== '') {
                try {
                    $stmGetAdm->execute([$pid]);
                    $oldAdm = '';
                    if ($rAdm = $stmGetAdm->fetch(PDO::FETCH_ASSOC)) {
                        $oldAdm = $rAdm['admin_comments'] ?? '';
                    }
                    $sep = $oldAdm ? "\n" : "";
                    $newAdm = $oldAdm . $sep . $msg;
                    $stmUpdAdm->execute([$newAdm, $pid]);
                } catch(Exception $e) {
                    // log se necessário
                }
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Rastreios – Salvo</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="alert alert-success">
    <h5>Rastreios Atualizados</h5>
    <p>Foram atualizados tracking para <?= $countOk ?> pedidos.</p>
  </div>
  <a href="?page=rastreios&view=list_unmatched" class="btn btn-secondary">Ver Pedidos Sem Rastreio</a>
  <a href="?page=rastreios&view=list_matched" class="btn btn-primary">Ver Pedidos Com Rastreio</a>
</div>
</body>
</html>
<?php
    exit;
}

// Se chegou aqui, redireciona para list_unmatched
header("Location: ?page=rastreios&view=list_unmatched");
exit;
