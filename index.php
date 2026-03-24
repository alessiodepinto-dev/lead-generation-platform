<?php
declare(strict_types=1);

session_start();

$config = [
    'db_host' => '127.0.0.1',
    'db_name' => 'leadflow',
    'db_user' => 'root',
    'db_pass' => '',
    'client_email' => 'alessiodepinto4@gmail.com',
    'from_email' => 'no-reply@example.com',
    'admin_user' => 'admin',
    'admin_password' => 'leadflow123',
    'webhook_url' => '',
    'telegram_bot_token' => '8528085296:AAGzDX0IlOjCdfubChiQxMKSklcKaZtQItA',
    'telegram_chat_id' => '889781821',
];

function ensure_db(array $config): PDO {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('nuovo','letto') NOT NULL DEFAULT 'nuovo',
            created_at DATETIME NOT NULL
        )"
    );
    return $pdo;
}

function base_path(): string {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $phpSelf = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
    $requestLower = strtolower($requestPath);

    $candidates = [];
    if ($script !== '') {
        $candidates[] = rtrim(dirname($script), '/');
    }
    if ($phpSelf !== '' && $phpSelf !== $script) {
        $candidates[] = rtrim(dirname($phpSelf), '/');
    }

    foreach ($candidates as $candidate) {
        if ($candidate === '' || $candidate === '/') {
            continue;
        }
        $candidateLower = strtolower($candidate);
        if ($requestLower === $candidateLower || strpos($requestLower, $candidateLower . '/') === 0) {
            return $candidate;
        }
    }

    return '';
}

function relative_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $base = base_path();
    if ($base !== '') {
        $pathLower = strtolower($path);
        $baseLower = strtolower($base);
        if (strpos($pathLower, $baseLower) === 0) {
            $path = substr($path, strlen($base));
        }
    }
    return trim($path, '/');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function is_logged_in(array $config): bool {
    return isset($_SESSION['admin_user']) && $_SESSION['admin_user'] === $config['admin_user'];
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_mail(string $to, string $subject, string $body, string $from): bool {
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=utf-8',
    ];
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function post_webhook(string $url, array $payload): void {
    if ($url === '') {
        return;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function send_telegram(string $token, string $chatId, string $message): void {
    if ($token === '' || $chatId === '') {
        return;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$pdo = ensure_db($config);
$route = relative_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';



if ($route === 'api/leads' && $method === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $honeypot = trim($_POST['company'] ?? '');

    if ($honeypot !== '') {
        json_response(['ok' => true]);
    }
    if ($name === '' || $email === '' || $message === '') {
        json_response(['ok' => false, 'error' => 'Compila tutti i campi.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Email non valida.'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO leads (name, email, message, status, created_at) VALUES (:name, :email, :message, :status, :created_at)');
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':message' => $message,
        ':status' => 'nuovo',
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    $leadId = (int)$pdo->lastInsertId();
    $clientBody = "Nuovo contatto ricevuto:\n\nNome: {$name}\nEmail: {$email}\nMessaggio:\n{$message}\n\nID Lead: {$leadId}";
    $userBody = "Ciao {$name},\n\nGrazie per averci contattato. Abbiamo ricevuto il tuo messaggio e ti risponderemo presto.\n\n- LeadFlow";

    send_mail($config['client_email'], 'Nuovo lead dal sito', $clientBody, $config['from_email']);
    send_mail($email, 'Abbiamo ricevuto la tua richiesta', $userBody, $config['from_email']);

    post_webhook($config['webhook_url'], [
        'id' => $leadId,
        'name' => $name,
        'email' => $email,
        'message' => $message,
        'created_at' => date('c'),
        'status' => 'nuovo',
    ]);

    $telegramMessage = "Nuovo lead #" . $leadId . "\n";
    $telegramMessage .= "Nome: " . $name . "\n";
    $telegramMessage .= "Email: " . $email . "\n";
    $telegramMessage .= "Messaggio: " . $message;
    send_telegram($config['telegram_bot_token'], $config['telegram_chat_id'], $telegramMessage);

    json_response(['ok' => true]);
}

if ($route === 'admin/login') {
    if ($method === 'POST') {
        if (!verify_csrf()) {
            $error = 'Sessione non valida, riprova.';
        } else {
            $user = trim($_POST['username'] ?? '');
            $pass = trim($_POST['password'] ?? '');
            if ($user === $config['admin_user'] && $pass === $config['admin_password']) {
                $_SESSION['admin_user'] = $config['admin_user'];
                header('Location: ' . base_path() . '/admin');
                exit;
            }
            $error = 'Credenziali errate.';
        }
    }
    $token = csrf_token();
    ?>
    <!doctype html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>LeadFlow Admin Login</title>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg: #0b1220;
                --card: #121a2b;
                --accent: #ff7a45;
                --accent-2: #3dd5c6;
                --text: #f4f7fb;
                --muted: #9bb0c9;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Space Grotesk", sans-serif;
                background: radial-gradient(circle at top, rgba(61, 213, 198, 0.2), transparent 50%), var(--bg);
                color: var(--text);
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 24px;
            }
            .card {
                background: var(--card);
                padding: 32px;
                border-radius: 20px;
                width: min(420px, 100%);
                box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
                border: 1px solid rgba(255, 255, 255, 0.08);
            }
            h1 { margin: 0 0 12px; font-size: 28px; }
            p { margin: 0 0 24px; color: var(--muted); }
            label { display: block; margin-bottom: 6px; font-weight: 600; }
            input {
                width: 100%;
                padding: 12px 14px;
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,0.15);
                background: #0b1324;
                color: var(--text);
                margin-bottom: 16px;
            }
            button {
                width: 100%;
                padding: 12px 14px;
                border: none;
                border-radius: 12px;
                background: linear-gradient(135deg, var(--accent), #ffb347);
                color: #1b0b00;
                font-weight: 700;
                cursor: pointer;
                transition: transform 0.2s ease;
            }
            button:hover { transform: translateY(-2px); }
            .error { color: #ff9494; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Accesso Admin</h1>
            <p>Gestisci i lead con una login semplice e veloce.</p>
            <?php if (!empty($error)) : ?>
                <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post" action="<?php echo base_path(); ?>/admin/login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="username">Username</label>
                <input id="username" name="username" required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
                <button type="submit">Entra</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if ($route === 'admin/logout') {
    session_destroy();
    header('Location: ' . base_path() . '/admin/login');
    exit;
}

if (strpos($route, 'admin') === 0) {
    if (!is_logged_in($config)) {
        header('Location: ' . base_path() . '/admin/login');
        exit;
    }

    if ($method === 'POST') {
        if (!verify_csrf()) {
            header('Location: ' . base_path() . '/admin?error=csrf');
            exit;
        }
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'mark' && $id > 0) {
            $stmt = $pdo->prepare('UPDATE leads SET status = :status WHERE id = :id');
            $stmt->execute([':status' => 'letto', ':id' => $id]);
        }
        if ($action === 'delete' && $id > 0) {
            $stmt = $pdo->prepare('DELETE FROM leads WHERE id = :id');
            $stmt->execute([':id' => $id]);
        }
        header('Location: ' . base_path() . '/admin');
        exit;
    }

    $token = csrf_token();
    $leads = $pdo->query('SELECT * FROM leads ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $total = count($leads);
    $newCount = count(array_filter($leads, fn($lead) => $lead['status'] === 'nuovo'));
    ?>
    <!doctype html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>LeadFlow Dashboard</title>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg: #f6f3ef;
                --panel: #ffffff;
                --accent: #ff7a45;
                --accent-2: #2c7cff;
                --text: #1b1a1f;
                --muted: #6b7280;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Space Grotesk", sans-serif;
                color: var(--text);
                background: linear-gradient(120deg, #fff8f2, #eef4ff);
                min-height: 100vh;
                padding: 32px 20px;
            }
            .header {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 24px;
            }
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            .stat {
                background: var(--panel);
                padding: 16px;
                border-radius: 16px;
                box-shadow: 0 12px 30px rgba(22, 27, 45, 0.08);
            }
            table {
                width: 100%;
                border-collapse: collapse;
                background: var(--panel);
                border-radius: 18px;
                overflow: hidden;
                box-shadow: 0 20px 50px rgba(20, 24, 36, 0.08);
            }
            th, td {
                text-align: left;
                padding: 14px 16px;
                border-bottom: 1px solid #f0f1f5;
                vertical-align: top;
            }
            th { background: #f9fafb; }
            .badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 12px;
                background: #ffe7db;
                color: #9a3f1c;
            }
            .badge.read { background: #e3f2ff; color: #1d4ed8; }
            .actions form { display: inline-block; margin-right: 6px; }
            .btn {
                background: #1f2937;
                color: #fff;
                border: none;
                padding: 8px 12px;
                border-radius: 10px;
                cursor: pointer;
                font-size: 12px;
            }
            .btn.mark { background: var(--accent-2); }
            .btn.delete { background: #e11d48; }
            .top-link {
                background: #111827;
                color: #fff;
                padding: 8px 14px;
                border-radius: 999px;
                text-decoration: none;
                font-weight: 600;
            }
            @media (max-width: 860px) {
                table, thead, tbody, th, td, tr { display: block; }
                thead { display: none; }
                tr { margin-bottom: 16px; }
                td { border-bottom: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div>
                <h1>LeadFlow Dashboard</h1>
                <p>Contatti ricevuti dal sito.</p>
            </div>
            <a class="top-link" href="<?php echo base_path(); ?>/admin/logout">Logout</a>
        </div>
        <div class="stats">
            <div class="stat">
                <strong><?php echo $total; ?></strong>
                <div>Lead totali</div>
            </div>
            <div class="stat">
                <strong><?php echo $newCount; ?></strong>
                <div>Nuovi</div>
            </div>
        </div>
        <div style="margin-top: 24px;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Messaggio</th>
                        <th>Stato</th>
                        <th>Data</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($leads)) : ?>
                    <tr><td colspan="7">Nessun lead ancora.</td></tr>
                <?php else : ?>
                    <?php foreach ($leads as $lead) : ?>
                        <tr>
                            <td>#<?php echo (int)$lead['id']; ?></td>
                            <td><?php echo htmlspecialchars($lead['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($lead['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($lead['message'], ENT_QUOTES, 'UTF-8')); ?></td>
                            <td>
                                <span class="badge <?php echo $lead['status'] === 'letto' ? 'read' : ''; ?>">
                                    <?php echo htmlspecialchars($lead['status'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($lead['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="actions">
                                <?php if ($lead['status'] !== 'letto') : ?>
                                    <form method="post" action="<?php echo base_path(); ?>/admin">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="mark">
                                        <input type="hidden" name="id" value="<?php echo (int)$lead['id']; ?>">
                                        <button class="btn mark" type="submit">Segna letto</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo base_path(); ?>/admin">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$lead['id']; ?>">
                                    <button class="btn delete" type="submit">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$base = base_path();
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LeadFlow - Genera Contatti Qualificati</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f2ee;
            --panel: #ffffff;
            --accent: #ff7a45;
            --accent-2: #1f5eff;
            --text: #1b1a1f;
            --muted: #6b7280;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            background: radial-gradient(circle at 20% 20%, rgba(255, 122, 69, 0.18), transparent 40%),
                        radial-gradient(circle at 80% 0%, rgba(31, 94, 255, 0.16), transparent 45%),
                        var(--bg);
            color: var(--text);
        }
        header {
            padding: 32px 20px 12px;
        }
        .container {
            width: min(1100px, 90%);
            margin: 0 auto;
        }
        .hero {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 32px;
            align-items: center;
            padding: 40px 0 60px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff3eb;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            color: #a34a23;
        }
        h1 {
            font-size: clamp(32px, 4vw, 54px);
            margin: 16px 0;
            line-height: 1.05;
        }
        .lead {
            font-size: 18px;
            color: var(--muted);
        }
        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }
        .cta {
            padding: 14px 22px;
            border-radius: 14px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .cta.primary {
            background: linear-gradient(135deg, var(--accent), #ffb347);
            color: #1b0b00;
        }
        .cta.secondary {
            background: var(--panel);
            color: var(--text);
            border: 1px solid rgba(0,0,0,0.08);
        }
        .hero-card {
            background: var(--panel);
            padding: 28px;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(25, 28, 38, 0.12);
            position: relative;
            overflow: hidden;
        }
        .hero-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(61, 213, 198, 0.18), transparent 55%);
            pointer-events: none;
        }
        .hero-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .hero-card li {
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f6;
        }
        .section {
            padding: 40px 0;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }
        .feature {
            background: var(--panel);
            padding: 20px;
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(18, 20, 29, 0.08);
        }
        .form-card {
            background: var(--panel);
            padding: 28px;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(25, 28, 38, 0.12);
        }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input, textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.12);
            margin-bottom: 16px;
            font-family: inherit;
        }
        textarea { min-height: 120px; resize: vertical; }
        .hidden { display: none; }
        .feedback {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-weight: 600;
        }
        .feedback.success { background: #ecfdf3; color: #047857; }
        .feedback.error { background: #fff1f2; color: #be123c; }
        .footer {
            padding: 24px 0 40px;
            color: var(--muted);
        }
        .fade-in {
            animation: fadeInUp 0.8s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        .fade-delay-1 { animation-delay: 0.1s; }
        .fade-delay-2 { animation-delay: 0.2s; }
        .fade-delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp {
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <header class="container">
        <div class="badge">LeadFlow · sistema contatti</div>
    </header>

    <main class="container">
        <section class="hero">
            <div>
                <div class="badge">Cattura lead veri</div>
                <h1 class="fade-in fade-delay-1">Trasforma il tuo sito in una macchina di contatti qualificati.</h1>
                <p class="lead fade-in fade-delay-2">
                    Landing page moderna + dashboard pronta per gestire i lead. Ricevi notifiche, aggiorna lo stato e mantieni tutto organizzato.
                </p>
                <div class="cta-row fade-in fade-delay-3">
                    <a class="cta primary" href="#contatti">Inizia ora</a>
                    <a class="cta secondary" href="<?php echo $base; ?>/admin">Apri dashboard</a>
                </div>
            </div>
            <div class="hero-card fade-in fade-delay-2">
                <h3>Stack MVP incluso</h3>
                <ul>
                    <li>Form contatti con feedback immediato</li>
                    <li>Salvataggio su database e stato lead</li>
                    <li>Email automatica cliente + utente</li>
                    <li>Webhook opzionale per automazioni</li>
                </ul>
            </div>
        </section>

        <section class="section">
            <div class="grid">
                <div class="feature">
                    <h3>Hero che converte</h3>
                    <p>Layout magnetico, CTA chiara, focus sul valore.</p>
                </div>
                <div class="feature">
                    <h3>Lead sempre ordinati</h3>
                    <p>Dashboard con stato, data e azioni rapide.</p>
                </div>
                <div class="feature">
                    <h3>Notifiche automatiche</h3>
                    <p>Email immediate e webhook per integrazioni.</p>
                </div>
                <div class="feature">
                    <h3>Mobile first</h3>
                    <p>Esperienza fluida anche su smartphone.</p>
                </div>
            </div>
        </section>

        <section id="contatti" class="section">
            <div class="form-card">
                <h2>Richiedi informazioni</h2>
                <p>Ti rispondiamo entro 24 ore con una proposta su misura.</p>
                <form id="lead-form">
                    <label for="name">Nome</label>
                    <input id="name" name="name" required>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required>
                    <label for="message">Messaggio</label>
                    <textarea id="message" name="message" required></textarea>
                    <input class="hidden" name="company" tabindex="-1" autocomplete="off">
                    <button class="cta primary" type="submit">Invia richiesta</button>
                    <div id="form-feedback" class="feedback hidden"></div>
                </form>
            </div>
        </section>
    </main>

    <footer class="container footer">
        LeadFlow MVP · Dashboard e notifiche incluse.
    </footer>

    <script>
        const form = document.getElementById('lead-form');
        const feedback = document.getElementById('form-feedback');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            feedback.className = 'feedback hidden';
            const formData = new FormData(form);

            try {
                const response = await fetch('<?php echo $base; ?>/api/leads', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.ok) {
                    feedback.textContent = 'Messaggio inviato! Ti contatteremo presto.';
                    feedback.className = 'feedback success';
                    form.reset();
                } else {
                    feedback.textContent = data.error || 'Errore durante l\'invio.';
                    feedback.className = 'feedback error';
                }
            } catch (error) {
                feedback.textContent = 'Impossibile inviare. Riprova tra poco.';
                feedback.className = 'feedback error';
            }
        });
    </script>
</body>
</html>
