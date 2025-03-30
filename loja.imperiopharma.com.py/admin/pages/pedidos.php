<?php
global $pdo; // Garante que $pdo esteja disponível (definido em config.php)

/**************************************************************
 * pages/pedidos.php
 *
 * - action=list      => Lista pedidos (+ filtros)
 * - action=detail    => Detalhes de 1 pedido (inclui campo tracking_code)
 * - action=rastreios => Aba avançada p/ importação multiline (NOME, CIDADE, CEP...)
 * - action=sendWpp   => Envia manualmente a mensagem do pedido via WhatsApp (NOVO)
 **************************************************************/

// ------------------------------------------------------------
// FUNÇÕES AUXILIARES
// ------------------------------------------------------------
/** Remove acentos e converte a string para maiúsculas sem caracteres especiais */
function normalize_str($str) {
    if (!$str) return '';
    // Maiúsculas
    $str = mb_strtoupper($str, 'UTF-8');
    // Remover acentos
    $mapAcentos = [
        '/[ÁÀÂÃÄ]/u' => 'A',
        '/[ÉÈÊË]/u'  => 'E',
        '/[ÍÌÎÏ]/u'  => 'I',
        '/[ÓÒÔÕÖ]/u' => 'O',
        '/[ÚÙÛÜ]/u'  => 'U',
        '/[Ç]/u'     => 'C'
    ];
    foreach($mapAcentos as $rgx => $rep) {
        $str = preg_replace($rgx, $rep, $str);
    }
    // Manter letras, números e espaço
    $str = preg_replace('/[^A-Z0-9 ]+/', ' ', $str);
    // Trim e normalizar espaços
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

/** Retorna similaridade percentual (0..100) entre 2 strings normalizadas */
function similar_score($a, $b) {
    if (!$a || !$b) return 0.0;
    similar_text($a, $b, $pct);
    return $pct;
}

/**
 * Envia mensagem via Wascript (POST /api/enviar-texto/{token})
 * // >>> NOVO <<< Adicionado aqui para permitir envio no action=sendWpp
 */
function enviarMensagemWascript($phone, $message) {
    // Seu token (mesmo que em salvarPedido.php)
    $token = '1741243040070-789f20d337e5e8d6c95621ba5f5807f8';

    $url = "https://api-whatsapp.wascript.com.br/api/enviar-texto/{$token}";
    $payload = [
        'phone'   => $phone,
        'message' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    error_log("Wascript => HTTP=$httpCode, err=$err, resp=$resp");

    if($httpCode===200 && !$err) {
        $jsonResp = @json_decode($resp, true);
        if(isset($jsonResp['success']) && $jsonResp['success']===true) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------
// LÊ O action= DA QUERY
// ------------------------------------------------------------
$action = isset($_GET['action']) ? trim($_GET['action']) : 'list';

// ------------------------------------------------------------
// (1) LISTA DE PEDIDOS (action=list)
// ------------------------------------------------------------
if ($action === 'list') {
    $statusFiltro = isset($_GET['status']) ? trim($_GET['status']) : '';
    $nomeFiltro   = isset($_GET['nome'])   ? trim($_GET['nome'])   : '';

    $where = [];
    $params= [];

    if ($statusFiltro !== '') {
        $where[] = "status = :st";
        $params[':st'] = $statusFiltro;
    }
    if ($nomeFiltro !== '') {
        $where[] = "customer_name LIKE :nome";
        $params[':nome'] = "%$nomeFiltro%";
    }
    $whereSQL = '';
    if ($where) {
        $whereSQL = 'WHERE ' . implode(' AND ', $where);
    }

    $sql = "
      SELECT id, customer_name, final_value, cost_total,
             status, created_at
      FROM orders
      $whereSQL
      ORDER BY id DESC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        echo "<div class='alert alert-danger'>Erro ao buscar pedidos: ".
             htmlspecialchars($e->getMessage())."</div>";
        $orders=[];
    }
    ?>
    <h2>Lista de Pedidos</h2>

    <!-- Form de Filtros -->
    <form method="GET" class="mb-3">
      <input type="hidden" name="page" value="pedidos">
      <input type="hidden" name="action" value="list">

      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label for="status" class="form-label">Status:</label>
          <select name="status" id="status" class="form-select">
            <option value="">-- Todos --</option>
            <?php
              $possSt = ['PENDENTE','CONFIRMADO','EM PROCESSO','CANCELADO','CONCLUIDO'];
              foreach($possSt as $stx) {
                $sel=($statusFiltro===$stx)?'selected':'';
                echo "<option value='$stx' $sel>$stx</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label for="nome" class="form-label">Nome Cliente:</label>
          <input type="text"
                 name="nome"
                 id="nome"
                 value="<?= htmlspecialchars($nomeFiltro) ?>"
                 class="form-control"
                 placeholder="Ex: José"
          >
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
      </div>
    </form>

    <p>
      <a href="index.php?page=pedidos&action=rastreios"
         class="btn btn-success"
      >Importar Rastreios (Avançado)</a>
    </p>

    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Cliente</th>
            <th>Valor Final</th>
            <th>Custo</th>
            <th>Status</th>
            <th>Data/Hora</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$orders): ?>
          <tr>
            <td colspan="7" class="text-muted text-center">
              Nenhum pedido encontrado
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($orders as $od): ?>
            <?php
              // cor do badge
              $badge='badge bg-secondary';
              switch($od['status']){
                case 'PENDENTE':    $badge='badge bg-warning text-dark'; break;
                case 'CONFIRMADO': $badge='badge bg-primary'; break;
                case 'EM PROCESSO':$badge='badge bg-info text-dark'; break;
                case 'CANCELADO':  $badge='badge bg-danger'; break;
                case 'CONCLUIDO':  $badge='badge bg-success'; break;
              }
            ?>
            <tr>
              <td><?= $od['id'] ?></td>
              <td><?= htmlspecialchars($od['customer_name']??'') ?></td>
              <td>R$ <?= number_format($od['final_value'],2,',','.') ?></td>
              <td>R$ <?= number_format($od['cost_total'],2,',','.') ?></td>
              <td><span class="<?= $badge ?>"><?= htmlspecialchars($od['status']) ?></span></td>
              <td><?= date('d/m/Y H:i', strtotime($od['created_at'])) ?></td>
              <td>
                <a href="index.php?page=pedidos&action=detail&id=<?= $od['id'] ?>"
                   class="btn btn-sm btn-primary"
                >Detalhes</a>
              </td>
            </tr>
          <?php endforeach;?>
        <?php endif;?>
        </tbody>
      </table>
    </div>
    <?php

// ------------------------------------------------------------
// (2) DETALHE DE UM PEDIDO (action=detail)
// ------------------------------------------------------------
} elseif($action==='detail'){
    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if($orderId<=0){
        echo "<div class='alert alert-danger'>Pedido inválido.</div>";
        return;
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        // 1) Status
        if(isset($_POST['novo_status'])){
            $ns=trim($_POST['novo_status']);
            try {
                $stm1=$pdo->prepare("UPDATE orders SET status=? WHERE id=?");
                $stm1->execute([$ns, $orderId]);
            } catch(Exception $e){
                echo "<div class='alert alert-danger'>Erro status: ".htmlspecialchars($e->getMessage())."</div>";
            }
        }
        // 2) admin_comments
        if(isset($_POST['admin_comments'])){
            $novaMsg=trim($_POST['admin_comments']);
            try{
                $stmOld=$pdo->prepare("SELECT admin_comments FROM orders WHERE id=?");
                $stmOld->execute([$orderId]);
                $oldTxt='';
                if($rO=$stmOld->fetch(PDO::FETCH_ASSOC)){
                    $oldTxt=$rO['admin_comments']??'';
                }
                $textoFinal=$oldTxt."\n".$novaMsg;
                $stmU=$pdo->prepare("UPDATE orders SET admin_comments=? WHERE id=?");
                $stmU->execute([$textoFinal, $orderId]);
            } catch(Exception $e){
                echo "<div class='alert alert-danger'>Erro msg: ".htmlspecialchars($e->getMessage())."</div>";
            }
        }
        // 3) Ajustar pontos
        if(isset($_POST['pontos_valor'])){
            $pts=(int)$_POST['pontos_valor'];
            if($pts!==0){
                try{
                    $stmC=$pdo->prepare("SELECT customer_id FROM orders WHERE id=?");
                    $stmC->execute([$orderId]);
                    if($rc=$stmC->fetch(PDO::FETCH_ASSOC)){
                        $cid=(int)$rc['customer_id'];
                        if($cid>0){
                            $stmPC=$pdo->prepare("UPDATE customers SET points=points+:p WHERE id=:id");
                            $stmPC->execute([':p'=>$pts,':id'=>$cid]);
                        }
                    }
                }catch(Exception $e){
                    echo "<div class='alert alert-danger'>Erro pontos: ".htmlspecialchars($e->getMessage())."</div>";
                }
            }
        }
        // 4) tracking_code
        if(isset($_POST['tracking_code'])){
            $tc=trim($_POST['tracking_code']);
            try{
                $stmT=$pdo->prepare("UPDATE orders SET tracking_code=? WHERE id=?");
                $stmT->execute([$tc, $orderId]);
            }catch(Exception $e){
                echo "<div class='alert alert-danger'>Erro rastreio: ".htmlspecialchars($e->getMessage())."</div>";
            }
        }

        // Redirecionar
        header("Location: index.php?page=pedidos&action=detail&id=$orderId");
        exit;
    }

    // Carregar pedido
    try{
        $stmO=$pdo->prepare("SELECT * FROM orders WHERE id=?");
        $stmO->execute([$orderId]);
        $order=$stmO->fetch(PDO::FETCH_ASSOC);
        if(!$order){
            echo "<div class='alert alert-danger'>Pedido não encontrado!</div>";
            return;
        }
    }catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro ao buscar pedido: ".htmlspecialchars($e->getMessage())."</div>";
        return;
    }

    // Itens
    $itens=[];
    try{
        $stmI=$pdo->prepare("
          SELECT oi.*, p.cost AS cost_atual
          FROM order_items oi
          LEFT JOIN products p ON oi.product_id=p.id
          WHERE oi.order_id=?
        ");
        $stmI->execute([$orderId]);
        $itens=$stmI->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro itens: ".htmlspecialchars($e->getMessage())."</div>";
    }

    // Pontos
    $pontosAtuais=0;
    $cid=(int)($order['customer_id']??0);
    if($cid>0){
        try{
            $sx=$pdo->prepare("SELECT points FROM customers WHERE id=?");
            $sx->execute([$cid]);
            if($rx=$sx->fetch(PDO::FETCH_ASSOC)){
                $pontosAtuais=(int)$rx['points'];
            }
        }catch(Exception $e){}
    }

    // Calcular
    $venda=0; 
    $custo=0;
    foreach($itens as &$ix){
        $venda+=(float)$ix['subtotal'];
        $ca=(float)($ix['cost_atual']??0);
        $q=(int)$ix['quantity'];
        $ix['cost_subtotal_atual']=$ca*$q;
        $custo+=$ix['cost_subtotal_atual'];
    }
    unset($ix);

    $freteOriginal=(float)($order['shipping_value']??0);
    $finalVal     =(float)($order['final_value']??0);
    $freteParaCusto=$freteOriginal-5;
    if($freteParaCusto<0) $freteParaCusto=0;
    $custoPedido=$custo+$freteParaCusto;
    $lucro=$finalVal-$custoPedido;

    $qtdTotalProd= array_sum(array_column($itens,'quantity'));
    $tipoFrete=$order['shipping_type']??'---';

    // Mensagem interna (somente custo)
    $msgInterna="Bruno Big\n($tipoFrete)\n\n";
    $msgInterna.="Nome: ".($order['customer_name']??'')."\n";
    $msgInterna.="CPF: ".($order['cpf']??'')."\n";
    $msgInterna.="E-mail: ".($order['email']??'')."\n";
    $msgInterna.="Telefone: ".($order['phone']??'')."\n";
    $msgInterna.="Endereço: ".($order['address']??'')."\n";
    $msgInterna.="Número: ".($order['number']??'')."\n";
    $msgInterna.="Complemento: ".($order['complement']??'')."\n";
    $msgInterna.="CEP: ".($order['cep']??'')."\n";
    $msgInterna.="Bairro: ".($order['neighborhood']??'')."\n";
    $msgInterna.="Cidade: ".($order['city']??'')."\n";
    $msgInterna.="Estado: ".($order['state']??'')."\n\n";
    $msgInterna.="FRETE: ".number_format($freteParaCusto,2,',','.')."\n\n";
    $msgInterna.="PRODUTOS:\n";
    foreach($itens as $ix){
        $q =(int)$ix['quantity'];
        $nm=$ix['product_name']??'';
        $br=$ix['brand']??'';
        $cs=(float)$ix['cost_subtotal_atual'];
        $linha="{$q}x {$nm}";
        if($br) $linha.=" ({$br})";
        $linha.=" => ".number_format($cs,2,',','.');
        $msgInterna.=$linha."\n";
    }
    $msgInterna.="\nTOTAL: ".number_format($custoPedido,2,',','.')."\n";

    // Comprovante
    $compUrl=$order['comprovante_url']??'';
    // Ajuste caso haja caminho antigo
    if(stripos($compUrl,'/miguel/uploads/')!==false){
        $compUrl=str_replace('/miguel/uploads/','/uploads/',$compUrl);
    }

    ?>
    <h2>Detalhes do Pedido #<?= $order['id']??'' ?></h2>
    <div class="painel-card mb-3">
      <h3>Informações do Pedido</h3>
      <p>
        <strong>Status:</strong> <?= htmlspecialchars($order['status']??'') ?><br>
        <strong>Data Criação:</strong> <?= htmlspecialchars($order['created_at']??'--') ?><br>
        <strong>Qtd. Total de Produtos:</strong> <?= (int)$qtdTotalProd ?><br>
        <strong>Frete Original:</strong> R$ <?= number_format($freteOriginal,2,',','.') ?>
        <small>(-5 no custo interno)</small>
      </p>
      <!-- Form p/ editar -->
      <form method="POST" class="mb-3">
        <div class="mb-2">
          <label><b>Alterar Status:</b></label>
          <?php
            $possSt=['PENDENTE','CONFIRMADO','EM PROCESSO','CANCELADO','CONCLUIDO'];
          ?>
          <select name="novo_status" class="form-select d-inline-block w-auto">
            <?php foreach($possSt as $sx){
                $sel=($order['status']===$sx)?'selected':'';
                echo "<option value='$sx' $sel>$sx</option>";
            } ?>
          </select>
        </div>
        <div class="mb-2">
          <label><b>Código de Rastreio:</b></label>
          <input type="text"
                 name="tracking_code"
                 class="form-control"
                 value="<?= htmlspecialchars($order['tracking_code']??'') ?>"
          >
        </div>
        <div class="mb-2">
          <label><b>Mensagem Interna (append):</b></label>
          <textarea name="admin_comments" rows="2" class="form-control"></textarea>
        </div>
        <div class="mb-2">
          <label><b>Ajuste Pontos (±):</b></label>
          <input type="number" name="pontos_valor" value="0" style="width:120px;" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
      </form>
      <div style="background:#f9f9f9; border:1px solid #ccc; padding:8px;">
        <strong>Histórico de Mensagens:</strong><br>
        <div style="white-space:pre-wrap; margin-top:4px;">
          <?= nl2br(htmlspecialchars($order['admin_comments']??'Nenhum registro.')) ?>
        </div>
      </div>
    </div>

    <div class="painel-card mb-3">
      <h3>Ajustar Pontos do Cliente</h3>
      <p>Pontuação atual: <strong><?= $pontosAtuais ?></strong></p>
    </div>

    <div class="painel-card mb-3">
      <h3>Dados do Cliente</h3>
      <p style="line-height:1.5;">
        <strong>Nome:</strong> <?= htmlspecialchars($order['customer_name']??'') ?><br>
        <strong>CPF:</strong> <?= htmlspecialchars($order['cpf']??'') ?><br>
        <strong>Telefone:</strong> <?= htmlspecialchars($order['phone']??'') ?><br>
        <strong>E-mail:</strong> <?= htmlspecialchars($order['email']??'') ?><br>
        <strong>Endereço:</strong> <?= htmlspecialchars($order['address']??'') ?><br>
        <strong>Número:</strong> <?= htmlspecialchars($order['number']??'') ?><br>
        <strong>Complemento:</strong> <?= htmlspecialchars($order['complement']??'') ?><br>
        <strong>Bairro:</strong> <?= htmlspecialchars($order['neighborhood']??'') ?><br>
        <strong>Cidade/Estado:</strong>
          <?= htmlspecialchars($order['city']??'')."/".htmlspecialchars($order['state']??'') ?><br>
        <strong>CEP:</strong> <?= htmlspecialchars($order['cep']??'') ?><br>
      </p>
    </div>

    <div class="painel-card mb-3">
      <h3>Itens do Pedido (Custo Atual)</h3>
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead>
            <tr>
              <th>ID Item</th>
              <th>Produto</th>
              <th>Marca</th>
              <th>Qtd</th>
              <th>Custo Unit (R$)</th>
              <th>Custo Subtotal (R$)</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$itens): ?>
            <tr>
              <td colspan="6" class="text-muted text-center">Nenhum item encontrado</td>
            </tr>
          <?php else: ?>
            <?php foreach($itens as $it): ?>
              <tr>
                <td><?= (int)$it['order_item_id'] ?></td>
                <td><?= htmlspecialchars($it['product_name']??'') ?></td>
                <td><?= htmlspecialchars($it['brand']??'') ?></td>
                <td><?= (int)$it['quantity'] ?></td>
                <td>R$ <?= number_format((float)($it['cost_atual']??0),2,',','.') ?></td>
                <td>R$ <?= number_format((float)($it['cost_subtotal_atual']??0),2,',','.') ?></td>
              </tr>
            <?php endforeach;?>
          <?php endif;?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="painel-card mb-3">
      <h3>Comprovante de Pagamento</h3>
      <?php if($compUrl): ?>
        <p>
          <a href="<?= htmlspecialchars($compUrl) ?>"
             target="_blank"
             class="btn btn-primary btn-sm"
          >
            Ver Comprovante
          </a>
        </p>
      <?php else: ?>
        <p class="text-muted">Nenhum comprovante disponível.</p>
      <?php endif;?>
    </div>

    <?php
    // Resumo finance
    $discountValue  =(float)($order['discount_value']??0);
    $cardFeeValue   =(float)($order['card_fee_value']??0);
    $insuranceValue =(float)($order['insurance_value']??0);
    ?>
    <div class="painel-card mb-3">
      <h3>Métricas &amp; Resumo Financeiro</h3>
      <ul style="list-style:none; padding-left:0;">
        <li><strong>Venda Itens (sem frete):</strong> R$ <?= number_format($venda,2,',','.') ?></li>
        <li><strong>Frete (Cobrado):</strong> R$ <?= number_format($freteOriginal,2,',','.') ?></li>
        <li><strong>Venda Total:</strong> R$ <?= number_format($finalVal,2,',','.') ?></li>
        <li><hr></li>
        <li><strong>Custo Itens:</strong> R$ <?= number_format($custo,2,',','.') ?></li>
        <li><strong>Custo Frete:</strong> R$ <?= number_format($freteParaCusto,2,',','.') ?> <small>(-5 p/ custo interno)</small></li>
        <li><strong>Custo Total:</strong> R$ <?= number_format($custoPedido,2,',','.') ?></li>
        <li><strong>Lucro Estimado:</strong> R$ <?= number_format($lucro,2,',','.') ?></li>
        <?php if($discountValue>0):?>
          <li><strong>Desconto:</strong> R$ <?= number_format($discountValue,2,',','.')?></li>
        <?php endif;?>
        <?php if($cardFeeValue>0):?>
          <li><strong>Taxa Cartão:</strong> R$ <?= number_format($cardFeeValue,2,',','.')?></li>
        <?php endif;?>
        <?php if($insuranceValue>0):?>
          <li><strong>Seguro:</strong> R$ <?= number_format($insuranceValue,2,',','.')?></li>
        <?php endif;?>
      </ul>
    </div>

    <?php
    $msgInterna.="\nTOTAL: ".number_format($custoPedido,2,',','.')."\n";
    ?>
    <div class="painel-card mb-3">
      <h3>Mensagem Interna (Somente Custo)</h3>
      <textarea id="msgInterna"
                class="form-control"
                rows="6"
                style="font-family:monospace;"
      ><?= htmlspecialchars($msgInterna) ?></textarea>
      <button class="btn btn-primary btn-sm mt-2" onclick="copiarInterna()">
        Copiar Mensagem
      </button>
    </div>
    <script>
    function copiarInterna(){
      const t = document.getElementById('msgInterna');
      t.select();
      document.execCommand('copy');
      alert('Mensagem interna copiada!');
    }
    </script>

    <!-- Botões de Voltar e Enviar WPP -->
    <p>
      <a href="index.php?page=pedidos" class="btn btn-secondary btn-sm">
        &laquo; Voltar
      </a>

      <!-- >>> NOVO <<< Botão para enviar via WPP manualmente -->
      <a href="index.php?page=pedidos&action=sendWpp&id=<?= $order['id'] ?>"
         class="btn btn-success btn-sm"
         style="margin-left:10px;"
      >
        Enviar pedido WPP
      </a>
    </p>
    <?php

// ------------------------------------------------------------
// (2.1) ENVIAR PEDIDO MANUALMENTE VIA WHATSAPP (action=sendWpp)
// ------------------------------------------------------------
} elseif($action==='sendWpp') {
    // >>> NOVO <<<

    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if($orderId<=0){
        echo "<div class='alert alert-danger'>ID de pedido inválido.</div>";
        return;
    }

    // 1) Carrega o pedido
    try{
        $stmO=$pdo->prepare("SELECT * FROM orders WHERE id=?");
        $stmO->execute([$orderId]);
        $order=$stmO->fetch(PDO::FETCH_ASSOC);
        if(!$order){
            echo "<div class='alert alert-danger'>Pedido não encontrado!</div>";
            return;
        }
    }catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro ao buscar pedido: ".htmlspecialchars($e->getMessage())."</div>";
        return;
    }

    // 2) Carrega itens
    $itens=[];
    try{
        $stmI=$pdo->prepare("
          SELECT oi.*, p.cost AS cost_atual
          FROM order_items oi
          LEFT JOIN products p ON oi.product_id=p.id
          WHERE oi.order_id=?
        ");
        $stmI->execute([$orderId]);
        $itens=$stmI->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro ao buscar itens: ".htmlspecialchars($e->getMessage())."</div>";
        return;
    }

    // 3) Monta mensagem interna (igual detail)
    //    (Copia a mesma lógica do detail para calcular custo total)
    $freteOriginal=(float)($order['shipping_value']??0);
    $tipoFrete=$order['shipping_type']??'';
    // Calcula custo
    $custo=0;
    foreach($itens as $it){
        $qtd  = (int)($it['quantity']??1);
        $ct   = (float)($it['cost_atual']??0);
        $custo+=($qtd*$ct);
    }
    $freteParaCusto = $freteOriginal - 5;
    if($freteParaCusto<0) $freteParaCusto=0;
    $custoPedido=$custo+$freteParaCusto;

    $msgInterna  = "Bruno Big\n($tipoFrete)\n\n";
    $msgInterna .= "Nome: ".($order['customer_name']??'')."\n";
    $msgInterna .= "CPF: ".($order['cpf']??'')."\n";
    $msgInterna .= "E-mail: ".($order['email']??'')."\n";
    $msgInterna .= "Telefone: ".($order['phone']??'')."\n";
    $msgInterna .= "Endereço: ".($order['address']??'')."\n";
    $msgInterna .= "Número: ".($order['number']??'')."\n";
    $msgInterna .= "Complemento: ".($order['complement']??'')."\n";
    $msgInterna .= "CEP: ".($order['cep']??'')."\n";
    $msgInterna .= "Bairro: ".($order['neighborhood']??'')."\n";
    $msgInterna .= "Cidade: ".($order['city']??'')."\n";
    $msgInterna .= "Estado: ".($order['state']??'')."\n\n";
    $msgInterna .= "FRETE: ".number_format($freteParaCusto,2,',','.')."\n\n";
    $msgInterna .= "PRODUTOS:\n";

    foreach($itens as $ix){
        $q   = (int)($ix['quantity']??1);
        $nm  = $ix['product_name']??'';
        $br  = $ix['brand']??'';
        $cst = (float)($ix['cost_atual']??0) * $q;
        $linha = "{$q}x {$nm}";
        if($br){
            $linha.=" ({$br})";
        }
        $linha.=" => ".number_format($cst,2,',','.');
        $msgInterna.=$linha."\n";
    }
    $msgInterna .= "\nTOTAL: ".number_format($custoPedido,2,',','.')."\n";

    // 3.1) Se tiver comprovante, adiciona link no final
    $compUrl = $order['comprovante_url']??'';
    if(!empty($compUrl)){
        $msgInterna.="\nComprovante: ".$compUrl;
    }

    // 4) Dispara para o admin
    $numeroAdm='351932356037'; // Ajuste se precisar
    $ok = enviarMensagemWascript($numeroAdm, $msgInterna);

    // 5) Mostra resultado
    if($ok){
        echo "<div class='alert alert-success'>Pedido #$orderId enviado ao WhatsApp com sucesso!</div>";
    } else {
        echo "<div class='alert alert-danger'>Falha ao enviar pedido #$orderId via WhatsApp.</div>";
    }

    ?>
    <p>
      <a href="index.php?page=pedidos&action=detail&id=<?= $orderId ?>"
         class="btn btn-primary"
      >
        Voltar ao Detalhe
      </a>
    </p>
    <?php

// ------------------------------------------------------------
// (3) ABA DE RASTREIOS (action=rastreios) - MULTILINE
// ------------------------------------------------------------
} elseif($action==='rastreios') {

    // Se enviou POST finalMatches => confirm
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['finalMatches'])) {
        $sqlU="UPDATE orders SET tracking_code=? WHERE id=?";
        $stmU=$pdo->prepare($sqlU);
        $countOk=0;
        foreach($_POST['finalMatches'] as $idx=>$arr) {
            $check=isset($arr['check']);
            $pid=(int)($arr['radio']??0);
            $tr = trim($arr['track']??'');
            if($check && $pid>0 && $tr!==''){
                try{
                    $stmU->execute([$tr,$pid]);
                    $countOk++;
                } catch(Exception $e){
                    echo "<div class='alert alert-danger'>Erro #$pid: ".htmlspecialchars($e->getMessage())."</div>";
                }
            }
        }
        echo "<div class='alert alert-success'>Foram atualizados $countOk rastreios!</div>";
        ?>
        <p><a href="index.php?page=pedidos&action=rastreios" class="btn btn-secondary">Voltar</a></p>
        <?php
        return;
    }

    // Se enviou textoBruto => parse
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['textoBruto'])) {
        $txt=trim($_POST['textoBruto']);
        if($txt===''){
            echo "<div class='alert alert-danger'>Nenhum bloco informado!</div>";
        } else {
            // separar
            $rawBlocos=explode("------",$txt);
            $arrOK=[];
            $arrFail=[];
            foreach($rawBlocos as $bk) {
                $bk=trim($bk);
                if($bk==='')continue;

                // extrair
                $nome=''; $cidade=''; $uf=''; $cep=''; $obj=''; $cod='';
                $linhas=explode("\n",$bk);
                foreach($linhas as $ln){
                    $ln=trim($ln);
                    if(stripos($ln,'NOME:')===0) {
                        $nome=trim(substr($ln,5));
                    } elseif(stripos($ln,'CIDADE:')===0) {
                        // ex: GUARULHOS - SP
                        $tmp=trim(substr($ln,7));
                        $pp=explode('-',$tmp);
                        $cidade=trim($pp[0]??'');
                        $uf=trim($pp[1]??'');
                    } elseif(stripos($ln,'CEP:')===0){
                        $cep=trim(substr($ln,4));
                    } elseif(stripos($ln,'OBJETO:')===0){
                        $obj=trim(substr($ln,7));
                    } elseif(stripos($ln,'CODIGO:')===0){
                        $cod=trim(substr($ln,7));
                    }
                }

                $binfo=[
                  'raw'=>$bk,
                  'nome'=>$nome,
                  'cidade'=>$cidade,
                  'uf'=>$uf,
                  'cep'=>$cep,
                  'objeto'=>$obj,
                  'codigo'=>$cod,
                  'results'=>[]
                ];
                if(!$cep || !$cod){
                    // Falta info
                    $arrFail[]=$binfo;
                    continue;
                }
                // Limpar CEP
                $cepLimpo=preg_replace('/[^0-9]/','',$cep);

                // Buscar no BD
                $sqlS="SELECT id, customer_name, city, tracking_code FROM orders
                       WHERE REPLACE(cep,'-','')=:c";
                $stmS=$pdo->prepare($sqlS);
                $stmS->execute([':c'=>$cepLimpo]);
                $found=$stmS->fetchAll(PDO::FETCH_ASSOC);
                if(!$found){
                    // sem
                    $arrFail[]=$binfo;
                    continue;
                }
                // Normalizar
                $nomeNorm  = normalize_str($nome.' '.$uf);
                $cidadeNorm= normalize_str($cidade.' '.$uf);

                // ver se Bate
                $poss=[];
                foreach($found as $fd) {
                    // normalizar
                    $bdName= normalize_str($fd['customer_name']??'');
                    $bdCity= normalize_str($fd['city']??'');

                    $score1= similar_score($nomeNorm, $bdName);
                    $score2= similar_score($cidadeNorm, $bdCity);
                    $maxS  = max($score1,$score2); // ou media ?

                    // Se > 30%, considera
                    if($maxS>=30) {
                        $poss[]=[
                          'pedido_id'=>(int)$fd['id'],
                          'customer_name'=>$fd['customer_name']??'',
                          'city'=>$fd['city']??'',
                          'tracking_code'=>$fd['tracking_code']??'',
                          'score_nome'=>$score1,
                          'score_cidade'=>$score2,
                          'score_total'=>$maxS
                        ];
                    }
                }
                if(!$poss) {
                    $arrFail[]=$binfo;
                } else {
                    // Ordenar desc
                    usort($poss,function($x,$y){
                        return $y['score_total']<=>$x['score_total'];
                    });
                    $binfo['results']=$poss;
                    $arrOK[]=$binfo;
                }
            }
            ?>
            <h2>Revisão de Rastreios</h2>
            <form method="POST">
              <input type="hidden" name="finalMatches" value="1" disabled style="display:none;">

              <?php if($arrOK): ?>
                <div class="alert alert-info">
                  <strong><?= count($arrOK) ?> blocos com match</strong>
                </div>
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Marcar</th>
                      <th>Bloco</th>
                      <th>Possíveis Pedidos</th>
                      <th>Rastreio</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach($arrOK as $iB=>$bkx): ?>
                    <tr>
                      <td>
                        <?php foreach($bkx['results'] as $iR=>$rpd): ?>
                          <?php
                            $pid=(int)$rpd['pedido_id'];
                            $score= round($rpd['score_total']);
                          ?>
                          <div style="margin-bottom:5px;">
                            <input type="radio"
                                   name="finalMatches[<?= $iB ?>][radio]"
                                   id="r_<?= $iB ?>_<?= $iR ?>"
                                   value="<?= $pid ?>"
                                   <?= $iR===0?'checked':'' ?>
                            >
                            <label for="r_<?= $iB ?>_<?= $iR ?>">
                              #<?= $pid ?> (<?= $score ?>%)
                            </label>
                          </div>
                        <?php endforeach;?>
                        <div class="mt-1">
                          <input type="checkbox"
                                 name="finalMatches[<?= $iB ?>][check]"
                                 value="1" checked
                          > Confirmar
                        </div>
                      </td>
                      <td style="white-space:pre-wrap;">
                        <?= nl2br(htmlspecialchars($bkx['raw'])) ?>
                      </td>
                      <td>
                        <?php foreach($bkx['results'] as $rpd): ?>
                          <?php
                            $pid= $rpd['pedido_id'];
                            $nm = $rpd['customer_name'];
                            $ct = $rpd['city'];
                            $tk = $rpd['tracking_code'];
                            $sc = round($rpd['score_total']);
                          ?>
                          <p style="border-bottom:1px dashed #ccc; padding-bottom:4px; margin-bottom:6px;">
                            Pedido #<?= $pid ?><br>
                            Nome: <?= htmlspecialchars($nm) ?><br>
                            Cidade: <?= htmlspecialchars($ct) ?><br>
                            Score=<?= $sc ?>%, Atual= <?= htmlspecialchars($tk) ?>
                          </p>
                        <?php endforeach;?>
                      </td>
                      <td>
                        <input type="text" readonly class="form-control"
                               value="<?= htmlspecialchars($bkx['codigo']) ?>"
                        >
                        <input type="hidden"
                               name="finalMatches[<?= $iB ?>][track]"
                               value="<?= htmlspecialchars($bkx['codigo']) ?>"
                        >
                      </td>
                    </tr>
                  <?php endforeach;?>
                  </tbody>
                </table>
              <?php endif;?>

              <?php if($arrFail): ?>
                <div class="alert alert-warning mt-3">
                  <strong><?= count($arrFail) ?> blocos sem match</strong>
                </div>
                <ul>
                  <?php foreach($arrFail as $ff): ?>
                    <li style="white-space:pre-wrap; margin-bottom:5px;">
                      <?= nl2br(htmlspecialchars($ff['raw'])) ?>
                    </li>
                  <?php endforeach;?>
                </ul>
              <?php endif;?>

              <button type="submit" class="btn btn-primary" onclick="enableFinal()">
                Confirmar
              </button>
              <a href="index.php?page=pedidos" class="btn btn-secondary">Cancelar</a>
            </form>
            <script>
            function enableFinal(){
              document.querySelector('input[name="finalMatches"]').disabled=false;
            }
            </script>
            <?php
            return;
        }
    }

    // Form inicial
    ?>
    <h2>Importar Rastreios (Avançado)</h2>
    <p>Formato:
<pre>
NOME: FULANO DE TAL
CIDADE: CIDADE - UF
CEP: 12345-678

OBJETO: TXAS...
CODIGO: AA00750...
------
(repetir)
</pre>
    </p>
    <form method="POST">
      <textarea name="textoBruto" rows="10" class="form-control"
                placeholder="Cole aqui cada bloco separado por ------"
      ></textarea>
      <br>
      <button type="submit" class="btn btn-success">Analisar Blocos</button>
    </form>
    <p>
      <a href="index.php?page=pedidos" class="btn btn-secondary mt-3">
        &laquo; Voltar
      </a>
    </p>
    <?php

// ------------------------------------------------------------
// Caso action desconhecido
// ------------------------------------------------------------
} else {
    echo "<h2 class='text-danger'>Ação inválida em pedidos.</h2>";
}
