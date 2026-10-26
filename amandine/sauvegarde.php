<?php
// Pour la tâche planifiée du PC (accès par lien secret ?k=… : elle ne sait pas se connecter avec un mot de passe) :
// - sans paramètre : téléchargement de la sauvegarde du jour de la base ;
// - ?factures=1 : liste des factures et avoirs émis, pour en ranger une copie PDF sur le NAS ;
// - ?photos=1 / ?photo=ID : photos des enfants (elles ne sont pas dans la base).
require __DIR__ . '/inc.php';

db(); // crée la copie du jour si elle n'existe pas encore
if (!acces_robot()) {
    http_response_code(404);
    exit('Lien invalide.');
}
$k = rawurlencode((string)$_GET['k']);

if (isset($_GET['factures'])) {
    $docs = [];
    foreach (q('SELECT fa.numero, fa.mois, fa.famille_id, f.nom FROM factures fa JOIN familles f ON f.id = fa.famille_id ORDER BY fa.numero') as $r) {
        $docs[] = ['type' => 'Facture', 'numero' => (string)$r['numero'], 'mois' => $r['mois'], 'famille' => $r['nom'],
                   'url' => url_base() . "facture.php?f={$r['famille_id']}&m={$r['mois']}&k=$k"];
    }
    foreach (q('SELECT a.id, a.numero, a.mois, f.nom FROM avoirs a JOIN familles f ON f.id = a.famille_id ORDER BY a.numero') as $r) {
        $docs[] = ['type' => 'Avoir', 'numero' => 'AV-' . $r['numero'], 'mois' => $r['mois'], 'famille' => $r['nom'],
                   'url' => url_base() . "avoir.php?id={$r['id']}&k=$k"];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($docs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ?photos=1 : liste des photos d'enfants ; ?photo=ID : la photo elle-même
if (isset($_GET['photos'])) {
    $liste = [];
    foreach (q('SELECT e.id, e.prenom, f.nom FROM enfants e JOIN familles f ON f.id = e.famille_id ORDER BY e.id') as $e) {
        $p = photo_enfant_fichier((int)$e['id']);
        $nom = "{$e['prenom']} ({$e['nom']})";
        if (is_file($p)) $liste[] = ['id' => (int)$e['id'], 'prenom' => $e['prenom'], 'famille' => $e['nom'], 'modif' => filemtime($p),
                                     'fichier' => "$nom - {$e['id']}.jpg", 'url' => url_base() . "sauvegarde.php?k=$k&photo={$e['id']}"];
        foreach (photos_fond((int)$e['id']) as $fond) {
            $pf = dirname(DB_FILE) . "/photos/$fond";
            $liste[] = ['id' => (int)$e['id'], 'prenom' => $e['prenom'], 'famille' => $e['nom'], 'modif' => filemtime($pf),
                        'fichier' => "$nom - $fond", 'url' => url_base() . "sauvegarde.php?k=$k&photo={$e['id']}&fond=" . rawurlencode($fond)];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['photo'])) {
    $id = (int)$_GET['photo'];
    $fond = (string)($_GET['fond'] ?? '');
    $p = $fond === '' ? photo_enfant_fichier($id) : (preg_match("/^fond-$id-[0-9a-f]+\.jpg$/", $fond) ? dirname(DB_FILE) . "/photos/$fond" : '');
    if (!is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

$fic = dirname(DB_FILE) . '/sauvegardes/amandine-' . date('Y-m-d') . '.sqlite';
if (!is_file($fic)) $fic = DB_FILE;
set_setting('sauvegarde_derniere', date('Y-m-d H:i'));
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="amandine-' . date('Y-m-d') . '.sqlite"');
header('Content-Length: ' . filesize($fic));
header('Cache-Control: no-store');
readfile($fic);
