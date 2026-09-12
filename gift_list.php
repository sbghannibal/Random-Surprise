<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$token = (string)($_GET['token'] ?? '');
if ($token !== '') {
    $tokenCheckStmt = $pdo->prepare('SELECT 1 FROM participants WHERE token = ? LIMIT 1');
    $tokenCheckStmt->execute([$token]);
    if ($tokenCheckStmt->fetchColumn()) {
        session_regenerate_id(true);
        unset($_SESSION['organizer_token']);
        $_SESSION['participant_token'] = $token;
        header('Location: gift_list.php');
        exit;
    }
    http_response_code(404);
    exit('Deelnemer niet gevonden.');
}

$sessionToken = (string)($_SESSION['participant_token'] ?? '');
if ($sessionToken === '') {
    http_response_code(403);
    exit('Geen toegang. Open eerst je geldige deelnemer-link.');
}

$stmt = $pdo->prepare(
    'SELECT p.*, e.name AS event_name, e.event_type, e.event_date, e.draw_date, e.budget, e.id AS event_id
     FROM participants p
     JOIN events e ON e.id = p.event_id
     WHERE p.token = ?
     LIMIT 1'
);
$stmt->execute([$sessionToken]);
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
$fieldErrors = [];
$activeAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
$activeGiftId = (int)($_POST['gift_id'] ?? 0);
$activeQuestionId = (int)($_POST['question_id'] ?? 0);
$oldInput = [
    'description' => trim((string)($_POST['description'] ?? '')),
    'shop_url' => trim((string)($_POST['shop_url'] ?? '')),
    'question' => trim((string)($_POST['question'] ?? '')),
    'answer' => trim((string)($_POST['answer'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shouldRedirect = false;

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        addFormError($errors, $fieldErrors, null, 'Ongeldige aanvraag. Herlaad de pagina en probeer opnieuw.');
    } else {
        switch ($activeAction) {
            case 'add_gift':
                $description = $oldInput['description'];
                $shopUrl = $oldInput['shop_url'];

                if ($description === '') {
                    addFormError($errors, $fieldErrors, 'description', 'Omschrijving is verplicht.');
                }
                if ($shopUrl !== '' && filter_var($shopUrl, FILTER_VALIDATE_URL) === false) {
                    addFormError($errors, $fieldErrors, 'shop_url', 'Vul een geldige webshop link in.');
                } elseif ($shopUrl !== '' && !isValidHttpsUrl($shopUrl)) {
                    addFormError($errors, $fieldErrors, 'shop_url', 'Gebruik een webshop link die met https:// begint.');
                }

                if ($errors === []) {
                    try {
                        $insert = $pdo->prepare('INSERT INTO gift_ideas (participant_id, description, shop_url) VALUES (?, ?, ?)');
                        $insert->execute([$participantId, $description, $shopUrl !== '' ? $shopUrl : null]);
                        $shouldRedirect = true;
                    } catch (PDOException $e) {
                        logApplicationError('Kon cadeau-idee niet opslaan', $e);
                        addFormError($errors, $fieldErrors, null, 'Je cadeau-idee kon niet worden opgeslagen. Probeer het opnieuw.');
                    } catch (Throwable $e) {
                        logApplicationError('Onverwachte fout bij opslaan cadeau-idee', $e);
                        addFormError($errors, $fieldErrors, null, 'Je cadeau-idee kon niet worden opgeslagen. Probeer het opnieuw.');
                    }
                }
                break;

            case 'delete_gift':
                if ($activeGiftId <= 0) {
                    addFormError($errors, $fieldErrors, null, 'Kies een geldig cadeau-idee om te verwijderen.');
                } else {
                    try {
                        $delete = $pdo->prepare('DELETE FROM gift_ideas WHERE id = ? AND participant_id = ?');
                        $delete->execute([$activeGiftId, $participantId]);
                        if ($delete->rowCount() > 0) {
                            $shouldRedirect = true;
                        } else {
                            addFormError($errors, $fieldErrors, null, 'Dit cadeau-idee kon niet worden verwijderd.');
                        }
                    } catch (PDOException $e) {
                        logApplicationError('Kon cadeau-idee niet verwijderen', $e);
                        addFormError($errors, $fieldErrors, null, 'Dit cadeau-idee kon niet worden verwijderd. Probeer het opnieuw.');
                    } catch (Throwable $e) {
                        logApplicationError('Onverwachte fout bij verwijderen cadeau-idee', $e);
                        addFormError($errors, $fieldErrors, null, 'Dit cadeau-idee kon niet worden verwijderd. Probeer het opnieuw.');
                    }
                }
                break;

            case 'toggle_reservation':
                if ($me['event_type'] !== 'birthday') {
                    addFormError($errors, $fieldErrors, null, 'Reservaties zijn alleen beschikbaar voor verjaardagsevenementen.');
                } elseif ($activeGiftId <= 0) {
                    addFormError($errors, $fieldErrors, null, 'Kies een geldig cadeau om te reserveren.');
                } else {
                    try {
                        $giftOwnerStmt = $pdo->prepare(
                            'SELECT g.participant_id
                             FROM gift_ideas g
                             JOIN participants p ON p.id = g.participant_id
                             WHERE g.id = ? AND p.event_id = ?'
                        );
                        $giftOwnerStmt->execute([$activeGiftId, $eventId]);
                        $giftOwnerId = (int)($giftOwnerStmt->fetchColumn() ?: 0);
                        if ($giftOwnerId > 0 && $giftOwnerId !== $participantId) {
                            $toggle = $pdo->prepare(
                                'UPDATE gift_ideas
                                 SET bought_by_participant_id = CASE WHEN bought_by_participant_id = ? THEN NULL ELSE ? END
                                 WHERE id = ? AND participant_id = ? AND (bought_by_participant_id IS NULL OR bought_by_participant_id = ?)'
                            );
                            $toggle->execute([$participantId, $participantId, $activeGiftId, $giftOwnerId, $participantId]);
                            if ($toggle->rowCount() > 0) {
                                $shouldRedirect = true;
                            } else {
                                addFormError($errors, $fieldErrors, null, 'Deze reservatie is net gewijzigd door iemand anders. Vernieuw de pagina en probeer opnieuw.');
                            }
                        } elseif ($giftOwnerId === $participantId) {
                            addFormError($errors, $fieldErrors, null, 'Je kunt je eigen cadeau-idee niet reserveren.');
                        } else {
                            addFormError($errors, $fieldErrors, null, 'Dit cadeau kan niet worden gereserveerd.');
                        }
                    } catch (PDOException $e) {
                        logApplicationError('Kon reservatie niet wijzigen', $e);
                        addFormError($errors, $fieldErrors, null, 'De reservatie kon niet worden bijgewerkt. Probeer het opnieuw.');
                    } catch (Throwable $e) {
                        logApplicationError('Onverwachte fout bij wijzigen reservatie', $e);
                        addFormError($errors, $fieldErrors, null, 'De reservatie kon niet worden bijgewerkt. Probeer het opnieuw.');
                    }
                }
                break;

            case 'ask_question':
                $questionField = 'question_' . $activeGiftId;
                $question = $oldInput['question'];

                if ($question === '') {
                    addFormError($errors, $fieldErrors, $questionField, 'Vul een vraag in.');
                } elseif ($activeGiftId <= 0) {
                    addFormError($errors, $fieldErrors, null, 'Kies een geldig cadeau om een vraag over te stellen.');
                } else {
                    try {
                        $allowed = false;
                        $giftCheck = $pdo->prepare(
                            'SELECT g.participant_id
                             FROM gift_ideas g
                             JOIN participants p ON p.id = g.participant_id
                             WHERE g.id = ? AND p.event_id = ?'
                        );
                        $giftCheck->execute([$activeGiftId, $eventId]);
                        $gift = $giftCheck->fetch();
                        if ($gift && (int)$gift['participant_id'] !== $participantId) {
                            if ($me['event_type'] === 'birthday') {
                                $allowed = true;
                            }
                            if ($me['event_type'] === 'secret_santa' && $canSeeMatch) {
                                $targetId = (int)($me['matched_participant_id'] ?? 0);
                                $allowed = $targetId > 0 && $targetId === (int)$gift['participant_id'];
                            }
                        }
                        if ($allowed) {
                            $insertQuestion = $pdo->prepare(
                                'INSERT INTO anonymous_questions (gift_idea_id, asker_participant_id, question) VALUES (?, ?, ?)'
                            );
                            $insertQuestion->execute([$activeGiftId, $participantId, $question]);
                            $shouldRedirect = true;
                        } else {
                            addFormError($errors, $fieldErrors, null, 'Je mag geen vraag stellen voor dit cadeau.');
                        }
                    } catch (PDOException $e) {
                        logApplicationError('Kon vraag niet opslaan', $e);
                        addFormError($errors, $fieldErrors, null, 'Je vraag kon niet worden verzonden. Probeer het opnieuw.');
                    } catch (Throwable $e) {
                        logApplicationError('Onverwachte fout bij opslaan vraag', $e);
                        addFormError($errors, $fieldErrors, null, 'Je vraag kon niet worden verzonden. Probeer het opnieuw.');
                    }
                }
                break;

            case 'answer_question':
                $answerField = 'answer_' . $activeQuestionId;
                $answer = $oldInput['answer'];

                if ($answer === '') {
                    addFormError($errors, $fieldErrors, $answerField, 'Vul een antwoord in.');
                } elseif ($activeQuestionId <= 0) {
                    addFormError($errors, $fieldErrors, null, 'Kies een geldige vraag om te beantwoorden.');
                } else {
                    try {
                        $answerStmt = $pdo->prepare(
                            'UPDATE anonymous_questions aq
                             JOIN gift_ideas g ON g.id = aq.gift_idea_id
                             SET aq.answer = ?
                             WHERE aq.id = ? AND g.participant_id = ? AND aq.answer IS NULL'
                        );
                        $answerStmt->execute([$answer, $activeQuestionId, $participantId]);
                        if ($answerStmt->rowCount() > 0) {
                            $shouldRedirect = true;
                        } else {
                            addFormError($errors, $fieldErrors, null, 'Deze vraag kan niet meer worden beantwoord.');
                        }
                    } catch (PDOException $e) {
                        logApplicationError('Kon antwoord niet opslaan', $e);
                        addFormError($errors, $fieldErrors, null, 'Je antwoord kon niet worden opgeslagen. Probeer het opnieuw.');
                    } catch (Throwable $e) {
                        logApplicationError('Onverwachte fout bij opslaan antwoord', $e);
                        addFormError($errors, $fieldErrors, null, 'Je antwoord kon niet worden opgeslagen. Probeer het opnieuw.');
                    }
                }
                break;
        }
    }

    if ($shouldRedirect) {
        header('Location: gift_list.php');
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
         LEFT JOIN gift_ideas g ON g.participant_id = p.id
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
    <?php renderErrorSummary($errors, 'Actie mislukt. Controleer de gemarkeerde velden.'); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5">Mijn cadeau-ideeën</h2>
                    <form method="post" class="row g-2 mb-3" novalidate>
                        <input type="hidden" name="action" value="add_gift">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <div class="col-12">
                            <input class="<?= fieldErrorClass($fieldErrors, 'description') ?>" name="description" placeholder="Omschrijving" required value="<?= $activeAction === 'add_gift' ? h($oldInput['description']) : '' ?>">
                            <?php if ($error = firstFieldError($fieldErrors, 'description')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <input class="<?= fieldErrorClass($fieldErrors, 'shop_url') ?>" name="shop_url" placeholder="Webshop link (optioneel)" type="url" value="<?= $activeAction === 'add_gift' ? h($oldInput['shop_url']) : '' ?>">
                            <?php if ($error = firstFieldError($fieldErrors, 'shop_url')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                        </div>
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
                            <form method="post" class="mt-2" novalidate>
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
                                <form method="post" class="mt-2" novalidate>
                                    <input type="hidden" name="action" value="answer_question">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                                    <input class="<?= fieldErrorClass($fieldErrors, 'answer_' . (int)$question['id']) ?> mb-2" name="answer" placeholder="Typ je antwoord" required value="<?= ($activeAction === 'answer_question' && $activeQuestionId === (int)$question['id']) ? h($oldInput['answer']) : '' ?>">
                                    <?php if ($error = firstFieldError($fieldErrors, 'answer_' . (int)$question['id'])): ?><div class="invalid-feedback d-block mb-2"><?= h($error) ?></div><?php endif; ?>
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
                            if ($currentOwner !== $row['participant_id']):
                                $currentOwner = $row['participant_id'];
                                echo '<hr><h3 class="h6 mb-2">' . h((string)$row['name']) . '</h3>';
                            endif;
                            if ($row['gift_id'] === null):
                                echo '<p class="text-muted small">Nog geen ideeën toegevoegd.</p>';
                                continue;
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
                                    <?php $reservedByOther = ((int)$row['bought_by_participant_id'] > 0 && (int)$row['bought_by_participant_id'] !== $participantId); ?>
                                    <button class="btn btn-sm <?= ((int)$row['bought_by_participant_id'] === $participantId) ? 'btn-success' : 'btn-outline-success' ?>" <?= $reservedByOther ? 'disabled' : '' ?>>
                                        <?= $reservedByOther ? 'Reeds gereserveerd' : ((((int)$row['bought_by_participant_id'] === $participantId) ? 'Reservatie annuleren' : 'Ik koop dit')) ?>
                                    </button>
                                </form>
                                <form method="post" class="mt-2" novalidate>
                                    <input type="hidden" name="action" value="ask_question">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="gift_id" value="<?= (int)$row['gift_id'] ?>">
                                    <input class="<?= fieldErrorClass($fieldErrors, 'question_' . (int)$row['gift_id'], 'form-control form-control-sm') ?> mb-1" name="question" placeholder="Stel anoniem een vraag" required value="<?= ($activeAction === 'ask_question' && $activeGiftId === (int)$row['gift_id']) ? h($oldInput['question']) : '' ?>">
                                    <?php if ($error = firstFieldError($fieldErrors, 'question_' . (int)$row['gift_id'])): ?><div class="invalid-feedback d-block mb-1"><?= h($error) ?></div><?php endif; ?>
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
                                    <form method="post" class="mt-2" novalidate>
                                        <input type="hidden" name="action" value="ask_question">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="gift_id" value="<?= (int)$row['gift_id'] ?>">
                                        <input class="<?= fieldErrorClass($fieldErrors, 'question_' . (int)$row['gift_id'], 'form-control form-control-sm') ?> mb-1" name="question" placeholder="Stel anoniem een vraag" required value="<?= ($activeAction === 'ask_question' && $activeGiftId === (int)$row['gift_id']) ? h($oldInput['question']) : '' ?>">
                                        <?php if ($error = firstFieldError($fieldErrors, 'question_' . (int)$row['gift_id'])): ?><div class="invalid-feedback d-block mb-1"><?= h($error) ?></div><?php endif; ?>
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
