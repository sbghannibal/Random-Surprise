<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Ongeldige aanvraag. Herlaad de pagina en probeer opnieuw.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $eventType = (string)($_POST['event_type'] ?? 'birthday');
        $eventDate = (string)($_POST['event_date'] ?? '');
        $drawDate = (string)($_POST['draw_date'] ?? '');
        $budgetRaw = trim((string)($_POST['budget'] ?? ''));

        $participantNames = $_POST['participant_name'] ?? [];
        $participantEmails = $_POST['participant_email'] ?? [];

        if ($name === '') {
            $errors[] = 'Naam van het evenement is verplicht.';
        } else {
            if (!in_array($eventType, ['birthday', 'secret_santa'], true)) {
                $errors[] = 'Ongeldig evenementtype.';
            }
            if (!isValidDate($eventDate)) {
                $errors[] = 'Datum evenement is ongeldig.';
            }

            if ($eventType === 'secret_santa') {
                if (!isValidDate($drawDate)) {
                    $errors[] = 'Koppel-datum is verplicht voor Secret Santa.';
                } elseif (isValidDate($eventDate)) {
                    $drawDateObj = new DateTimeImmutable($drawDate);
                    $eventDateObj = new DateTimeImmutable($eventDate);
                    if ($drawDateObj > $eventDateObj) {
                        $errors[] = 'Koppel-datum mag niet na de evenementdatum liggen.';
                    }
                }
            } else {
                $drawDate = null;
            }

            $budget = null;
            if ($budgetRaw !== '') {
                if (!is_numeric($budgetRaw) || (float)$budgetRaw < 0) {
                    $errors[] = 'Budget moet een niet-negatief getal zijn.';
                } else {
                    $budget = number_format((float)$budgetRaw, 2, '.', '');
                }
            }

            $participants = [];
            $seenEmails = [];
            foreach ($participantNames as $index => $participantName) {
                $pName = trim((string)$participantName);
                $pEmail = trim((string)($participantEmails[$index] ?? ''));

                if ($pName === '' && $pEmail === '') {
                    continue;
                }
                if ($pName === '' || $pEmail === '') {
                    $errors[] = 'Elke deelnemer moet een naam en e-mail hebben.';
                    continue;
                }
                if (!isValidEmail($pEmail)) {
                    $errors[] = 'Ongeldig e-mailadres voor deelnemer: ' . h($pName);
                    continue;
                }
                $emailKey = strtolower($pEmail);
                if (isset($seenEmails[$emailKey])) {
                    $errors[] = 'Dubbel e-mailadres gevonden: ' . h($pEmail);
                    continue;
                }
                $seenEmails[$emailKey] = true;

                $participants[] = ['name' => $pName, 'email' => $pEmail];
            }

            if (count($participants) < 2) {
                $errors[] = 'Voeg minstens 2 deelnemers toe.';
            }

            if ($errors === []) {
                $organizerToken = generateToken();
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'INSERT INTO events (token, name, event_type, event_date, draw_date, budget) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $organizerToken,
                        $name,
                        $eventType,
                        $eventDate,
                        $drawDate,
                        $budget,
                    ]);

                    $eventId = (int)$pdo->lastInsertId();
                    $participantStmt = $pdo->prepare(
                        'INSERT INTO participants (event_id, token, name, email) VALUES (?, ?, ?, ?)'
                    );

                    foreach ($participants as $participant) {
                        $participantStmt->execute([
                            $eventId,
                            generateToken(),
                            $participant['name'],
                            $participant['email'],
                        ]);
                    }

                    $pdo->commit();
                    header('Location: organizer.php?token=' . urlencode($organizerToken));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $constraintDetails = (string)($e->errorInfo[2] ?? '');
                    if ($e->getCode() === '23000' && str_contains($constraintDetails, 'uniq_event_email')) {
                        $errors[] = 'Dubbele deelnemer-e-mail gevonden. Gebruik unieke e-mailadressen per event.';
                    } elseif ($e->getCode() === '23000') {
                        $errors[] = 'Opslaan mislukt door een gegevensconflict. Probeer opnieuw.';
                    } else {
                        $errors[] = 'Opslaan mislukt. Probeer opnieuw.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Opslaan mislukt. Probeer opnieuw.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Random Surprise - Nieuw evenement</title>
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
    <div class="container"><span class="navbar-brand mb-0 h1">Random Surprise</span></div>
</nav>
<div class="container pb-5">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Maak een evenement aan</h1>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endforeach; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Naam evenement</label>
                        <input class="form-control" name="name" required value="<?= h((string)($_POST['name'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="event_type" id="event_type">
                            <option value="birthday" <?= (($_POST['event_type'] ?? '') === 'birthday') ? 'selected' : '' ?>>Verjaardag</option>
                            <option value="secret_santa" <?= (($_POST['event_type'] ?? '') === 'secret_santa') ? 'selected' : '' ?>>Secret Santa</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Datum evenement</label>
                        <input class="form-control" type="date" name="event_date" required value="<?= h((string)($_POST['event_date'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3" id="draw_date_wrap" style="display:none;">
                        <label class="form-label">Koppel-datum</label>
                        <input class="form-control" type="date" name="draw_date" value="<?= h((string)($_POST['draw_date'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Budgetlimiet (optioneel)</label>
                        <input class="form-control" type="number" min="0" step="0.01" name="budget" value="<?= h((string)($_POST['budget'] ?? '')) ?>">
                    </div>
                </div>

                <hr class="my-4">
                <h2 class="h5">Deelnemers</h2>
                <div id="participants">
                    <?php
                    $oldNames = $_POST['participant_name'] ?? ['', ''];
                    $oldEmails = $_POST['participant_email'] ?? ['', ''];
                    $rowCount = max(2, count($oldNames));
                    for ($i = 0; $i < $rowCount; $i++):
                    ?>
                        <div class="row g-2 mb-2 participant-row">
                            <div class="col-md-5"><input class="form-control" name="participant_name[]" placeholder="Naam" value="<?= h((string)($oldNames[$i] ?? '')) ?>"></div>
                            <div class="col-md-5"><input class="form-control" type="email" name="participant_email[]" placeholder="E-mail" value="<?= h((string)($oldEmails[$i] ?? '')) ?>"></div>
                            <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-participant">Verwijder</button></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-accent" id="add_participant">Voeg deelnemer toe</button>

                <div class="mt-4">
                    <button class="btn btn-primary">Opslaan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    const eventTypeSelect = document.getElementById('event_type');
    const drawDateWrap = document.getElementById('draw_date_wrap');
    const participants = document.getElementById('participants');

    function refreshDrawDate() {
        drawDateWrap.style.display = eventTypeSelect.value === 'secret_santa' ? 'block' : 'none';
    }

    eventTypeSelect.addEventListener('change', refreshDrawDate);
    refreshDrawDate();

    document.getElementById('add_participant').addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'row g-2 mb-2 participant-row';
        row.innerHTML = `
            <div class="col-md-5"><input class="form-control" name="participant_name[]" placeholder="Naam"></div>
            <div class="col-md-5"><input class="form-control" type="email" name="participant_email[]" placeholder="E-mail"></div>
            <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-participant">Verwijder</button></div>
        `;
        participants.appendChild(row);
    });

    participants.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-participant')) {
            const rows = participants.querySelectorAll('.participant-row');
            if (rows.length > 2) {
                e.target.closest('.participant-row').remove();
            }
        }
    });
</script>
</body>
</html>
