<?php
// Photo d'un enfant : visible seulement par Amandine et par les parents de l'enfant.
require __DIR__ . '/inc.php';

$id = (int)($_GET['e'] ?? 0);
$autorise = is_admin()
    || (($_SESSION['role'] ?? '') === 'parent' && q('SELECT 1 FROM enfants WHERE id = ? AND famille_id = ?', [$id, (int)$_SESSION['famille']])->fetch());
$fond = (string)($_GET['fond'] ?? '');
if ($fond !== '') { // photo de fond (nom vérifié : pas d'accès à d'autres fichiers)
    $f = preg_match("/^fond-$id-[0-9a-f]+\.jpg$/", $fond) ? dirname(DB_FILE) . "/photos/$fond" : '';
} else {
    $f = photo_enfant_fichier($id, !empty($_GET['g']));
    if (!is_file($f)) $f = photo_enfant_fichier($id); // photo envoyée avant la version « grand »
}
if (!$autorise || !is_file($f)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=2592000'); // l'adresse change (v=…) quand la photo change
readfile($f);
