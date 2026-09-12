<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$token = (string)($_GET['token'] ?? '');

$stmt = $pdo->prepare(
    'SELECT p.*, e.name AS event_name, e.event_type, e.event_date, e.draw_date, e.budget, e.id AS event_id
     FROM participants p
     JOIN events e ON e.id = p.event_id
     WHERE p.token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$me = $stmt->fetch();

if (!$me) {
    http_response_code(404);
    exit('Deelnemer niet gevonden.');
}

$eventId = (int)$me['event_id'];
$participantId = (int)$me['id'];
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$canSeeMatch = $me['event_type'] === 'secret_santa'
    && $me['draw_date'] !== null
    && $me['draw_date'] <= $today
    && $me['matched_participant_id'] !== null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $shouldRedirect = false;

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Ongeldige aanvraag. Herlaad de pagina en probeer opnieuw.';
    } else {
        if ($action === 'add_gift') {
            $description = trim((string)($_POST['description'] ?? ''));
            $shopUrl = trim((string)($_POST['shop_url'] ?? ''));

            if ($description === '') {
                $errors[] = 'Omschrijving is verplicht.';
            } else {
                if ($shopUrl !== '' && filter_var($shopUrl, FILTER_VALIDATE_URL) === false) {
                    $errors[] = 'Webshop link is ongeldig.';
                }
                if ($errors === []) {
                    $insert = $pdo->prepare('INSERT INTO gift_ideas (participant_id, description, shop_url) VALUES (?, ?, ?)');
                    $insert->execute([$participantId, $description, $shopUrl !== '' ? $shopUrl : null]);
                    $shouldRedirect = true;
                }
            }
        }

        if ($action === 'delete_gift') {
            $giftId = (int)($_POST['gift_id'] ?? 0);
            $delete = $pdo->prepare('DELETE FROM gift_ideas WHERE id = ? AND participant_id = ?');
            $delete->execute([$giftId, $participantId]);
            $shouldRedirect = true;
        }

        if ($action === 'toggle_reservation' && $me['event_type'] === 'birthday') {
            $giftId = (int)($_POST['gift_id'] ?? 0);
            $giftOwnerStmt = $pdo->prepare(
                'SELECT g.participant_id
                 FROM gift_ideas g
                 JOIN participants p ON p.id = g.participant_id
                 WHERE g.id = ? AND p.event_id = ?'
            );
            $giftOwnerStmt->execute([$giftId, $eventId]);
            $giftOwnerId = (int)($giftOwnerStmt->fetchColumn() ?: 0);
            if ($giftOwnerId > 0 && $giftOwnerId !== $participantId) {
                $toggle = $pdo->prepare(
                    'UPDATE gift_ideas
                     SET bought_by_participant_id = CASE WHEN bought_by_participant_id = ? THEN NULL ELSE ? END
                     WHERE id = ? AND participant_id = ? AND (bought_by_participant_id IS NULL OR bought_by_participant_id = ?)'
                );
                $toggle->execute([$participantId, $participantId, $giftId, $giftOwnerId, $participantId]);
                if ($toggle->rowCount() > 0) {
                    $shouldRedirect = true;
                } else {
                    $errors[] = 'Deze reservatie is net gewijzigd door iemand anders. Vernieuw en probeer opnieuw.';
                }
            } elseif ($giftOwnerId === $participantId) {
                $errors[] = 'Je kunt je eigen cadeau-idee niet reserveren.';
            } else {
                $errors[] = 'Dit cadeau kan niet worden gereserveerd.';
            }
        }

        if ($action === 'ask_question') {
            $giftId = (int)($_POST['gift_id'] ?? 0);
            $question = trim((string)($_POST['question'] ?? ''));
            if ($question !== '') {
                $allowed = false;
                $giftCheck = $pdo->prepare(
                    'SELECT g.participant_id
                     FROM gift_ideas g
                     JOIN participants p ON p.id = g.participant_id
                     WHERE g.id = ? AND p.event_id = ?'
                );
                $giftCheck->execute([$giftId, $eventId]);
                $gift = $giftCheck->fetch();
                if ($gift && (int)$gift['participant_id'] !== $participantId) {
                    if ($me['event_type'] === 'birthday') {
                        $allowed = true;
                    }
                    if ($me['event_type'] === 'secret_santa' && $canSeeMatch) {
                        $myTargetStmt = $pdo->prepare('SELECT matched_participant_id FROM participants WHERE id = ?');
                        $myTargetStmt->execute([$participantId]);
                        $targetId = (int)($myTargetStmt->fetchColumn() ?: 0);
                        $allowed = $targetId > 0 && $targetId === (int)$gift['participant_id'];
                    }
                }
                if ($allowed) {
                    $insertQuestion = $pdo->prepare(
                        'INSERT INTO anonymous_questions (gift_idea_id, asker_participant_id, question) VALUES (?, ?, ?)'
                    );
                    $insertQuestion->execute([$giftId, $participantId, $question]);
                    $shouldRedirect = true;
                }
            }
        }

        if ($action === 'answer_question') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            $answer = trim((string)($_POST['answer'] ?? ''));
            if ($answer !== '') {
                $answerStmt = $pdo->prepare(
                    'UPDATE anonymous_questions aq
                     JOIN gift_ideas g ON g.id = aq.gift_idea_id
                     SET aq.answer = ?
                     WHERE aq.id = ? AND g.participant_id = ? AND aq.answer IS NULL'
                );
                $answerStmt->execute([$answer, $questionId, $participantId]);
                if ($answerStmt->rowCount() > 0) {
                    $shouldRedirect = true;
                } else {
                    $errors[] = 'Dit antwoord kon niet worden opgeslagen.';
                }
            }
        }
    }

    if ($shouldRedirect) {
        header('Location: gift_list.php?token=' . urlencode($token));
        exit;
    }
}

$myGiftsStmt = $pdo->prepare('SELECT id, description, shop_url FROM gift_ideas WHERE participant_id = ? ORDER BY id DESC');
$myGiftsStmt->execute([$participantId]);
$myGifts = $myGiftsStmt->fetchAll();

$questionsStmt = $pdo->prepare(
    'SELECT aq.id, aq.question, aq.answer, g.description
     FROM anonymous_questions aq
     JOIN gift_ideas g ON g.id = aq.gift_idea_id
     WHERE g.participant_id = ?
     ORDER BY aq.created_at DESC'
);
$questionsStmt->execute([$participantId]);
$incomingQuestions = $questionsStmt->fetchAll();

$others = [];
$matchedPerson = null;

if ($me['event_type'] === 'birthday') {
    $othersStmt = $pdo->prepare(
        'SELECT p.id AS participant_id, p.name, g.id AS gift_id, g.description, g.shop_url, g.bought_by_participant_id
         FROM participants p
         JOIN gift_ideas g ON g.participant_id = p.id
         WHERE p.event_id = ? AND p.id <> ?
         ORDER BY p.name, g.id'
    );
    $othersStmt->execute([$eventId, $participantId]);
    $others = $othersStmt->fetchAll();
}

if ($me['event_type'] === 'secret_santa' && $canSeeMatch) {
    $matchStmt = $pdo->prepare(
        'SELECT target.id, target.name
         FROM participants me
         JOIN participants target ON target.id = me.matched_participant_id
         WHERE me.id = ?'
    );
    $matchStmt->execute([$participantId]);
    $matchedPerson = $matchStmt->fetch();

    if ($matchedPerson) {
        $othersStmt = $pdo->prepare(
            'SELECT g.id AS gift_id, g.description, g.shop_url
             FROM gift_ideas g
             WHERE g.participant_id = ?
             ORDER BY g.id'
        );
        $othersStmt->execute([(int)$matchedPerson['id']]);
        $others = $othersStmt->fetchAll();
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadeaulijst</title>
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
    <div class="container"><span class="navbar-brand">Cadeaulijst - <?= h((string)$me['event_name']) ?></span></div>
</nav>
<div class="container pb-5">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5">Mijn cadeau-ideeën</h2>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="add_gift">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <div class="col-12"><input class="form-control" name="description" placeholder="Omschrijving" required></div>
                        <div class="col-12"><input class="form-control" name="shop_url" placeholder="Webshop link (optioneel)" type="url"></div>
                        <div class="col-12"><button class="btn btn-primary">Idee toevoegen</button></div>
                    </form>

                    <?php if (!$myGifts): ?>
                        <p class="text-muted mb-0">Nog geen ideeën toegevoegd.</p>
                    <?php endif; ?>

                    <?php foreach ($myGifts as $gift): ?>
                        <div class="border rounded p-3 mb-2">
                            <strong><?= h((string)$gift['description']) ?></strong>
                            <?php if (!empty($gift['shop_url'])): ?>
                                <div><a href="<?= h((string)$gift['shop_url']) ?>" target="_blank" rel="noopener noreferrer">Webshop link</a></div>
                            <?php endif; ?>
                            <form method="post" class="mt-2">
                                <input type="hidden" name="action" value="delete_gift">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="gift_id" value="<?= (int)$gift['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">Verwijder</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-body p-4">
                    <h2 class="h5">Anonieme vragen over mijn lijst</h2>
                    <?php if (!$incomingQuestions): ?>
                        <p class="text-muted mb-0">Nog geen vragen ontvangen.</p>
                    <?php endif; ?>
                    <?php foreach ($incomingQuestions as $question): ?>
                        <div class="border rounded p-3 mb-2">
                            <div class="small text-muted">Over: <?= h((string)$question['description']) ?></div>
                            <div><strong>Vraag:</strong> <?= h((string)$question['question']) ?></div>
                            <?php if (!empty($question['answer'])): ?>
                                <div class="mt-2"><strong>Antwoord:</strong> <?= h((string)$question['answer']) ?></div>
                            <?php else: ?>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="answer_question">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                                    <input class="form-control mb-2" name="answer" placeholder="Typ je antwoord" required>
                                    <button class="btn btn-sm btn-accent">Antwoord opslaan</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php if ($me['event_type'] === 'birthday'): ?>
                        <h2 class="h5">Lijstjes van andere deelnemers</h2>
                        <?php if (!$others): ?><p class="text-muted mb-0">Nog geen items beschikbaar.</p><?php endif; ?>
                        <?php
                        $currentOwner = null;
                        foreach ($others as $row):
                            if ($row['gift_id'] === null):
                                continue;
                            endif;
                            if ($currentOwner !== $row['participant_id']):
                                $currentOwner = $row['participant_id'];
                                echo '<hr><h3 class="h6 mb-2">' . h((string)$row['name']) . '</h3>';
                            endif;
                        ?>
                            <div class="border rounded p-3 mb-2">
                                <div><?= h((string)$row['description']) ?></div>
                                <?php if (!empty($row['shop_url'])): ?>
                                    <div><a href="<?= h((string)$row['shop_url']) ?>" target="_blank" rel="noopener noreferrer">Webshop link</a></div>
                                <?php endif; ?>
                                <form method="post" class="mt-2 d-inline">
                                    <input type="hidden" name="action" value="toggle_reservation">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="gift_id" value="<?= (int)$row['gift_id'] ?>">
                                    <button class="btn btn-sm <?= ((int)$row['bought_by_participant_id'] === $participantId) ? 'btn-success' : 'btn-outline-success' ?>">
                                        <?= ((int)$row['bought_by_participant_id'] === $participantId) ? 'Reservatie annuleren' : 'Ik koop dit' ?>
                                    </button>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="action" value="ask_question">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="gift_id" value="<?= (int)$row['gift_id'] ?>">
                                    <input class="form-control form-control-sm mb-1" name="question" placeholder="Stel anoniem een vraag" required>
                                    <button class="btn btn-sm btn-accent">Vraag verzenden</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <h2 class="h5">Secret Santa</h2>
                        <?php if (!$canSeeMatch): ?>
                            <p class="mb-0 text-muted">De koppel-datum is nog niet bereikt. Je ziet voorlopig alleen je eigen lijst.</p>
                        <?php elseif (!$matchedPerson): ?>
                            <p class="mb-0 text-muted">Koppeling nog niet uitgevoerd.</p>
                        <?php else: ?>
                            <p class="mb-2">Jij trekt: <strong><?= h((string)$matchedPerson['name']) ?></strong></p>
                            <?php if ($me['budget'] !== null): ?><p class="mb-3">Budget: €<?= h((string)$me['budget']) ?></p><?php endif; ?>
                            <?php if (!$others): ?><p class="text-muted">Nog geen ideeën.</p><?php endif; ?>
                            <?php foreach ($others as $row): ?>
                                <div class="border rounded p-3 mb-2">
                                    <div><?= h((string)$row['description']) ?></div>
                                    <?php if (!empty($row['shop_url'])): ?>
                                        <div><a href="<?= h((string)$row['shop_url']) ?>" target="_blank" rel="noopener noreferrer">Webshop link</a></div>
                                    <?php endif; ?>
                                    <form method="post" class="mt-2">
                                        <input type="hidden" name="action" value="ask_question">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="gift_id" value="<?= (int)$row['gift_id'] ?>">
                                        <input class="form-control form-control-sm mb-1" name="question" placeholder="Stel anoniem een vraag" required>
                                        <button class="btn btn-sm btn-accent">Vraag verzenden</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
