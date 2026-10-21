<?php
session_start();
$jsonFile = 'futebol98.json';

function loadData() {
    global $jsonFile;
    if (!file_exists($jsonFile)) {
        $initialData = [
            'users' => [
                'admin' => ['pass' => password_hash('1234', PASSWORD_DEFAULT), 'balance_cents' => 50000, 'role' => 'admin', 'active_bets' => []]
            ],
            'affiliates' => [],
            'settings' => [
                'client_id' => 'ep_3494f9fae8d92e2463294ac2e28e0266',
                'client_secret' => 'eps_9df1cdc2257b5749b385d796347dadfc06f648153c7d26790dc06b3a9cd281d0',
                'commission_pct' => 15,
                'win_rate_pct' => 75
            ],
            'stats' => ['clicks' => 0]
        ];
        file_put_contents($jsonFile, json_encode($initialData, JSON_PRETTY_PRINT));
        return $initialData;
    }
    $content = file_get_contents($jsonFile);
    $data = json_decode($content, true);
    if (!isset($data['settings'])) {
        $data['settings'] = [
            'client_id' => 'ep_3494f9fae8d92e2463294ac2e28e0266',
            'client_secret' => 'eps_9df1cdc2257b5749b385d796347dadfc06f648153c7d26790dc06b3a9cd281d0',
            'commission_pct' => 15,
            'win_rate_pct' => 75
        ];
        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    if (!isset($data['settings']['win_rate_pct'])) {
        $data['settings']['win_rate_pct'] = 75;
    }
    // Remove pix_key se existir em instalações antigas
    if (isset($data['settings']['pix_key'])) {
        unset($data['settings']['pix_key']);
        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    foreach ($data['users'] as $u => &$info) {
        if (!isset($info['active_bets'])) {
            $info['active_bets'] = [];
        }
        if (isset($info['credits']) && !isset($info['balance_cents'])) {
            $info['balance_cents'] = $info['credits'] * 100;
            unset($info['credits']);
        }
        if (!isset($info['balance_cents'])) {
            $info['balance_cents'] = 50000;
        }
    }
    unset($info);
    return $data;
}

function saveData($data) {
    global $jsonFile;
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
}

$dbData = loadData();
$page = $_GET['page'] ?? 'auth';
$msg = '';
$pixQrCodeUrl = '';
$pixCopyPaste = '';

if (isset($_GET['webhook'])) {
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    if ($body && (isset($body['status']) && ($body['status'] === 'PAID' || $body['status'] === 'COMPLETO' || $body['status'] === 'COMPLETED' || $body['success'] === true))) {
        $amountPaid = floatval($body['amount'] ?? 0); 
        $usernameRef = $body['external_reference'] ?? $body['username'] ?? '';
        
        if (!empty($usernameRef) && isset($dbData['users'][$usernameRef])) {
            $centsEarned = intval($amountPaid * 100); 
            $dbData['users'][$usernameRef]['balance_cents'] += $centsEarned;
            saveData($dbData);
        }
    }
    echo json_encode(['received' => true]);
    exit;
}

if (isset($_GET['ref'])) {
    $ref = trim($_GET['ref']);
    if (isset($dbData['users'][$ref])) {
        if (!isset($dbData['affiliates'][$ref])) {
            $dbData['affiliates'][$ref] = ['clicks' => 0, 'referrals' => 0, 'earnings_cents' => 0];
        }
        $dbData['affiliates'][$ref]['clicks']++;
        saveData($dbData);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $refBy = trim($_POST['ref_by'] ?? '');

        if (empty($username) || empty($password)) {
            $msg = "Preencha todos os campos!";
        } elseif (isset($dbData['users'][$username])) {
            $msg = "Este nome de usuário já existe!";
        } else {
            $dbData['users'][$username] = [
                'pass' => password_hash($password, PASSWORD_DEFAULT),
                'balance_cents' => 10000, // R$ 100,00 inicial
                'role' => 'user',
                'active_bets' => []
            ];
            
            if (!empty($refBy) && isset($dbData['users'][$refBy])) {
                if (!isset($dbData['affiliates'][$refBy])) {
                    $dbData['affiliates'][$refBy] = ['clicks' => 0, 'referrals' => 0, 'earnings_cents' => 0];
                }
                $dbData['affiliates'][$refBy]['referrals']++;
                $bonusCents = 1000; // R$ 10,00
                $dbData['affiliates'][$refBy]['earnings_cents'] += $bonusCents;
                $dbData['users'][$refBy]['balance_cents'] += $bonusCents;
            }

            saveData($dbData);
            $msg = "Conta criada com sucesso! Faça login.";
            $page = 'auth';
        }
    }

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $redirectType = $_POST['redirect_type'] ?? 'game';

        if (isset($dbData['users'][$username]) && password_verify($password, $dbData['users'][$username]['pass'])) {
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $dbData['users'][$username]['role'];
            
            if ($redirectType === 'affiliate') {
                header("Location: index.php?page=affiliate");
            } else {
                header("Location: index.php?page=game");
            }
            exit;
        } else {
            $msg = "Usuário ou senha inválidos!";
        }
    }

    if ($action === 'admin_login') {
        $password = $_POST['admin_pass'] ?? '';
        if ($password === '1234') {
            $_SESSION['admin_logged'] = true;
            header("Location: index.php?page=admin");
            exit;
        } else {
            $msg = "Senha de administrador incorreta!";
        }
    }

    if ($action === 'admin_action' && isset($_SESSION['admin_logged'])) {
        $subAction = $_POST['sub_action'] ?? '';

        if ($subAction === 'update_settings') {
            $dbData['settings']['client_id'] = trim($_POST['client_id']);
            $dbData['settings']['client_secret'] = trim($_POST['client_secret']);
            $dbData['settings']['commission_pct'] = floatval($_POST['commission_pct']);
            
            // Taxa de vitória ajustada pelo adm (limitada entre 10% e 90% para garantir a lógica de que a casa sempre ganha)
            $winRate = intval($_POST['win_rate_pct'] ?? 75);
            if ($winRate > 90) $winRate = 90;
            if ($winRate < 10) $winRate = 10;
            $dbData['settings']['win_rate_pct'] = $winRate;

            saveData($dbData);
            $msg = "Configurações atualizadas com sucesso!";
        } elseif ($subAction === 'add_user') {
            $newU = trim($_POST['new_username']);
            $newP = $_POST['new_password'];
            $newRole = $_POST['new_role'] ?? 'user';
            if (!empty($newU) && !isset($dbData['users'][$newU])) {
                $dbData['users'][$newU] = [
                    'pass' => password_hash($newP, PASSWORD_DEFAULT),
                    'balance_cents' => 20000,
                    'role' => $newRole,
                    'active_bets' => []
                ];
                if ($newRole === 'affiliate' && !isset($dbData['affiliates'][$newU])) {
                    $dbData['affiliates'][$newU] = ['clicks' => 0, 'referrals' => 0, 'earnings_cents' => 0];
                }
                saveData($dbData);
                $msg = "Usuário/Afiliado $newU adicionado com sucesso!";
            } else {
                $msg = "Nome de usuário inválido ou já existente.";
            }
        } elseif ($subAction === 'update_balance') {
            $targetUser = $_POST['target_user'] ?? '';
            $valReais = floatval($_POST['balance_val']);
            if (isset($dbData['users'][$targetUser])) {
                $dbData['users'][$targetUser]['balance_cents'] += intval($valReais * 100);
                saveData($dbData);
                $msg = "Saldo de $targetUser atualizado!";
            }
        } elseif ($subAction === 'delete_user') {
            $targetUser = $_POST['target_user'] ?? '';
            if (isset($dbData['users'][$targetUser]) && $targetUser !== 'admin') {
                unset($dbData['users'][$targetUser]);
                unset($dbData['affiliates'][$targetUser]);
                saveData($dbData);
                $msg = "Usuário $targetUser removido!";
            }
        }
    }

    if ($action === 'request_deposit' && isset($_SESSION['username'])) {
        $amount = floatval($_POST['t_amount'] ?? 0);
        $username = $_SESSION['username'];

        if ($amount > 0) {
            $apiUrl = "https://api.elitepaybr.com/api/v1/deposit";
            $payload = json_encode([
                "amount" => $amount,
                "description" => "Deposito Futebol 98 - " . $username,
                "external_reference" => $username,
                "payerName" => $username,
                "payerDocument" => "00000000000"
            ]);

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "ci: " . $dbData['settings']['client_id'],
                "cs: " . $dbData['settings']['client_secret']
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode($response, true);
            
            if ($httpCode >= 200 && $httpCode < 300 && (isset($resData['qrcodeUrl']) || isset($resData['copyPaste']) || isset($resData['pixCode']) || isset($resData['qrcode']))) {
                $pixQrCodeUrl = $resData['qrcodeUrl'] ?? $resData['qrCode'] ?? '';
                $pixCopyPaste = $resData['copyPaste'] ?? $resData['pixCode'] ?? $resData['qrcode'] ?? '';
                $msg = "PIX gerado com sucesso! Escaneie o QR Code ou copie o código abaixo.";
            } else {
                $errDesc = $resData['message'] ?? $resData['error'] ?? 'Erro desconhecido na API de pagamento';
                $msg = "Erro ao gerar Pix: " . $errDesc;
            }
        }
    }

    if ($action === 'request_withdraw' && isset($_SESSION['username'])) {
        $amountReais = floatval($_POST['t_amount'] ?? 0);
        $username = $_SESSION['username'];
        $centsNeeded = intval($amountReais * 100);

        if ($amountReais > 0 && $dbData['users'][$username]['balance_cents'] >= $centsNeeded) {
            $dbData['users'][$username]['balance_cents'] -= $centsNeeded;
            saveData($dbData);
            $msg = "Solicitação de saque de R$ " . number_format($amountReais, 2, ',', '.') . " enviada com sucesso!";
        } else {
            $msg = "Saldo insuficiente para este valor de saque!";
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Tabuleiro de 24 posições com prêmios fracionados/variados
$boardTiles = [
    ['r'=>0, 'c'=>0, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.3, 'emoji'=>'🇧🇷']],
    ['r'=>0, 'c'=>1, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.5, 'emoji'=>'🇦🇷']],
    ['r'=>0, 'c'=>2, 'symbol' => ['id'=>'copa', 'name'=>'Taça', 'mult'=>5.0, 'emoji'=>'🏆']],
    ['r'=>0, 'c'=>3, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.8, 'emoji'=>'🇧🇷']],
    ['r'=>0, 'c'=>4, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.3, 'emoji'=>'🇦🇷']],
    ['r'=>0, 'c'=>5, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>1.5, 'emoji'=>'🇧🇷']],
    ['r'=>0, 'c'=>6, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>1.2, 'emoji'=>'🇦🇷']],
    ['r'=>1, 'c'=>6, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.5, 'emoji'=>'🇧🇷']],
    ['r'=>2, 'c'=>6, 'symbol' => ['id'=>'copa', 'name'=>'Taça', 'mult'=>10.0, 'emoji'=>'🏆']],
    ['r'=>3, 'c'=>6, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.8, 'emoji'=>'🇦🇷']],
    ['r'=>4, 'c'=>6, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>2.0, 'emoji'=>'🇧🇷']],
    ['r'=>5, 'c'=>6, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.5, 'emoji'=>'🇦🇷']],
    ['r'=>6, 'c'=>6, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.3, 'emoji'=>'🇧🇷']],
    ['r'=>6, 'c'=>5, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>1.5, 'emoji'=>'🇦🇷']],
    ['r'=>6, 'c'=>4, 'symbol' => ['id'=>'copa', 'name'=>'Taça', 'mult'=>5.0, 'emoji'=>'🏆']],
    ['r'=>6, 'c'=>3, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>1.2, 'emoji'=>'🇧🇷']],
    ['r'=>6, 'c'=>2, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.3, 'emoji'=>'🇦🇷']],
    ['r'=>6, 'c'=>1, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.5, 'emoji'=>'🇧🇷']],
    ['r'=>6, 'c'=>0, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>2.0, 'emoji'=>'🇦🇷']],
    ['r'=>5, 'c'=>0, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.8, 'emoji'=>'🇧🇷']],
    ['r'=>4, 'c'=>0, 'symbol' => ['id'=>'copa', 'name'=>'Taça', 'mult'=>10.0, 'emoji'=>'🏆']],
    ['r'=>3, 'c'=>0, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>0.5, 'emoji'=>'🇦🇷']],
    ['r'=>2, 'c'=>0, 'symbol' => ['id'=>'br', 'name'=>'Brasil', 'mult'=>0.3, 'emoji'=>'🇧🇷']],
    ['r'=>1, 'c'=>0, 'symbol' => ['id'=>'ar', 'name'=>'Argentina', 'mult'=>1.2, 'emoji'=>'🇦🇷']]
];

// AJAX: Fazer aposta (cada aposta custa 25 centavos)
if (isset($_GET['ajax_place_bet']) && isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $symId = $input['symbol'] ?? '';
    $username = $_SESSION['username'];
    $dbData = loadData();

    if (!isset($dbData['users'][$username])) {
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }

    $costPerBetCents = 25; // 25 centavos por clique/aposta
    if ($dbData['users'][$username]['balance_cents'] < $costPerBetCents) {
        echo json_encode(['error' => 'Saldo insuficiente! Cada aposta custa R$ 0,25.']);
        exit;
    }

    $dbData['users'][$username]['balance_cents'] -= $costPerBetCents;
    if (!isset($dbData['users'][$username]['active_bets'][$symId])) {
        $dbData['users'][$username]['active_bets'][$symId] = 0;
    }
    $dbData['users'][$username]['active_bets'][$symId] += 1; 
    saveData($dbData);

    echo json_encode([
        'success' => true,
        'newBalanceCents' => $dbData['users'][$username]['balance_cents'],
        'activeBets' => $dbData['users'][$username]['active_bets']
    ]);
    exit;
}

// AJAX: Limpar apostas (reembolsa R$ 0,25 por unidade apostada)
if (isset($_GET['ajax_clear_bets']) && isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    $username = $_SESSION['username'];
    $dbData = loadData();

    if (isset($dbData['users'][$username])) {
        $activeBets = $dbData['users'][$username]['active_bets'] ?? [];
        $totalUnits = 0;
        foreach ($activeBets as $units) {
            $totalUnits += intval($units);
        }
        $refundCents = $totalUnits * 25;
        $dbData['users'][$username]['balance_cents'] += $refundCents;
        $dbData['users'][$username]['active_bets'] = [];
        saveData($dbData);

        echo json_encode([
            'success' => true,
            'newBalanceCents' => $dbData['users'][$username]['balance_cents'],
            'activeBets' => []
        ]);
    }
    exit;
}

// AJAX: Girar a roleta com controle de taxa de vitória (respeitando a lógica de que a casa sempre ganha)
if (isset($_GET['ajax_spin']) && isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    $username = $_SESSION['username'];
    $dbData = loadData();
    $activeBets = $dbData['users'][$username]['active_bets'] ?? [];
    $totalUnits = 0;
    foreach ($activeBets as $units) $totalUnits += intval($units);

    if ($totalUnits <= 0) {
        echo json_encode(['error' => 'Faça ao menos uma aposta!']);
        exit;
    }

    // Lê a taxa de vitória configurada no painel admin (padrão 75%, limitado a máx 90% para garantir vantagem da casa)
    $winRate = intval($dbData['settings']['win_rate_pct'] ?? 75);
    if ($winRate > 90) $winRate = 90;
    if ($winRate < 10) $winRate = 10;

    $winningTilesIndices = [];
    $losingTilesIndices = [];

    foreach ($boardTiles as $idx => $tile) {
        $symId = $tile['symbol']['id'];
        if (isset($activeBets[$symId]) && intval($activeBets[$symId]) > 0) {
            $winningTilesIndices[] = $idx;
        } else {
            $losingTilesIndices[] = $idx;
        }
    }

    $roll = rand(1, 100);
    // Se o roll estiver dentro da taxa de vitória E houver tiles vencedores, seleciona um tile vencedor.
    // Caso contrário, direciona para um tile perdedor (garantindo que o adm controle a probabilidade mas a casa mantenha o controle/margem).
    if ($roll <= $winRate && !empty($winningTilesIndices)) {
        $bestIndex = $winningTilesIndices[array_rand($winningTilesIndices)];
    } else {
        if (!empty($losingTilesIndices)) {
            $bestIndex = $losingTilesIndices[array_rand($losingTilesIndices)];
        } else {
            $bestIndex = rand(0, count($boardTiles) - 1);
        }
    }

    $winningTile = $boardTiles[$bestIndex];
    $unitsOnWin = intval($activeBets[$winningTile['symbol']['id']] ?? 0);
    $winAmountCents = intval(round($unitsOnWin * 25 * $winningTile['symbol']['mult']));

    $dbData['users'][$username]['balance_cents'] += $winAmountCents;
    $dbData['users'][$username]['active_bets'] = [];
    saveData($dbData);

    echo json_encode([
        'winIndex' => $bestIndex,
        'winAmountCents' => $winAmountCents,
        'newBalanceCents' => $dbData['users'][$username]['balance_cents'],
        'winningSymbol' => $winningTile['symbol']
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Futebol 98</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: radial-gradient(circle at center, #0f172a 0%, #020617 100%); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 10px; }
        .card-box {
            background: rgba(15, 23, 42, 0.95);
            border: 2px solid #3b82f6; border-radius: 16px; padding: 24px; width: 100%; max-width: 440px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.8); backdrop-filter: blur(10px);
        }
        h1 { color: #facc15; font-size: 26px; text-align: center; margin-bottom: 20px; font-weight: 800; text-shadow: 0 0 10px rgba(250,204,21,0.4); }
        .input-group { margin-bottom: 14px; }
        label { font-size: 12px; color: #94a3b8; display: block; margin-bottom: 6px; font-weight: 600; }
        .input-field { width: 100%; padding: 12px; background: #020617; border: 1px solid #334155; border-radius: 8px; color: #fff; font-size: 14px; transition: border-color 0.2s; }
        .input-field:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 8px rgba(59,130,246,0.3); }
        .btn { width: 100%; padding: 14px; font-weight: bold; font-size: 14px; border-radius: 8px; cursor: pointer; border: none; text-transform: uppercase; margin-top: 10px; transition: transform 0.1s, filter 0.2s; }
        .btn:active { transform: scale(0.98); }
        .btn-primary { background: linear-gradient(to right, #22c55e, #16a34a); color: #fff; box-shadow: 0 4px 12px rgba(34,197,94,0.4); }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background: #334155; color: #fff; border: 1px solid #475569; }
        .msg { background: rgba(127,29,29,0.8); color: #fca5a5; padding: 10px; border-radius: 8px; font-size: 12px; margin-bottom: 16px; text-align: center; border: 1px solid #ef4444; }
        .link-switch { text-align: center; margin-top: 16px; font-size: 12px; color: #94a3b8; }
        .link-switch a { color: #38bdf8; text-decoration: none; font-weight: 600; }
        .link-switch a:hover { text-decoration: underline; }

        .arcade-cabinet { width: 100%; max-width: 480px; background: rgba(15, 23, 42, 0.95); border: 3px solid #3b82f6; border-radius: 20px; padding: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.9); }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 12px; color: #94a3b8; background: #020617; padding: 8px 12px; border-radius: 8px; border: 1px solid #1e293b; }
        .logout-btn { background: #ef4444; color: #fff; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: bold; }
        .header-scores { display: flex; justify-content: space-between; margin-bottom: 12px; gap: 10px; }
        .score-box { background: #020617; border: 2px solid #334155; border-radius: 10px; padding: 8px; flex: 1; text-align: center; }
        .score-label { font-size: 10px; color: #94a3b8; font-weight: bold; }
        .score-value { font-size: 18px; color: #facc15; font-weight: 800; text-shadow: 0 0 8px rgba(250,204,21,0.5); }
        .board-container { position: relative; background: #1e1b4b; border: 4px solid #facc15; border-radius: 12px; aspect-ratio: 1 / 1; display: grid; grid-template-columns: repeat(7, 1fr); grid-template-rows: repeat(7, 1fr); gap: 3px; padding: 6px; margin-bottom: 12px; box-shadow: inset 0 0 20px rgba(0,0,0,0.8); }
        .cell { background: #312e81; border: 2px solid #4338ca; border-radius: 6px; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: #e0e7ff; }
        .cell.active { background: #facc15 !important; border-color: #fff !important; color: #000 !important; box-shadow: 0 0 20px #facc15; transform: scale(1.08); z-index: 10; }
        .center-screen { grid-column: 2 / 7; grid-row: 2 / 7; background: linear-gradient(135deg, #065f46, #047857); border: 3px ridge #10b981; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 8px; text-align: center; box-shadow: inset 0 0 15px rgba(0,0,0,0.5); }
        .center-screen h1 { font-size: 18px; color: #fde047; text-shadow: 2px 2px #000; margin-bottom: 4px; }
        .center-screen p { font-size: 10px; color: #d1fae5; font-weight: 600; }
        .betting-panel { background: #020617; border: 2px solid #334155; border-radius: 12px; padding: 10px; margin-bottom: 12px; }
        .betting-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 10px; }
        .bet-btn { background: #0f172a; border: 2px solid #334155; border-radius: 8px; padding: 8px 4px; color: #fff; font-size: 11px; cursor: pointer; text-align: center; transition: all 0.2s; }
        .bet-btn.selected { border-color: #22c55e; background: #064e3b; box-shadow: 0 0 8px rgba(34, 197, 94, 0.6); }
        .controls { display: flex; gap: 8px; }
        .btn-action { flex: 1; padding: 10px; font-weight: bold; font-size: 12px; border-radius: 8px; cursor: pointer; border: none; text-transform: uppercase; }
        .btn-spin { background: linear-gradient(to right, #22c55e, #16a34a); color: #fff; border: 2px solid #86efac; box-shadow: 0 4px 12px rgba(34,197,94,0.4); }
        .btn-spin:disabled { background: #475569; border-color: #64748b; cursor: not-allowed; box-shadow: none; }
        .btn-clear { background: linear-gradient(to right, #ef4444, #dc2626); color: #fff; border: 2px solid #fca5a5; }
        .wallet-actions { display: flex; gap: 8px; margin-bottom: 12px; }
        .btn-wallet { flex: 1; background: #1e293b; border: 1px solid #3b82f6; color: #38bdf8; padding: 8px; border-radius: 8px; font-size: 11px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; }
        .btn-wallet:hover { background: #3b82f6; color: #fff; }

        #toast-notification {
            visibility: hidden;
            min-width: 250px;
            background-color: #10b981;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 12px;
            position: fixed;
            z-index: 2000;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            transition: opacity 0.3s, transform 0.3s;
            opacity: 0;
        }
        #toast-notification.show {
            visibility: visible;
            opacity: 1;
            transform: translateX(-50%) translateY(-5px);
        }
    </style>
</head>
<body>

    <div id="toast-notification">📋 Código Pix copiado com sucesso!</div>

    <?php if ($page === 'auth'): ?>
        <div class="card-box">
            <h1>FUTEBOL 98</h1>
            <?php if (!empty($msg)): ?><div class="msg"><?php echo $msg; ?></div><?php endif; ?>

            <?php if (!isset($_GET['register'])): ?>
                <form method="POST" action="index.php?page=auth">
                    <input type="hidden" name="action" value="login">
                    <div class="input-group">
                        <label>Usuário:</label>
                        <input type="text" name="username" class="input-field" required>
                    </div>
                    <div class="input-group">
                        <label>Senha:</label>
                        <input type="password" name="password" class="input-field" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Entrar no Jogo</button>
                </form>
                <div class="link-switch">
                    Não tem conta? <a href="index.php?page=auth&register=1">Criar conta</a><br><br>
                    <a href="index.php?page=affiliate_login">Área de Afiliados</a> | 
                    <a href="index.php?page=admin_login">Painel Admin</a>
                </div>
            <?php else: ?>
                <form method="POST" action="index.php?page=auth">
                    <input type="hidden" name="action" value="register">
                    <div class="input-group">
                        <label>Novo Usuário:</label>
                        <input type="text" name="username" class="input-field" required>
                    </div>
                    <div class="input-group">
                        <label>Senha:</label>
                        <input type="password" name="password" class="input-field" required>
                    </div>
                    <div class="input-group">
                        <label>Código de Indicação (Opcional):</label>
                        <input type="text" name="ref_by" value="<?php echo htmlspecialchars($_GET['ref'] ?? ''); ?>" class="input-field">
                    </div>
                    <button type="submit" class="btn btn-primary">Registrar Conta</button>
                </form>
                <div class="link-switch">
                    Já tem conta? <a href="index.php?page=auth">Fazer Login</a>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($page === 'admin_login'): ?>
        <div class="card-box">
            <h1>ADMINISTRAÇÃO</h1>
            <?php if (!empty($msg)): ?><div class="msg"><?php echo $msg; ?></div><?php endif; ?>
            <form method="POST" action="index.php?page=admin_login">
                <input type="hidden" name="action" value="admin_login">
                <div class="input-group">
                    <label>Senha de Administrador (Padrão: 1234):</label>
                    <input type="password" name="admin_pass" class="input-field" required>
                </div>
                <button type="submit" class="btn btn-primary">Acessar Painel</button>
            </form>
            <div class="link-switch"><a href="index.php">Voltar ao Início</a></div>
        </div>

    <?php elseif ($page === 'affiliate_login'): ?>
        <div class="card-box">
            <h1>ÁREA DE AFILIADOS</h1>
            <p style="font-size:12px; color:#94a3b8; text-align:center; margin-bottom:14px;">Faça login para gerenciar suas indicações e comissões.</p>
            <?php if (!empty($msg)): ?><div class="msg"><?php echo $msg; ?></div><?php endif; ?>
            <form method="POST" action="index.php?page=auth">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="redirect_type" value="affiliate">
                <div class="input-group">
                    <label>Usuário:</label>
                    <input type="text" name="username" class="input-field" required>
                </div>
                <div class="input-group">
                    <label>Senha:</label>
                    <input type="password" name="password" class="input-field" required>
                </div>
                <button type="submit" class="btn btn-primary">Entrar no Painel de Afiliados</button>
            </form>
            <div class="link-switch"><a href="index.php">Voltar ao Início</a></div>
        </div>

    <?php elseif ($page === 'admin' && isset($_SESSION['admin_logged'])): ?>
        <div class="card-box" style="max-width: 650px;">
            <h1>PAINEL ADMIN</h1>
            <?php if (!empty($msg)): ?><div class="msg" style="background:rgba(6,95,70,0.8); color:#a7f3d0; border-color:#10b981;"><?php echo $msg; ?></div><?php endif; ?>
            
            <div style="background:#020617; padding:12px; border-radius:8px; margin-bottom:14px; border:1px solid #334155;">
                <h3 style="color:#38bdf8; font-size:13px; margin-bottom:8px;">⚙️ Configurações da API Pix & Dificuldade</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="admin_action">
                    <input type="hidden" name="sub_action" value="update_settings">
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <div style="flex:1;">
                            <label>Client ID:</label>
                            <input type="text" name="client_id" value="<?php echo htmlspecialchars($dbData['settings']['client_id']); ?>" class="input-field" required>
                        </div>
                        <div style="flex:1;">
                            <label>Client Secret:</label>
                            <input type="text" name="client_secret" value="<?php echo htmlspecialchars($dbData['settings']['client_secret']); ?>" class="input-field" required>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <div style="flex:1;">
                            <label>Comissão Afiliado (%):</label>
                            <input type="number" step="0.1" name="commission_pct" value="<?php echo $dbData['settings']['commission_pct']; ?>" class="input-field" required>
                        </div>
                        <div style="flex:1;">
                            <label title="Chance de acerto do jogador (máx 90% para a casa sempre vencer)">Taxa de Vitória do Jogador (%):</label>
                            <input type="number" min="10" max="90" name="win_rate_pct" value="<?php echo $dbData['settings']['win_rate_pct'] ?? 75; ?>" class="input-field" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="margin-top:2px; padding:8px;">Salvar Configurações</button>
                </form>
            </div>

            <div style="background:#020617; padding:12px; border-radius:8px; margin-bottom:14px; border:1px solid #334155;">
                <h3 style="color:#38bdf8; font-size:13px; margin-bottom:8px;">➕ Adicionar Usuário / Afiliado</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="admin_action">
                    <input type="hidden" name="sub_action" value="add_user">
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <input type="text" name="new_username" placeholder="Nome de usuário" class="input-field" required style="flex:1;">
                        <input type="password" name="new_password" placeholder="Senha" class="input-field" required style="flex:1;">
                        <select name="new_role" class="input-field" style="flex:1;">
                            <option value="user">Usuário / Jogador</option>
                            <option value="affiliate">Afiliado</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="padding:8px;">Cadastrar Novo Membro</button>
                </form>
            </div>

            <h3 style="color:#38bdf8; font-size:13px; margin-bottom:6px;">👥 Gerenciar Usuários & Saldo em Reais</h3>
            <div style="max-height: 180px; overflow-y: auto; margin-bottom: 12px; border: 1px solid #334155; border-radius: 8px; padding: 6px; background:#020617;">
                <?php foreach($dbData['users'] as $u => $info): ?>
                    <div style="background:#0f172a; padding:8px; border-radius:6px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; font-size:12px;">
                        <div>
                            <b><?php echo $u; ?></b> <span style="color:#facc15;">(R$ <?php echo number_format($info['balance_cents']/100, 2, ',', '.'); ?>)</span> - <span style="color:#38bdf8;"><?php echo $info['role']; ?></span>
                        </div>
                        <form method="POST" style="display:flex; gap:4px; align-items:center;">
                            <input type="hidden" name="action" value="admin_action">
                            <input type="hidden" name="sub_action" value="update_balance">
                            <input type="hidden" name="target_user" value="<?php echo $u; ?>">
                            <input type="number" step="0.50" name="balance_val" value="50.00" style="width: 75px; background:#020617; color:#fff; border:1px solid #334155; padding:4px; border-radius:4px; font-size:11px;" title="Valor em R$ a somar/subtrair">
                            <button type="submit" class="btn-secondary" style="padding:4px 6px; font-size:10px; margin:0;">+/- R$</button>
                            <?php if($u !== 'admin'): ?>
                                <button type="submit" name="sub_action" value="delete_user" class="logout-btn" style="padding:4px 6px; font-size:10px; margin:0;" onclick="return confirm('Excluir usuário?')">Excluir</button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align: center;">
                <a href="index.php?logout=1" class="logout-btn" style="padding: 10px 20px; display:inline-block;">Sair do Admin</a>
            </div>
        </div>

    <?php elseif ($page === 'affiliate' && isset($_SESSION['username'])): ?>
        <div class="card-box">
            <h1>PAINEL DE AFILIADO</h1>
            <div style="background: #020617; padding: 12px; border-radius: 8px; font-size: 12px; margin-bottom: 14px; border: 1px solid #334155;">
                <p style="color: #94a3b8; margin-bottom: 6px;">Seu link de indicação exclusivo:</p>
                <div style="background: #000; padding: 8px; border-radius: 6px; color: #22c55e; word-break: break-all; border: 1px dashed #22c55e; font-size: 11px;">
                    https://<?php echo $_SERVER['HTTP_HOST']; ?>/index.php?page=auth&register=1&ref=<?php echo urlencode($_SESSION['username']); ?>
                </div>
            </div>
            <?php 
                $affData = $dbData['affiliates'][$_SESSION['username']] ?? ['clicks' => 0, 'referrals' => 0, 'earnings_cents' => 0];
            ?>
            <div style="font-size: 12px; margin-bottom: 16px; background: #020617; padding: 12px; border-radius: 8px; border: 1px solid #334155;">
                <p style="margin-bottom:6px;">Cliques no link: <b style="color: #38bdf8; float:right;"><?php echo $affData['clicks']; ?></b></p>
                <p style="margin-bottom:6px;">Cadastros realizados: <b style="color: #22c55e; float:right;"><?php echo $affData['referrals']; ?></b></p>
                <p>Comissões ganhas (<?php echo $dbData['settings']['commission_pct']; ?>%): <b style="color: #facc15; float:right;">R$ <?php echo number_format(($affData['earnings_cents'] ?? 0)/100, 2, ',', '.'); ?></b></p>
            </div>
            <a href="index.php?page=game" class="btn btn-primary" style="display:block; text-align:center; text-decoration:none; margin-bottom:8px;">Ir para o Jogo</a>
            <a href="index.php?logout=1" class="logout-btn" style="display:block; text-align:center; padding: 10px;">Sair</a>
        </div>

    <?php elseif ($page === 'game' && isset($_SESSION['username'])): ?>
        <div class="arcade-cabinet">
            <div class="top-bar">
                <span>Técnico: <b><?php echo htmlspecialchars($_SESSION['username']); ?></b></span>
                <div>
                    <a href="index.php?page=affiliate" style="color:#38bdf8; text-decoration:none; margin-right:10px; font-weight:bold;">🤝 Afiliados</a>
                    <a href="index.php?logout=1" class="logout-btn">SAIR</a>
                </div>
            </div>

            <div class="wallet-actions">
                <button class="btn-wallet" onclick="openModal('deposit')">➕ Depositar Pix</button>
                <button class="btn-wallet" onclick="openModal('withdraw')">➖ Sacar</button>
            </div>

            <?php if (!empty($msg)): ?><div class="msg" style="margin-bottom:10px;"><?php echo $msg; ?></div><?php endif; ?>
            
            <?php if (!empty($pixQrCodeUrl) || !empty($pixCopyPaste)): ?>
                <div id="pix-container" style="background:#020617; padding:12px; border-radius:8px; margin-bottom:10px; text-align:center; border:2px solid #22c55e; max-height: 80vh; overflow-y: auto;">
                    <p style="font-size:12px; color:#22c55e; font-weight:bold; margin-bottom:8px;">Pague via Pix para creditar:</p>
                    <?php if(!empty($pixQrCodeUrl)): ?>
                        <img src="<?php echo htmlspecialchars($pixQrCodeUrl); ?>" alt="QR Code Pix" style="max-width:130px; border-radius:6px; margin-bottom:8px; border:2px solid #fff;">
                    <?php endif; ?>
                    <?php if(!empty($pixCopyPaste)): ?>
                        <p style="font-size:10px; color:#94a3b8; margin-bottom:4px;">Pix Copia e Cola:</p>
                        <input type="text" id="pix-copy-input" readonly value="<?php echo htmlspecialchars($pixCopyPaste); ?>" class="input-field" style="font-size:10px; text-align:center; margin-bottom:8px;">
                        <button type="button" class="btn btn-secondary" style="padding:10px; font-size:12px; margin-top:2px; margin-bottom:8px; background:#1e293b; border-color:#3b82f6; color:#38bdf8;" onclick="copyPixCode()">📋 Copiar Código Pix</button>
                    <?php endif; ?>
                    <div style="margin-top: 6px;">
                        <button type="button" class="btn btn-primary" style="padding:10px; font-size:12px; background:#2563eb; margin-top:0;" onclick="closePixContainer()">✅ Já paguei (Voltar ao jogo)</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="header-scores">
                <div class="score-box">
                    <div class="score-label">PRÊMIO DA RODADA</div>
                    <div class="score-value" id="win-display">R$ 0,00</div>
                </div>
                <div class="score-box">
                    <div class="score-label">SALDO EM CONTA</div>
                    <div class="score-value" id="balance-display">R$ <?php echo number_format(($dbData['users'][$_SESSION['username']]['balance_cents'] ?? 50000)/100, 2, ',', '.'); ?></div>
                </div>
            </div>

            <div class="board-container" id="board">
                <div class="center-screen">
                    <h1>FUTEBOL 98</h1>
                    <p id="msg-status">Cada aposta custa R$ 0,25. Escolha seus palpites!</p>
                </div>
            </div>

            <div class="betting-panel">
                <div class="betting-grid" id="bet-grid"></div>
                <div class="controls">
                    <button class="btn-action btn-clear" onclick="clearBets()">Limpar</button>
                    <button class="btn-action btn-spin" id="spin-btn" onclick="startSpin()">GIRAR</button>
                </div>
            </div>
        </div>

        <div id="wallet-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; z-index:1000;">
            <div class="card-box" style="max-width:350px;">
                <h2 id="modal-title" style="color:#facc15; font-size:18px; text-align:center; margin-bottom:14px;">Transação</h2>
                <form method="POST" id="wallet-form">
                    <input type="hidden" name="action" id="t_action_input" value="request_deposit">
                    <div class="input-group">
                        <label id="modal-label">Valor (R$):</label>
                        <input type="number" step="1" name="t_amount" value="20" min="5" class="input-field" required>
                    </div>
                    <button type="submit" class="btn btn-primary" id="modal-btn">Confirmar</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()" style="margin-top:6px;">Cancelar</button>
                </form>
            </div>
        </div>

        <script>
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            function playSound(type) {
                if (!audioCtx) return;
                if (audioCtx.state === 'suspended') { audioCtx.resume(); }

                if (type === 'click') {
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.frequency.setValueAtTime(400, audioCtx.currentTime);
                    gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.05);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.05);
                } else if (type === 'tick') {
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.type = 'square';
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.frequency.setValueAtTime(750, audioCtx.currentTime);
                    gain.gain.setValueAtTime(0.04, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.025);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.025);
                } else if (type === 'win') {
                    // Jingle de vitória alegre e polifônico
                    const chord = [523.25, 659.25, 783.99, 1046.50];
                    chord.forEach((freq, index) => {
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.type = 'triangle';
                        osc.connect(gain);
                        gain.connect(audioCtx.destination);
                        const startTime = audioCtx.currentTime + (index * 0.1);
                        osc.frequency.setValueAtTime(freq, startTime);
                        gain.gain.setValueAtTime(0.2, startTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.5);
                        osc.start(startTime);
                        osc.stop(startTime + 0.5);
                    });
                } else if (type === 'lose') {
                    // Música/som de derrota (descendente e melancólico)
                    const notes = [330, 311, 293, 261.63];
                    notes.forEach((freq, index) => {
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.type = 'sawtooth';
                        osc.connect(gain);
                        gain.connect(audioCtx.destination);
                        const startTime = audioCtx.currentTime + (index * 0.15);
                        osc.frequency.setValueAtTime(freq, startTime);
                        gain.gain.setValueAtTime(0.15, startTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.35);
                        osc.start(startTime);
                        osc.stop(startTime + 0.35);
                    });
                } else if (type === 'coin_tick') {
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.type = 'sine';
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.frequency.setValueAtTime(1200 + Math.random() * 500, audioCtx.currentTime);
                    gain.gain.setValueAtTime(0.06, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.03);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.03);
                }
            }

            function formatBRL(cents) {
                return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            }

            function openModal(type) {
                playSound('click');
                document.getElementById('wallet-modal').style.display = 'flex';
                if(type === 'deposit') {
                    document.getElementById('modal-title').innerText = 'Depositar via Pix (API)';
                    document.getElementById('modal-label').innerText = 'Valor do Depósito em Reais (R$):';
                    document.getElementById('t_action_input').value = 'request_deposit';
                    document.getElementById('modal-btn').innerText = 'Gerar Pix';
                } else {
                    document.getElementById('modal-title').innerText = 'Solicitar Saque';
                    document.getElementById('modal-label').innerText = 'Valor do Saque em Reais (R$):';
                    document.getElementById('t_action_input').value = 'request_withdraw';
                    document.getElementById('modal-btn').innerText = 'Solicitar Saque';
                }
            }
            function closeModal() {
                playSound('click');
                document.getElementById('wallet-modal').style.display = 'none';
            }

            function showToast(message) {
                const toast = document.getElementById('toast-notification');
                toast.innerText = message;
                toast.className = 'show';
                setTimeout(() => {
                    toast.className = toast.className.replace('show', '');
                }, 3000);
            }

            function copyPixCode() {
                playSound('click');
                const input = document.getElementById('pix-copy-input');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        showToast('📋 Código Pix Copia e Cola copiado!');
                    }).catch(() => {
                        fallbackCopy(input);
                    });
                } else {
                    fallbackCopy(input);
                }
            }

            function fallbackCopy(input) {
                input.select();
                document.execCommand('copy');
                showToast('📋 Código Pix Copia e Cola copiado!');
            }

            function closePixContainer() {
                playSound('click');
                const container = document.getElementById('pix-container');
                if (container) {
                    container.style.display = 'none';
                }
                document.getElementById('msg-status').innerText = "Aguardando confirmação automática da API...";
            }

            let currentWinCents = 0;
            let bets = <?php echo json_encode($dbData['users'][$_SESSION['username']]['active_bets'] ?? []); ?>;
            let isSpinning = false;
            let currentIndex = 0;
            let balanceCents = parseInt(<?php echo $dbData['users'][$_SESSION['username']]['balance_cents'] ?? 50000; ?>);

            const symbols = [
                { id: 'br', name: 'Brasil', emoji: '🇧🇷' },
                { id: 'ar', name: 'Argentina', emoji: '🇦🇷' },
                { id: 'copa', name: 'Taça', emoji: '🏆' }
            ];

            const boardTiles = [
                {r:0, c:0, symbol: {id:'br', name:'Brasil', mult:0.3, emoji:'🇧🇷'}},
                {r:0, c:1, symbol: {id:'ar', name:'Argentina', mult:0.5, emoji:'🇦🇷'}},
                {r:0, c:2, symbol: {id:'copa', name:'Taça', mult:5.0, emoji:'🏆'}},
                {r:0, c:3, symbol: {id:'br', name:'Brasil', mult:0.8, emoji:'🇧🇷'}},
                {r:0, c:4, symbol: {id:'ar', name:'Argentina', mult:0.3, emoji:'🇦🇷'}},
                {r:0, c:5, symbol: {id:'br', name:'Brasil', mult:1.5, emoji:'🇧🇷'}},
                {r:0, c:6, symbol: {id:'ar', name:'Argentina', mult:1.2, emoji:'🇦🇷'}},
                {r:1, c:6, symbol: {id:'br', name:'Brasil', mult:0.5, emoji:'🇧🇷'}},
                {r:2, c:6, symbol: {id:'copa', name:'Taça', mult:10.0, emoji:'🏆'}},
                {r:3, c:6, symbol: {id:'ar', name:'Argentina', mult:0.8, emoji:'🇦🇷'}},
                {r:4, c:6, symbol: {id:'br', name:'Brasil', mult:2.0, emoji:'🇧🇷'}},
                {r:5, c:6, symbol: {id:'ar', name:'Argentina', mult:0.5, emoji:'🇦🇷'}},
                {r:6, c:6, symbol: {id:'br', name:'Brasil', mult:0.3, emoji:'🇧🇷'}},
                {r:6, c:5, symbol: {id:'ar', name:'Argentina', mult:1.5, emoji:'🇦🇷'}},
                {r:6, c:4, symbol: {id:'copa', name:'Taça', mult:5.0, emoji:'🏆'}},
                {r:6, c:3, symbol: {id:'br', name:'Brasil', mult:1.2, emoji:'🇧🇷'}},
                {r:6, c:2, symbol: {id:'ar', name:'Argentina', mult:0.3, emoji:'🇦🇷'}},
                {r:6, c:1, symbol: {id:'br', name:'Brasil', mult:0.5, emoji:'🇧🇷'}},
                {r:6, c:0, symbol: {id:'ar', name:'Argentina', mult:2.0, emoji:'🇦🇷'}},
                {r:5, c:0, symbol: {id:'br', name:'Brasil', mult:0.8, emoji:'🇧🇷'}},
                {r:4, c:0, symbol: {id:'copa', name:'Taça', mult:10.0, emoji:'🏆'}},
                {r:3, c:0, symbol: {id:'ar', name:'Argentina', mult:0.5, emoji:'🇦🇷'}},
                {r:2, c:0, symbol: {id:'br', name:'Brasil', mult:0.3, emoji:'🇧🇷'}},
                {r:1, c:0, symbol: {id:'ar', name:'Argentina', mult:1.2, emoji:'🇦🇷'}}
            ];

            const betGrid = document.getElementById('bet-grid');
            symbols.forEach(sym => {
                if(!bets[sym.id]) bets[sym.id] = 0;
                const btn = document.createElement('button');
                btn.className = 'bet-btn';
                btn.id = `bet-btn-${sym.id}`;
                btn.innerHTML = `<div>${sym.emoji} ${sym.name}</div><div id="bet-val-${sym.id}" style="color:#22c55e">R$ 0,00</div>`;
                btn.onclick = () => addBet(sym.id);
                betGrid.appendChild(btn);
            });

            const boardEl = document.getElementById('board');
            let cellElements = {};
            boardTiles.forEach(tile => {
                const div = document.createElement('div');
                div.className = 'cell';
                div.style.gridRow = tile.r + 1;
                div.style.gridColumn = tile.c + 1;
                div.innerHTML = `<span style="font-size:13px">${tile.symbol.emoji}</span><span style="font-size:8px; color:#facc15;">${tile.symbol.mult}x</span>`;
                cellElements[`${tile.r}-${tile.c}`] = div;
                boardEl.appendChild(div);
            });

            function updateDisplay(newBalCents) {
                if(newBalCents !== undefined) {
                    balanceCents = newBalCents;
                }
                document.getElementById('balance-display').innerText = formatBRL(balanceCents);
                document.getElementById('win-display').innerText = formatBRL(currentWinCents);
                symbols.forEach(sym => {
                    let units = bets[sym.id] || 0;
                    let valCents = units * 25;
                    document.getElementById(`bet-val-${sym.id}`).innerText = formatBRL(valCents);
                    const btnEl = document.getElementById(`bet-btn-${sym.id}`);
                    if(units > 0) btnEl.classList.add('selected');
                    else btnEl.classList.remove('selected');
                });
            }
            updateDisplay();

            function addBet(symId) {
                if(isSpinning) return;
                playSound('click');

                fetch('index.php?page=game&ajax_place_bet=1', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ symbol: symId })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.error) {
                        document.getElementById('msg-status').innerText = data.error;
                        return;
                    }
                    balanceCents = data.newBalanceCents;
                    bets = data.activeBets;
                    updateDisplay();
                    document.getElementById('msg-status').innerText = "Aposta de R$ 0,25 adicionada!";
                })
                .catch(err => {
                    document.getElementById('msg-status').innerText = "Erro ao registrar aposta.";
                });
            }

            function clearBets() {
                if(isSpinning) return;
                playSound('click');

                fetch('index.php?page=game&ajax_clear_bets=1', {
                    method: 'POST'
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        balanceCents = data.newBalanceCents;
                        bets = data.activeBets;
                        currentWinCents = 0;
                        updateDisplay();
                        document.getElementById('msg-status').innerText = "Apostas limpas e saldo reembolsado.";
                    }
                })
                .catch(err => {
                    document.getElementById('msg-status').innerText = "Erro ao limpar apostas.";
                });
            }

            function startSpin() {
                let totalUnits = 0;
                for(let id in bets) totalUnits += (bets[id] || 0);
                if(totalUnits === 0) {
                    document.getElementById('msg-status').innerText = "Faça ao menos uma aposta!";
                    return;
                }

                playSound('click');
                isSpinning = true;
                currentWinCents = 0;
                document.getElementById('spin-btn').disabled = true;
                document.getElementById('msg-status').innerText = "Girando a roleta...";

                fetch('index.php?page=game&ajax_spin=1', {
                    method: 'POST'
                })
                .then(res => res.json())
                .then(data => {
                    if(data.error) {
                        alert(data.error);
                        location.reload();
                        return;
                    }
                    executeAnimation(data.winIndex, data.winAmountCents, data.newBalanceCents, data.winningSymbol);
                })
                .catch(err => {
                    isSpinning = false;
                    document.getElementById('spin-btn').disabled = false;
                    document.getElementById('msg-status').innerText = "Erro de conexão.";
                });
            }

            function executeAnimation(targetIndex, winAmountCents, newBalanceCents, winningSymbol) {
                let totalSteps = boardTiles.length * 2 + ((targetIndex - currentIndex + boardTiles.length) % boardTiles.length);
                let currentStep = 0;
                let speed = 30;

                function step() {
                    let prevCoord = boardTiles[currentIndex];
                    let prevEl = cellElements[`${prevCoord.r}-${prevCoord.c}`];
                    if(prevEl) prevEl.classList.remove('active');

                    currentIndex = (currentIndex + 1) % boardTiles.length;
                    let currCoord = boardTiles[currentIndex];
                    let currEl = cellElements[`${currCoord.r}-${currCoord.c}`];
                    if(currEl) currEl.classList.add('active');

                    playSound('tick');
                    currentStep++;

                    if(currentStep < totalSteps) {
                        if(currentStep > totalSteps - 15) speed += 18;
                        setTimeout(step, speed);
                    } else {
                        for(let id in bets) bets[id] = 0;
                        isSpinning = false;
                        document.getElementById('spin-btn').disabled = false;

                        if(winAmountCents > 0) {
                            playSound('win');
                            document.getElementById('msg-status').innerText = `🎉 ${winningSymbol.emoji} Prêmio de ${formatBRL(winAmountCents)} (${winningSymbol.mult}x)!`;
                            
                            let displayedWinCents = winAmountCents;
                            let startBalCents = newBalanceCents - winAmountCents;
                            currentWinCents = displayedWinCents;
                            updateDisplay(startBalCents);

                            let countInterval = setInterval(() => {
                                if (displayedWinCents > 0) {
                                    let stepAmt = Math.max(1, Math.floor(displayedWinCents / 10));
                                    displayedWinCents -= stepAmt;
                                    if (displayedWinCents < 0) displayedWinCents = 0;
                                    
                                    startBalCents += stepAmt;
                                    currentWinCents = displayedWinCents;
                                    
                                    updateDisplay(startBalCents);
                                    playSound('coin_tick');
                                } else {
                                    clearInterval(countInterval);
                                    currentWinCents = 0;
                                    updateDisplay(newBalanceCents);
                                }
                            }, 45);

                        } else {
                            playSound('lose');
                            currentWinCents = 0;
                            updateDisplay(newBalanceCents);
                            document.getElementById('msg-status').innerText = `😢 Não premiado nesta rodada. Tente novamente!`;
                        }
                    }
                }
                step();
            }
        </script>
    <?php else: ?>
        <?php header("Location: index.php"); exit; ?>
    <?php endif; ?>

</body>
</html>