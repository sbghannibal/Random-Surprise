<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$token = (string)($_GET['token'] ?? '');
$message = null;
$error = null;
$findEventByToken = static function (PDO $pdo, string $eventToken): array|false {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE token = ? LIMIT 1');
    $stmt->execute([$eventToken]);
    return $stmt->fetch();
};

$event = null;
if ($token !== '') {
    $event = $findEventByToken($pdo, $token);
    if ($event) {
        session_regenerate_id(true);
        unset($_SESSION['participant_token']);
        $_SESSION['organizer_token'] = $token;
        header('Location: organizer.php');
        exit;
    }
}

if ($event === null && isset($_SESSION['organizer_token'])) {
    $event = $findEventByToken($pdo, (string)$_SESSION['organizer_token']);
}

if (!$event) {
    http_response_code(403);
    exit('Geen toegang. Open eerst de geldige organisator-link.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ongeldige aanvraag. Herlaad de pagina en probeer opnieuw.';
    } else {
        try {
            $reminderStmt = $pdo->prepare(
                'SELECT p.token, p.name, p.email
                 FROM participants p
                 LEFT JOIN gift_ideas g ON g.participant_id = p.id
                 WHERE p.event_id = ?
                 GROUP BY p.id, p.token, p.name, p.email
                 HAVING COUNT(g.id) = 0'
            );
            $reminderStmt->execute([(int)$event['id']]);
            $targets = $reminderStmt->fetchAll();

            $sent = 0;
            foreach ($targets as $target) {
                $link = absoluteUrl(sprintf('gift_list.php?token=%s', urlencode((string)$target['token'])));
                $mailText = "Hoi {$target['name']},\n\nJe hebt nog geen cadeau-ideeën toegevoegd.\nVoeg je lijstje toe via: {$link}";
                if (sendMailSafe((string)$target['email'], 'Herinnering: vul je cadeau-lijstje in', $mailText)) {
                    $sent++;
                }
            }

            $message = "Herinneringen verstuurd: {$sent}";
        } catch (Throwable $e) {
            $error = 'Herinneringen konden niet worden verzonden: controleer APP_BASE_URL.';
        }
    }
}

$participantStmt = $pdo->prepare(
    'SELECT p.id, p.name, p.email, COUNT(g.id) AS gift_count
     FROM participants p
     LEFT JOIN gift_ideas g ON g.participant_id = p.id
     WHERE p.event_id = ?
     GROUP BY p.id, p.name, p.email
     ORDER BY p.name'
);
$participantStmt->execute([(int)$event['id']]);
$participants = $participantStmt->fetchAll();
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organisator dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #F4F7FA; }
        .navbar, .btn-primary { background-color: #0066CC !important; border-color: #0066CC !important; }
        .btn-accent { background-color: #5C2D91; border-color: #5C2D91; color: #fff; }
        .card { background: #FFFFFF; border: none; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark mb-4">
    <div class="container"><span class="navbar-brand">Organisator dashboard</span></div>
</nav>
<div class="container pb-5">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-1"><?= h((string)$event['name']) ?></h1>
            <p class="text-muted mb-4">
                Type: <?= h((string)$event['event_type']) ?> | Datum: <?= h((string)$event['event_date']) ?>
            </p>

            <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
            <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

            <form method="post" class="mb-4">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <button name="send_reminder" value="1" class="btn btn-accent">Stuur herinnering naar deelnemers zonder ideeën</button>
            </form>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                    <tr>
                        <th>Deelnemer</th>
                        <th>E-mail</th>
                        <th>Status lijstje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($participants as $participant): ?>
                        <tr>
                            <td><?= h((string)$participant['name']) ?></td>
                            <td><?= h((string)$participant['email']) ?></td>
                            <td>
                                <?php if ((int)$participant['gift_count'] > 0): ?>
                                    <span class="badge text-bg-success">Ingevuld</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nog leeg</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
