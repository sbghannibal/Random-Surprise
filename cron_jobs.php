<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

function isDerangement(array $base, array $candidate): bool
{
    foreach ($base as $i => $id) {
        if ($candidate[$i] === $id) {
            return false;
        }
    }
    return true;
}

function createDerangement(array $ids): array
{
    $original = array_values($ids);
    $candidate = $original;

    if (count($ids) < 2) {
        return [];
    }

    for ($tries = 0; $tries < 200; $tries++) {
        shuffle($candidate);
        if (isDerangement($original, $candidate)) {
            return $candidate;
        }
    }

    shuffle($candidate);
    return array_merge(array_slice($candidate, 1), array_slice($candidate, 0, 1));
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
try {
    baseUrl();
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        echo "APP_BASE_URL ontbreekt of is ongeldig.\n";
    }
    exit(1);
}

$eventStmt = $pdo->prepare(
    "SELECT e.id
     FROM events e
     WHERE e.event_type = 'secret_santa'
       AND e.draw_date IS NOT NULL
       AND e.draw_date <= ?
       AND EXISTS (
           SELECT 1 FROM participants p WHERE p.event_id = e.id AND p.matched_participant_id IS NULL
       )"
);
$eventStmt->execute([$today]);
$eventIds = array_map(static fn(array $row): int => (int)$row['id'], $eventStmt->fetchAll());

$drawCount = 0;
foreach ($eventIds as $eventId) {
    try {
        $pdo->beginTransaction();

        $eventLockStmt = $pdo->prepare(
            "SELECT e.id, e.name
             FROM events e
             WHERE e.id = ?
               AND e.event_type = 'secret_santa'
               AND e.draw_date IS NOT NULL
               AND e.draw_date <= ?
             FOR UPDATE"
        );
        $eventLockStmt->execute([$eventId, $today]);
        $event = $eventLockStmt->fetch();
        if (!$event) {
            $pdo->rollBack();
            continue;
        }

        $participantStmt = $pdo->prepare('SELECT id, name, email, token, matched_participant_id FROM participants WHERE event_id = ? ORDER BY id FOR UPDATE');
        $participantStmt->execute([(int)$event['id']]);
        $participants = $participantStmt->fetchAll();

        if (count($participants) < 2) {
            $pdo->rollBack();
            continue;
        }

        foreach ($participants as $participant) {
            if ($participant['matched_participant_id'] !== null) {
                $pdo->rollBack();
                continue 2;
            }
        }

        $participantIds = array_map(static fn(array $p): int => (int)$p['id'], $participants);
        $drawnIds = createDerangement($participantIds);
        if ($drawnIds === []) {
            $pdo->rollBack();
            continue;
        }

        $updateStmt = $pdo->prepare('UPDATE participants SET matched_participant_id = ? WHERE id = ?');
        $assignmentMap = [];
        foreach ($participantIds as $i => $giverId) {
            $receiverId = $drawnIds[$i];
            $updateStmt->execute([$receiverId, $giverId]);
            $assignmentMap[$giverId] = $receiverId;
        }

        $nameLookup = [];
        foreach ($participants as $participant) {
            $nameLookup[(int)$participant['id']] = (string)$participant['name'];
        }

        $pendingEmails = [];
        foreach ($participants as $participant) {
            $giverId = (int)$participant['id'];
            $receiverId = $assignmentMap[$giverId] ?? 0;
            $receiverName = $nameLookup[$receiverId] ?? 'onbekend';
            $link = absoluteUrl(sprintf('gift_list.php?token=%s', urlencode((string)$participant['token'])));

            $body = "Hoi {$participant['name']},\n\nVoor event '{$event['name']}' heb jij getrokken: {$receiverName}.\nBekijk het lijstje via: {$link}";
            $pendingEmails[] = [
                'to' => (string)$participant['email'],
                'subject' => 'Secret Santa trekking uitgevoerd',
                'body' => $body,
            ];
        }

        $pdo->commit();
        foreach ($pendingEmails as $email) {
            sendMailSafe($email['to'], $email['subject'], $email['body']);
        }
        $drawCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

$cleanupStmt = $pdo->prepare('DELETE FROM events WHERE event_date < DATE_SUB(?, INTERVAL 28 DAY)');
$cleanupStmt->execute([$today]);
$deletedEvents = $cleanupStmt->rowCount();

if (PHP_SAPI === 'cli') {
    echo "Trekkingen uitgevoerd: {$drawCount}\n";
    echo "Oude events verwijderd: {$deletedEvents}\n";
}
