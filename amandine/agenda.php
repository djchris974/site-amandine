<?php
// Agenda à abonnement (iCalendar) pour le téléphone d'Amandine : créneaux réservés, fermetures, anniversaires.
// Accès par lien secret (?k=…) car les applis d'agenda ne savent pas se connecter avec un mot de passe.
require __DIR__ . '/inc.php';

$k = (string)($_GET['k'] ?? '');
if (setting('ics_token') === '' || !hash_equals(setting('ics_token'), $k)) {
    http_response_code(404);
    exit('Lien d\'agenda invalide.');
}

function ics_txt(string $s): string
{
    return str_replace(["\\", "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\\;'], $s);
}

/** Coupe les lignes à 75 octets comme l'exige le format iCalendar. */
function ics_ligne(string $l): string
{
    $out = '';
    while (strlen($l) > 75) {
        $coupe = 75;
        while ($coupe > 0 && (ord($l[$coupe]) & 0xC0) === 0x80) $coupe--; // ne pas couper un caractère UTF-8
        $out .= substr($l, 0, $coupe) . "\r\n ";
        $l = substr($l, $coupe);
    }
    return $out . $l . "\r\n";
}

$hote = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$stamp = gmdate('Ymd\THis\Z');
$du = date('Y-m-d', strtotime('-30 day'));
$au = date('Y-m-d', strtotime('+120 day'));
$lignes = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Garde periscolaire//Agenda//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ics_txt(nom_affiche()), 'X-WR-TIMEZONE:Europe/Paris', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
    'BEGIN:VTIMEZONE', 'TZID:Europe/Paris',
    'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
    'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
    'END:VTIMEZONE',
];

// Un événement par créneau et par jour, avec la liste des enfants
$rows = q("SELECT r.jour, r.arrivee, r.depart, r.present, r.prevu_debut, r.prevu_fin, r.repas, r.gouter, r.petitdej, p.id AS pid, p.libelle, p.debut, p.fin,
                  e.prenom, e.allergies, f.tel
           FROM reservations r JOIN prestations p ON p.id = r.prestation_id JOIN enfants e ON e.id = r.enfant_id
           JOIN familles f ON f.id = e.famille_id
           WHERE r.jour BETWEEN ? AND ? ORDER BY r.jour, p.ordre, e.prenom", [$du, $au])->fetchAll();
$groupes = [];
foreach ($rows as $r) $groupes[$r['jour'] . '|' . $r['pid']][] = $r;
foreach ($groupes as $cle => $g) {
    $r0 = $g[0];
    // heures prévues par les parents (créneau vacances) : de la première arrivée au dernier départ
    $r0['debut'] = min(array_map(fn($r) => $r['prevu_debut'] ?: $r['debut'], $g));
    $r0['fin'] = max(array_map(fn($r) => $r['prevu_fin'] ?: $r['fin'], $g));
    if (!$r0['debut'] || !$r0['fin']) continue;
    $presents = array_filter($g, fn($r) => $r['present'] === null || (int)$r['present'] === 1);
    $noms = array_map(fn($r) => $r['prenom'] . ($r['allergies'] ? ' ⚠' : ''), $presents ?: $g);
    $desc = [];
    foreach ($g as $r) {
        $extras = array_keys(array_filter(['p.déj' => $r['petitdej'], 'repas' => $r['repas'], 'goûter' => $r['gouter']]));
        $desc[] = $r['prenom'] . ((int)($r['present'] ?? 1) === 0 && $r['present'] !== null ? ' (absent)' : '')
            . ($extras ? ' – ' . implode(', ', $extras) : '') . ($r['allergies'] ? ' – ALLERGIE : ' . $r['allergies'] : '') . ($r['tel'] ? ' – parents ' . $r['tel'] : '');
    }
    $j = str_replace('-', '', $r0['jour']);
    $fin = minutes($r0['fin']) <= minutes($r0['debut']) ? date('Ymd', strtotime($r0['jour'] . ' +1 day')) : $j;
    array_push($lignes, 'BEGIN:VEVENT', 'UID:resa-' . md5($cle) . "@$hote", "DTSTAMP:$stamp",
        "DTSTART;TZID=Europe/Paris:{$j}T" . str_replace(':', '', substr($r0['debut'], 0, 5)) . '00',
        "DTEND;TZID=Europe/Paris:{$fin}T" . str_replace(':', '', substr($r0['fin'], 0, 5)) . '00',
        'SUMMARY:' . ics_txt($r0['libelle'] . ' : ' . implode(', ', $noms) . ' (' . count($presents ?: $g) . ')'),
        'DESCRIPTION:' . ics_txt(implode("\n", $desc)), 'END:VEVENT');
}

// Jours de fermeture (journée entière)
foreach (q('SELECT jour, motif FROM fermetures WHERE jour BETWEEN ? AND ?', [$du, $au]) as $f) {
    $j = str_replace('-', '', $f['jour']);
    array_push($lignes, 'BEGIN:VEVENT', "UID:ferme-$j@$hote", "DTSTAMP:$stamp", "DTSTART;VALUE=DATE:$j",
        'DTEND;VALUE=DATE:' . date('Ymd', strtotime($f['jour'] . ' +1 day')), 'SUMMARY:' . ics_txt('Fermé' . ($f['motif'] ? ' – ' . $f['motif'] : '')),
        'TRANSP:TRANSPARENT', 'END:VEVENT');
}

// Fermetures partielles (créneau fermé : journée entière dans l'agenda ; plage d'heures : à ses horaires)
foreach (q('SELECT * FROM blocages WHERE jour BETWEEN ? AND ?', [$du, $au]) as $b) {
    $j = str_replace('-', '', $b['jour']);
    $quand = $b['prestation_id'] ? ["DTSTART;VALUE=DATE:$j", 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($b['jour'] . ' +1 day'))]
        : ["DTSTART;TZID=Europe/Paris:{$j}T" . str_replace(':', '', substr($b['debut'], 0, 5)) . '00',
           "DTEND;TZID=Europe/Paris:{$j}T" . str_replace(':', '', substr($b['fin'], 0, 5)) . '00'];
    array_push($lignes, 'BEGIN:VEVENT', "UID:bloc-{$b['id']}@$hote", "DTSTAMP:$stamp", ...[...$quand,
        'SUMMARY:' . ics_txt('Absente – ' . texte_blocage($b)), 'END:VEVENT']);
}

// Anniversaires des enfants (prochains 120 jours)
foreach (anniversaires(120) as $a) {
    $j = str_replace('-', '', $a['date']);
    array_push($lignes, 'BEGIN:VEVENT', 'UID:anniv-' . md5($a['prenom'] . $a['naissance']) . "-$j@$hote", "DTSTAMP:$stamp",
        "DTSTART;VALUE=DATE:$j", 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($a['date'] . ' +1 day')),
        'SUMMARY:' . ics_txt('🎂 ' . $a['prenom'] . ' – ' . $a['age'] . ' ans'), 'TRANSP:TRANSPARENT', 'END:VEVENT');
}

$lignes[] = 'END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="garde.ics"');
header('Cache-Control: no-cache');
echo implode('', array_map('ics_ligne', $lignes));
