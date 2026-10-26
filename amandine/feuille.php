<?php
// Photo d'une feuille d'heures déjà lue (réservé à Amandine).
require __DIR__ . '/inc.php';
require_admin();

$nom = (string)($_GET['f'] ?? '');
$f = preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}-[0-9a-f]{6}\.jpg$/', $nom) ? dossier_feuilles() . "/$nom" : '';
if (!is_file($f)) {
    http_response_code(404);
    exit('Photo introuvable (les photos sont gardées 3 mois).');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=86400');
readfile($f);
