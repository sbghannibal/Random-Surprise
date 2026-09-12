<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$errors = [];
$fieldErrors = [];

$submittedParticipantNames = $_POST['participant_name'] ?? ['', ''];
if (!is_array($submittedParticipantNames)) {
    $submittedParticipantNames = ['', ''];
}

$submittedParticipantEmails = $_POST['participant_email'] ?? ['', ''];
if (!is_array($submittedParticipantEmails)) {
    $submittedParticipantEmails = ['', ''];
}

$old = [
    'name' => trim((string)($_POST['name'] ?? '')),
    'event_type' => (string)($_POST['event_type'] ?? 'birthday'),
    'event_date' => (string)($_POST['event_date'] ?? ''),
    'draw_date' => (string)($_POST['draw_date'] ?? ''),
    'budget' => trim((string)($_POST['budget'] ?? '')),
    'participant_name' => array_map(static fn ($value): string => trim((string)$value), $submittedParticipantNames),
    'participant_email' => array_map(static fn ($value): string => trim((string)$value), $submittedParticipantEmails),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        addFormError($errors, $fieldErrors, null, 'Ongeldige aanvraag. Herlaad de pagina en probeer opnieuw.');
    } else {
        $name = $old['name'];
        $eventType = $old['event_type'];
        $eventDate = $old['event_date'];
        $drawDate = $old['draw_date'];
        $budgetRaw = $old['budget'];
        $participantNames = $old['participant_name'];
        $participantEmails = $old['participant_email'];

        if ($name === '') {
            addFormError($errors, $fieldErrors, 'name', 'Naam van het evenement is verplicht.');
        }
        if (!in_array($eventType, ['birthday', 'secret_santa'], true)) {
            addFormError($errors, $fieldErrors, 'event_type', 'Kies een geldig evenementtype.');
        }
        if ($eventDate === '') {
            addFormError($errors, $fieldErrors, 'event_date', 'Datum van het evenement is verplicht.');
        } elseif (!isValidDate($eventDate)) {
            addFormError($errors, $fieldErrors, 'event_date', 'Vul een geldige evenementdatum in.');
        }

        if ($eventType === 'secret_santa') {
            if ($drawDate === '') {
                addFormError($errors, $fieldErrors, 'draw_date', 'Koppel-datum is verplicht voor Secret Santa.');
            } elseif (!isValidDate($drawDate)) {
                addFormError($errors, $fieldErrors, 'draw_date', 'Vul een geldige koppel-datum in.');
            } elseif (isValidDate($eventDate)) {
                $drawDateObj = new DateTimeImmutable($drawDate);
                $eventDateObj = new DateTimeImmutable($eventDate);
                if ($drawDateObj > $eventDateObj) {
                    addFormError($errors, $fieldErrors, 'draw_date', 'Koppel-datum mag niet na de evenementdatum liggen.');
                }
            }
        } else {
            $drawDate = null;
        }

        $budget = null;
        if ($budgetRaw !== '') {
            if (!is_numeric($budgetRaw) || (float)$budgetRaw < 0) {
                addFormError($errors, $fieldErrors, 'budget', 'Budget moet een geldig getal van 0 of hoger zijn.');
            } else {
                $budget = number_format((float)$budgetRaw, 2, '.', '');
            }
        }

        $participants = [];
        $seenEmails = [];
        $hasParticipantFieldErrors = false;
        foreach ($participantNames as $index => $participantName) {
            $pName = trim((string)$participantName);
            $pEmail = trim((string)($participantEmails[$index] ?? ''));

            if ($pName === '' && $pEmail === '') {
                continue;
            }

            if ($pName === '') {
                $hasParticipantFieldErrors = true;
                addFormError($errors, $fieldErrors, 'participant_name_' . $index, 'Naam van deelnemer ' . ($index + 1) . ' is verplicht.');
            }
            if ($pEmail === '') {
                $hasParticipantFieldErrors = true;
                addFormError($errors, $fieldErrors, 'participant_email_' . $index, 'E-mailadres van deelnemer ' . ($index + 1) . ' is verplicht.');
                continue;
            }
            if (!isValidEmail($pEmail)) {
                $hasParticipantFieldErrors = true;
                addFormError($errors, $fieldErrors, 'participant_email_' . $index, 'Vul een geldig e-mailadres in voor deelnemer ' . ($index + 1) . '.');
                continue;
            }
            $emailKey = strtolower($pEmail);
            if (isset($seenEmails[$emailKey])) {
                $hasParticipantFieldErrors = true;
                addFormError($errors, $fieldErrors, 'participant_email_' . $index, 'Dit e-mailadres is al ingevuld voor een andere deelnemer.');
                continue;
            }
            $seenEmails[$emailKey] = true;

            if ($pName !== '') {
                $participants[] = ['name' => $pName, 'email' => $pEmail];
            }
        }

        if (count($participants) < 2 && !$hasParticipantFieldErrors) {
            addFormError($errors, $fieldErrors, 'participants', 'Voeg minstens 2 deelnemers met naam en e-mailadres toe.');
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
                logApplicationError('Kon evenement niet opslaan', $e);
                $constraintDetails = (string)($e->errorInfo[2] ?? '');
                if ((string)$e->getCode() === '23000' && str_contains($constraintDetails, 'uniq_event_email')) {
                    addFormError($errors, $fieldErrors, 'participants', 'Een deelnemer met dit e-mailadres bestaat al binnen dit evenement. Gebruik per deelnemer een uniek e-mailadres.');
                } elseif ((string)$e->getCode() === '23000') {
                    addFormError($errors, $fieldErrors, null, 'Opslaan mislukt door een gegevensconflict. Controleer je invoer en probeer opnieuw.');
                } else {
                    addFormError($errors, $fieldErrors, null, 'Het evenement kon niet worden opgeslagen. Probeer het opnieuw.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                logApplicationError('Onverwachte fout bij opslaan evenement', $e);
                addFormError($errors, $fieldErrors, null, 'Het evenement kon niet worden opgeslagen. Probeer het opnieuw.');
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

            <?php renderErrorSummary(
                $errors,
                $fieldErrors,
                'Opslaan mislukt. Controleer de gemarkeerde velden.',
                'index-error-summary',
                ['participants' => fieldErrorId('participant_name_0')]
            ); ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="<?= h(fieldErrorId('name')) ?>">Naam evenement</label>
                        <input class="<?= fieldErrorClass($fieldErrors, 'name') ?>" id="<?= h(fieldErrorId('name')) ?>" name="name" required value="<?= h($old['name']) ?>">
                        <?php if ($error = firstFieldError($fieldErrors, 'name')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="<?= h(fieldErrorId('event_type')) ?>">Type</label>
                        <select class="<?= fieldErrorClass($fieldErrors, 'event_type', 'form-select') ?>" name="event_type" id="<?= h(fieldErrorId('event_type')) ?>">
                            <option value="birthday" <?= ($old['event_type'] === 'birthday') ? 'selected' : '' ?>>Verjaardag</option>
                            <option value="secret_santa" <?= ($old['event_type'] === 'secret_santa') ? 'selected' : '' ?>>Secret Santa</option>
                        </select>
                        <?php if ($error = firstFieldError($fieldErrors, 'event_type')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="<?= h(fieldErrorId('event_date')) ?>">Datum evenement</label>
                        <input class="<?= fieldErrorClass($fieldErrors, 'event_date') ?>" id="<?= h(fieldErrorId('event_date')) ?>" type="date" name="event_date" required value="<?= h($old['event_date']) ?>">
                        <?php if ($error = firstFieldError($fieldErrors, 'event_date')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3" id="draw_date_wrap" style="display:none;">
                        <label class="form-label" for="<?= h(fieldErrorId('draw_date')) ?>">Koppel-datum</label>
                        <input class="<?= fieldErrorClass($fieldErrors, 'draw_date') ?>" id="<?= h(fieldErrorId('draw_date')) ?>" type="date" name="draw_date" value="<?= h($old['draw_date']) ?>">
                        <?php if ($error = firstFieldError($fieldErrors, 'draw_date')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="<?= h(fieldErrorId('budget')) ?>">Budgetlimiet (optioneel)</label>
                        <input class="<?= fieldErrorClass($fieldErrors, 'budget') ?>" id="<?= h(fieldErrorId('budget')) ?>" type="number" min="0" step="0.01" name="budget" value="<?= h($old['budget']) ?>">
                        <?php if ($error = firstFieldError($fieldErrors, 'budget')): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                    </div>
                </div>

                <hr class="my-4">
                <div>
                <h2 class="h5" id="<?= h(fieldErrorId('participants')) ?>" tabindex="-1">Deelnemers</h2>
                <div id="participants">
                    <?php
                    $oldNames = $old['participant_name'];
                    $oldEmails = $old['participant_email'];
                    $rowCount = max(2, count($oldNames));
                    for ($i = 0; $i < $rowCount; $i++):
                    ?>
                        <div class="row g-2 mb-2 participant-row">
                            <div class="col-md-5">
                            <input class="<?= fieldErrorClass($fieldErrors, 'participant_name_' . $i) ?>" id="<?= h(fieldErrorId('participant_name_' . $i)) ?>" name="participant_name[]" placeholder="Naam" value="<?= h((string)($oldNames[$i] ?? '')) ?>">
                                <?php if ($error = firstFieldError($fieldErrors, 'participant_name_' . $i)): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-5">
                            <input class="<?= fieldErrorClass($fieldErrors, 'participant_email_' . $i) ?>" id="<?= h(fieldErrorId('participant_email_' . $i)) ?>" type="email" name="participant_email[]" placeholder="E-mail" value="<?= h((string)($oldEmails[$i] ?? '')) ?>">
                                <?php if ($error = firstFieldError($fieldErrors, 'participant_email_' . $i)): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-participant">Verwijder</button></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <?php if ($error = firstFieldError($fieldErrors, 'participants')): ?><div class="text-danger small mb-3"><?= h($error) ?></div><?php endif; ?>
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
    const eventTypeSelect = document.getElementById('<?= fieldErrorId('event_type') ?>');
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
