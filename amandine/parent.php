<?php
// Espace parents (et vue « réservations d'une famille » pour Amandine via ?f=ID)
require __DIR__ . '/inc.php';

$admin = is_admin();
$fid = $admin ? (int)($_GET['f'] ?? 0) : require_parent();
$fam = q('SELECT * FROM familles WHERE id = ?', [$fid])->fetch();
if (!$fam) redirect($admin ? 'admin.php?p=familles' : 'index.php?logout=1');

$v = $_GET['v'] ?? 'resa';
if (vue_amandine() && $_SERVER['REQUEST_METHOD'] === 'POST') { // aperçu « comme les parents » : rien n'est enregistré
    flash("Aperçu : rien n'a été enregistré (c'est l'espace de la famille).", 'err');
    redirect('parent.php' . ($v !== 'resa' ? "?v=$v" : ''));
}
$ym = mois_valide($_GET['m'] ?? null);
$base = 'parent.php?' . ($admin ? "f=$fid&" : '');
$enfants = q('SELECT * FROM enfants WHERE famille_id = ? AND actif = 1 ORDER BY prenom', [$fid])->fetchAll();
$GLOBALS['fond_famille'] = $fid; // photo des enfants en fond d'écran (page_debut)
if (!$admin && !vue_amandine() && ($fam['derniere_visite'] ?? '') < date('Y-m-d H:i:s', time() - 300)) { // au plus une écriture toutes les 5 min
    q('UPDATE familles SET derniere_visite = ? WHERE id = ?', [date('Y-m-d H:i:s'), $fid]);
}

// ---------- Demande de garde de dernière minute ----------
if ($v === 'urgence' && !$admin && post()) {
    $jour = date_valide($_POST['jour'] ?? null);
    $pr = q('SELECT * FROM prestations WHERE id = ? AND actif = 1', [(int)($_POST['prestation'] ?? 0)])->fetch();
    $ids = array_map('intval', array_keys((array)($_POST['enfants'] ?? [])));
    $mes = array_column($enfants, null, 'id');
    $ids = array_values(array_filter($ids, fn($i) => isset($mes[$i])));
    $debut = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['debut'] ?? '')) ? $_POST['debut'] : null;
    $fin = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['fin'] ?? '')) ? $_POST['fin'] : null;
    if ($jour < date('Y-m-d') || !$ids || !$pr || !in_array($pr['id'], array_column(prest_du_jour($jour), 'id'))) {
        flash("Demande incomplète : choisissez l'enfant, la date (aujourd'hui ou plus tard) et un créneau proposé ce jour-là.", 'err');
    } else {
        $msg = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 500);
        foreach ($ids as $i) {
            q('INSERT INTO urgences (famille_id, enfant_id, jour, prestation_id, debut, fin, message) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$fid, $i, $jour, $pr['id'], $debut, $fin, $msg]);
        }
        $prenoms = implode(', ', array_map(fn($i) => $mes[$i]['prenom'], $ids));
        $dest = setting('email_contact') ?: setting('admin_email');
        if ($dest) envoyer_mail($dest, "Garde de dernière minute – $prenoms – " . date('d/m', strtotime($jour)),
            "{$fam['nom']} demande une garde de dernière minute :\n\nEnfant(s) : $prenoms\nDate : " . date_fr($jour)
            . "\nCréneau : {$pr['libelle']}" . ($debut || $fin ? ' (' . ($debut ?: $pr['debut']) . ' – ' . ($fin ?: $pr['fin']) . ')' : ' (' . horaires_prest($pr) . ')')
            . ($msg ? "\nMessage : $msg" : '') . "\nTéléphone : {$fam['tel']}\n\nRépondre (accepter / refuser) : " . url_base() . "admin.php?p=jour\n", $fam['email']);
        alerte_ntfy("⚡ Garde de dernière minute – {$fam['nom']}", "$prenoms\n" . date_fr($jour) . " – {$pr['libelle']}"
            . ($debut || $fin ? ' (' . ($debut ?: $pr['debut']) . ' – ' . ($fin ?: $pr['fin']) . ')' : '') . ($msg ? "\n« $msg »" : ''), url_base() . 'admin.php?p=jour');
        alerte_whatsapp("⚡ *Garde de dernière minute*\n{$fam['nom']} – $prenoms\n" . date_fr($jour) . " – {$pr['libelle']}"
            . ($debut || $fin ? ' (' . ($debut ?: $pr['debut']) . ' – ' . ($fin ?: $pr['fin']) . ')' : '')
            . ($msg ? "\n« $msg »" : '') . "\nRépondre : " . url_base() . 'admin.php?p=jour');
        flash("Demande envoyée à " . nom_affiche() . " : vous recevrez la réponse par email.");
    }
    redirect('parent.php');
}

// ---------- Enregistrement des réservations ----------
if ($v === 'resa' && post()) {
    $ferme = fermetures($ym);
    $voulu = $_POST['r'] ?? [];
    $heures = $_POST['h'] ?? []; // heures prévues des créneaux « horaires libres » (vacances)
    $ajouts = $suppr = $modifs = 0;
    $complets = $changes = $fermes = []; // $changes : résumé envoyé à Amandine
    $quand = fn(string $d) => JOURS_COURTS[(int)date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d));
    $horaire = fn(array $p, ?string $hd, ?string $hf) => $p['libre'] ? ' ' . ($hd ?: substr($p['debut'], 0, 5)) . '–' . ($hf ?: substr($p['fin'], 0, 5)) : '';
    db()->beginTransaction();
    foreach ($enfants as $e) {
        $exist = array_flip(q("SELECT prestation_id || '|' || jour FROM reservations WHERE enfant_id = ? AND jour LIKE ?",
            [$e['id'], "$ym-%"])->fetchAll(PDO::FETCH_COLUMN));
        foreach (jours_du_mois($ym) as $d) {
            if (isset($ferme[$d]) || (!$admin && verrouille($d))) continue;
            foreach (prest_du_jour($d) as $p) {
                if (!propose($p, $e)) continue;
                $k = $p['id'] . '|' . $d;
                $veut = !empty($voulu[$e['id']][$d][$p['id']]);
                $hd = $hf = null;
                if ($p['libre']) {
                    $hd = heure_valide($heures[$e['id']][$d][$p['id']]['debut'] ?? null);
                    $hf = heure_valide($heures[$e['id']][$d][$p['id']]['fin'] ?? null);
                    // vacances : pas de case à cocher, une heure saisie suffit (l'autre prend l'horaire du créneau)
                    if (!empty($_POST['t'][$e['id']][$d][$p['id']])) {
                        $veut = $hd || $hf;
                        if ($veut) {
                            // heure manquante : celle du créneau, mais jamais dans une plage fermée (départ ramené au début de la fermeture)
                            if (!$hf && $hd && ($bf = heures_bloquees($d, $hd, substr($p['fin'], 0, 5))) && minutes($bf['debut']) > minutes($hd)) $hf = substr($bf['debut'], 0, 5);
                            if (!$hd && $hf && ($bf = heures_bloquees($d, substr($p['debut'], 0, 5), $hf)) && minutes($bf['fin']) < minutes($hf)) $hd = substr($bf['fin'], 0, 5);
                            $hd = $hd ?: substr($p['debut'], 0, 5); $hf = $hf ?: substr($p['fin'], 0, 5);
                        }
                    }
                }
                // fermeture partielle (créneau fermé ou heures fermées) : réservation refusée pour les parents
                if ($veut && !$admin && (!isset($exist[$k]) || $p['libre'])) {
                    $b = creneau_bloque($d, $p) ?? ($p['libre'] ? heures_bloquees($d, $hd, $hf) : null);
                    if ($b) {
                        $fermes[] = $e['prenom'] . ' – ' . $quand($d) . ' : ' . texte_blocage($b);
                        continue;
                    }
                }
                if ($veut && !isset($exist[$k])) {
                    $pris = (int)q('SELECT COUNT(*) FROM reservations WHERE prestation_id = ? AND jour = ?', [$p['id'], $d])->fetchColumn();
                    if ($pris >= (int)$p['places'] && !$admin) {
                        $complets[] = $e['prenom'] . ' – ' . $p['libelle'] . ' le ' . date('d/m', strtotime($d));
                        continue;
                    }
                    reserver((int)$e['id'], $p, $d, $hd, $hf);
                    $ajouts++;
                    $changes[] = "➕ {$e['prenom']} " . $quand($d) . ' ' . $p['libelle'] . $horaire($p, $hd, $hf);
                } elseif ($veut && $p['libre']) {
                    $n = q('UPDATE reservations SET prevu_debut = ?, prevu_fin = ? WHERE enfant_id = ? AND prestation_id = ? AND jour = ?
                                  AND (COALESCE(prevu_debut, \'\') <> COALESCE(?, \'\') OR COALESCE(prevu_fin, \'\') <> COALESCE(?, \'\'))',
                        [$hd, $hf, $e['id'], $p['id'], $d, $hd, $hf])->rowCount();
                    $modifs += $n;
                    if ($n) $changes[] = "🕒 {$e['prenom']} " . $quand($d) . ' nouvel horaire' . $horaire($p, $hd, $hf);
                } elseif (!$veut && isset($exist[$k])) {
                    q('DELETE FROM reservations WHERE enfant_id = ? AND prestation_id = ? AND jour = ?', [$e['id'], $p['id'], $d]);
                    $suppr++;
                    $changes[] = "❌ {$e['prenom']} " . $quand($d) . ' ' . $p['libelle'] . ' annulé';
                }
            }
        }
    }
    db()->commit();
    if ($changes && !$admin) {
        $max = 25; // WhatsApp : message court, le détail complet est dans le planning
        prevenir_amandine("Réservations – {$fam['nom']}", implode("\n", array_slice($changes, 0, $max))
            . (count($changes) > $max ? "\n… et " . (count($changes) - $max) . ' autre(s)' : ''),
            (string)$fam['email'], url_base() . "admin.php?p=planning&m=$ym");
    }
    flash($ajouts || $suppr || $modifs ? "Enregistré : $ajouts réservation(s) ajoutée(s), $suppr annulée(s)" . ($modifs ? ", $modifs horaire(s) modifié(s)" : '') . '.' : 'Aucun changement.');
    if ($complets) flash('Complet, non réservé : ' . implode(' ; ', $complets), 'err');
    if ($fermes) flash(nom_affiche() . ' est absente, non réservé : ' . implode(' ; ', $fermes), 'err');
    redirect($base . "m=$ym");
}

// ---------- Factures ----------
if ($v === 'factures') {
    page_debut('Factures' . ($admin ? ' – ' . $fam['nom'] : ''));
    $mois = q('SELECT f.mois AS m, f.numero, p.date AS paye FROM factures f
               LEFT JOIN paiements p ON p.famille_id = f.famille_id AND p.mois = f.mois
               WHERE f.famille_id = ? ORDER BY f.mois DESC', [$fid])->fetchAll();
    ?>
    <div class="scroll"><table>
      <tr><th>Mois</th><th>N°</th><th class="r">Montant</th><th>Statut</th><th></th></tr>
      <?php foreach ($mois as $m): ?>
        <tr><td><?= h(mois_fr($m['m'])) ?></td><td><?= (int)$m['numero'] ?></td><td class="r"><?= euro(calcul_facture($fid, $m['m'])['net']) ?></td>
          <td><?= $m['paye'] ? '<span class="badge">Payée le ' . h(date('d/m/Y', strtotime($m['paye']))) . '</span>' : '<span class="badge warn">À régler</span>' ?></td>
          <td><a href="facture.php?m=<?= h($m['m']) ?><?= $admin ? "&f=$fid" : '' ?>">Voir / imprimer</a></td></tr>
      <?php endforeach ?>
      <?php if (!$mois): ?><tr><td colspan="5" class="muted">Aucune facture pour le moment.</td></tr><?php endif ?>
    </table></div>
    <?php $avoirs = q('SELECT * FROM avoirs WHERE famille_id = ? ORDER BY numero DESC', [$fid])->fetchAll();
    if ($avoirs): ?>
      <h2>Avoirs</h2>
      <div class="scroll"><table>
        <tr><th>N°</th><th>Période</th><th>Motif</th><th class="r">Montant</th><th></th></tr>
        <?php foreach ($avoirs as $a): ?>
          <tr><td>AV-<?= (int)$a['numero'] ?></td><td><?= h(mois_fr($a['mois'])) ?></td><td><?= h($a['motif']) ?></td><td class="r">−<?= euro($a['montant']) ?></td>
            <td><a href="avoir.php?id=<?= (int)$a['id'] ?>">Voir / imprimer</a></td></tr>
        <?php endforeach ?>
      </table></div>
    <?php endif;
    $annees = q("SELECT DISTINCT substr(date, 1, 4) FROM paiements WHERE famille_id = ? ORDER BY 1 DESC", [$fid])->fetchAll(PDO::FETCH_COLUMN);
    if ($annees): ?>
      <h2>Attestations fiscales</h2>
      <p class="muted">Pour votre déclaration de revenus (frais de garde, hors repas).</p>
      <p><?php foreach ($annees as $a): ?><a class="btn light" href="attestation.php?a=<?= h($a) ?><?= $admin ? "&f=$fid" : '' ?>">Attestation <?= h($a) ?></a> <?php endforeach ?></p>
    <?php endif ?>
    <?php
    page_fin();
    exit;
}

// ---------- Mon compte : changer son mot de passe ----------
if ($v === 'compte' && !$admin) {
    if (post()) {
        $nouveau = (string)($_POST['nouveau'] ?? '');
        if (!password_verify((string)($_POST['actuel'] ?? ''), (string)$fam['pass'])) {
            flash('Mot de passe actuel incorrect.', 'err');
        } elseif (strlen($nouveau) < 8) {
            flash('Le nouveau mot de passe doit faire au moins 8 caractères.', 'err');
        } elseif ($nouveau !== (string)($_POST['confirmation'] ?? '')) {
            flash('Les deux nouveaux mots de passe ne sont pas identiques.', 'err');
        } else {
            q('UPDATE familles SET pass = ? WHERE id = ?', [password_hash($nouveau, PASSWORD_DEFAULT), $fid]);
            session_regenerate_id(true);
            flash('Mot de passe modifié. Utilisez-le à votre prochaine connexion.');
        }
        redirect('parent.php?v=compte');
    }
    page_debut('Mon compte');
    ?>
    <div class="card narrow">
      <p><b><?= h($fam['nom']) ?></b><br><span class="muted">Email de connexion : <?= h($fam['email']) ?></span></p>
      <form method="post"><?= csrf_field() ?>
        <h2 style="margin-top:0">Choisir un nouveau mot de passe</h2>
        <label>Mot de passe actuel (celui reçu par email)<input type="password" name="actuel" required autocomplete="current-password"></label>
        <label>Nouveau mot de passe (8 caractères minimum)<input type="password" name="nouveau" required minlength="8" autocomplete="new-password"></label>
        <label>Nouveau mot de passe, encore une fois<input type="password" name="confirmation" required minlength="8" autocomplete="new-password"></label>
        <button>Enregistrer</button>
      </form>
      <p class="muted">Pour changer d'email de connexion, contactez <?= h(nom_affiche()) ?><?= setting('tel') ? ' au ' . h(setting('tel')) : '' ?>.</p>
    </div>
    <?php
    page_fin();
    exit;
}

// ---------- Devis et contrats ----------
if ($v === 'contrats') {
    page_debut('Devis et contrats' . ($admin ? ' – ' . $fam['nom'] : ''));
    $docs = q("SELECT * FROM contrats WHERE famille_id = ? AND statut <> 'brouillon' ORDER BY numero DESC", [$fid])->fetchAll();
    ?>
    <div class="scroll"><table>
      <tr><th>N°</th><th>Objet</th><th class="r">Montant</th><th>Statut</th><th></th></tr>
      <?php foreach ($docs as $d): ?>
        <tr><td><?= $d['type'] === 'devis' ? 'Devis' : 'Contrat' ?> <?= (int)$d['numero'] ?></td><td><?= h($d['titre']) ?></td>
          <td class="r"><?= euro(calcul_contrat($d)['total']) ?></td>
          <td><?= $d['statut'] === 'accepte' ? '<span class="badge">Accepté le ' . h(date('d/m/Y', strtotime($d['accepte_le']))) . '</span>' : '<span class="badge warn">À valider</span>' ?></td>
          <td><a href="contrat.php?id=<?= (int)$d['id'] ?>"><?= $d['statut'] === 'envoye' && !$admin ? 'Lire et donner mon accord' : 'Voir / imprimer' ?></a></td></tr>
      <?php endforeach ?>
      <?php if (!$docs): ?><tr><td colspan="5" class="muted">Aucun devis ni contrat.</td></tr><?php endif ?>
    </table></div>
    <?php
    page_fin();
    exit;
}

// ---------- Fiches enfants : les parents remplissent les infos pratiques et les photos (pas les tarifs ni les notes d'Amandine) ----------
const CHAMPS_FICHE = ['naissance' => 'Date de naissance', 'ecole' => 'École', 'classe' => 'Classe', 'allergies' => 'Allergies / santé',
                      'autorises' => 'Personnes autorisées à venir le chercher', 'urgence' => "Contact d'urgence"];
if ($v === 'enfants') {
    if (post()) {
        $eid = (int)($_POST['eid'] ?? 0);
        $e = array_column($enfants, null, 'id')[$eid] ?? null;
        if ($e && ($_POST['act'] ?? '') === 'fiche') {
            $neuf = [];
            foreach (CHAMPS_FICHE as $c => $lib) $neuf[$c] = mb_substr(trim((string)($_POST[$c] ?? '')), 0, 500);
            $neuf['naissance'] = $neuf['naissance'] !== '' && date_valide($neuf['naissance']) === $neuf['naissance'] ? $neuf['naissance'] : null;
            $diff = [];
            foreach (CHAMPS_FICHE as $c => $lib) if ((string)($e[$c] ?? '') !== (string)($neuf[$c] ?? '')) {
                $diff[] = ($c === 'allergies' ? '⚠️ ' : '') . "$lib : " . ($c === 'naissance' && $neuf[$c] ? date('d/m/Y', strtotime($neuf[$c])) : ($neuf[$c] ?: '(vide)'));
            }
            if ($diff) {
                q('UPDATE enfants SET naissance = ?, ecole = ?, classe = ?, allergies = ?, autorises = ?, urgence = ? WHERE id = ? AND famille_id = ?',
                    [$neuf['naissance'], $neuf['ecole'], $neuf['classe'], $neuf['allergies'], $neuf['autorises'], $neuf['urgence'], $eid, $fid]);
                if (!$admin) prevenir_amandine("Fiche de {$e['prenom']} modifiée – {$fam['nom']}", implode("\n", $diff), (string)$fam['email'],
                    url_base() . "admin.php?p=famille&id=$fid");
                flash("Fiche de {$e['prenom']} enregistrée. " . nom_affiche() . ' est prévenue.');
            } else flash('Aucun changement.');
            redirect($base . 'v=enfants');
        }
        if ($e) {
            if (!empty($_FILES['photo']['name'])) {
                $err = enregistrer_photo_enfant($eid, $_FILES['photo']);
                flash($err ?: 'Photo enregistrée.', $err ? 'err' : 'ok');
            }
            foreach ((array)($_POST['suppr_fond'] ?? []) as $nom) supprimer_photo_fond($eid, (string)$nom);
            if (!empty($_POST['suppr_fond'])) flash('Photo(s) retirée(s).');
            [$nf, $errs] = ajouter_photos_fond($eid, $_FILES['fonds'] ?? []);
            if ($nf) flash("$nf photo(s) ajoutée(s) au fond d'écran.");
            foreach ($errs as $m) flash($m, 'err');
        }
        redirect($base . 'v=enfants');
    }
    page_debut('Mes enfants');
    foreach ($enfants as $e): ?>
        <div class="card"><h2 style="display:flex;align-items:center;gap:.7rem"><span id="rond-<?= (int)$e['id'] ?>"><?= avatar($e, 72) ?></span> <?= h($e['prenom'] . ' ' . $e['nom']) ?></h2>
          <form method="post" enctype="multipart/form-data" class="row" style="margin:-.3rem 0 .6rem"><?= csrf_field() ?><input type="hidden" name="eid" value="<?= (int)$e['id'] ?>">
            <label style="margin:0"><?= is_file(photo_enfant_fichier((int)$e['id'])) ? 'Changer la photo' : 'Ajouter une photo' ?>
              <input type="file" name="photo" accept="image/*" required data-photo-profil="rond-<?= (int)$e['id'] ?>"></label>
          </form>
          <details style="margin:0 0 .8rem"><summary><b>Photos du fond d'écran</b> <span class="muted">(<?= count(photos_fond((int)$e['id'])) ?> – une au hasard à chaque ouverture)</span></summary>
            <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="eid" value="<?= (int)$e['id'] ?>">
              <label>Choisir des photos (plusieurs d'un coup, ou en plusieurs fois)<input type="file" name="fonds[]" accept="image/*" multiple data-album="Envoyer les photos"></label>
              <button>Envoyer les photos</button>
              <?php $v = vignettes_fond((int)$e['id']); if ($v): ?><p class="muted" style="margin:.8rem 0 0">Déjà dans l'album :</p><?= $v ?><button class="light small">Retirer les photos cochées</button><?php endif ?>
            </form>
          </details>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="fiche"><input type="hidden" name="eid" value="<?= (int)$e['id'] ?>">
            <b>Fiche de <?= h($e['prenom']) ?></b> <span class="muted">— à compléter ou mettre à jour, <?= h(nom_affiche()) ?> est prévenue des changements</span>
            <div class="grid2" style="margin-top:.5rem">
              <label>Date de naissance<input type="date" name="naissance" value="<?= h($e['naissance'] ?? '') ?>" max="<?= date('Y-m-d') ?>"></label>
              <label>École<input name="ecole" value="<?= h($e['ecole'] ?? '') ?>" placeholder="ex. École des Bossons"></label>
              <label>Classe<input name="classe" value="<?= h($e['classe'] ?? '') ?>" placeholder="ex. CE1"></label>
              <label>Allergies / santé<input name="allergies" value="<?= h($e['allergies'] ?? '') ?>" placeholder="ex. arachides, asthme… (vide si rien)"></label>
              <label>Personnes autorisées à venir le chercher<textarea name="autorises" placeholder="Nom, lien, téléphone"><?= h($e['autorises'] ?? '') ?></textarea></label>
              <label>Contact d'urgence<textarea name="urgence" placeholder="Nom, lien, téléphone"><?= h($e['urgence'] ?? '') ?></textarea></label>
            </div>
            <button>Enregistrer la fiche</button>
          </form></div>
    <?php endforeach;
    $meds = q("SELECT m.*, e.prenom FROM medicaments m JOIN enfants e ON e.id = m.enfant_id
               WHERE e.famille_id = ? AND m.jour >= date('now', '-12 months') ORDER BY m.jour DESC, m.heure DESC", [$fid])->fetchAll();
    if ($meds): ?>
      <h2>Médicaments donnés (12 derniers mois)</h2>
      <div class="scroll"><table>
        <tr><th>Date</th><th>Enfant</th><th>Médicament</th><th>Dose</th><th>Motif</th></tr>
        <?php foreach ($meds as $m): ?>
          <tr><td><?= h(date('d/m/Y', strtotime($m['jour']))) ?><?= $m['heure'] ? ' ' . h(str_replace(':', 'h', $m['heure'])) : '' ?></td>
            <td><?= h($m['prenom']) ?></td><td><?= h($m['medicament']) ?></td><td><?= h($m['dose']) ?></td><td><?= h($m['motif']) ?></td></tr>
        <?php endforeach ?>
      </table></div>
    <?php endif ?>
    <p class="muted">Une question ? Contactez <?= h(nom_affiche()) ?><?= setting('tel') ? ' au ' . h(setting('tel')) : '' ?>.</p>
    <?php
    page_fin();
    exit;
}

// ---------- Grille de réservation du mois ----------
page_debut('Réservations' . ($admin ? ' – ' . $fam['nom'] : ''));
$ferme = fermetures($ym);
$prests = prestations();
$occup = [];
foreach (q('SELECT prestation_id, jour, COUNT(*) AS n FROM reservations WHERE jour LIKE ? GROUP BY prestation_id, jour', ["$ym-%"]) as $o) {
    $occup[$o['prestation_id']][$o['jour']] = (int)$o['n'];
}
$delai = (int)setting('delai_jours', '1');
?>
<div class="row">
  <a class="btn light small" href="<?= h($base . 'm=' . mois_decale($ym, -1)) ?>">◀</a>
  <b><?= h(mois_fr($ym)) ?></b>
  <a class="btn light small" href="<?= h($base . 'm=' . mois_decale($ym, 1)) ?>">▶</a>
</div>
<?php if ($admin): ?>
  <p class="muted">Vue Amandine : vous pouvez modifier toutes les dates, même passées ou complètes.</p>
<?php else: ?>
  <p class="muted">Pendant les vacances scolaires, indiquez simplement l'heure d'arrivée et de départ prévues (laissez vide si l'enfant ne vient pas).<br>
    Cochez les créneaux souhaités puis cliquez sur « Enregistrer ». Les modifications sont possibles jusqu'à <?= $delai ? "$delai jour(s) avant" : 'la veille' ?>.</p>
<?php endif ?>

<?php if ($enfants && !$admin):
  $urg = q("SELECT u.*, e.prenom, p.libelle FROM urgences u JOIN enfants e ON e.id = u.enfant_id JOIN prestations p ON p.id = u.prestation_id
            WHERE u.famille_id = ? AND u.jour >= date('now', '-7 day') ORDER BY u.cree DESC LIMIT 6", [$fid])->fetchAll(); ?>
  <details class="card"<?= array_filter($urg, fn($u) => $u['statut'] === 'attente') ? ' open' : '' ?>>
    <summary><b>⚡ Besoin d'une garde de dernière minute ?</b> <span class="muted">(après le délai de réservation)</span></summary>
    <?php foreach ($urg as $u): ?>
      <div class="muted" style="margin:.3rem 0">
        <?= h($u['prenom']) ?> – <?= h(date('d/m', strtotime($u['jour']))) ?> – <?= h($u['libelle']) ?> :
        <?= $u['statut'] === 'attente' ? '<span class="badge warn">en attente</span>' : ($u['statut'] === 'acceptee' ? '<span class="badge">acceptée ✓</span>' : '<span class="badge full">refusée</span>') ?>
        <?= $u['reponse'] ? '« ' . h($u['reponse']) . ' »' : '' ?>
      </div>
    <?php endforeach ?>
    <form method="post" action="parent.php?v=urgence" style="margin-top:.6rem"><?= csrf_field() ?>
      <div><?php foreach ($enfants as $e): ?>
        <label style="display:inline-block;margin-right:1rem;color:var(--ink)"><input type="checkbox" name="enfants[<?= $e['id'] ?>]" <?= count($enfants) === 1 ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> <?= h($e['prenom']) ?></label>
      <?php endforeach ?></div>
      <div class="grid2">
        <label>Date<input type="date" name="jour" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required></label>
        <label>Créneau<select name="prestation" required><?php foreach (prestations() as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['libelle']) ?> (<?= h(horaires_prest($p)) ?>)</option><?php endforeach ?></select></label>
        <label>Arrivée prévue (optionnel)<input type="time" name="debut"></label>
        <label>Départ prévu (optionnel)<input type="time" name="fin"></label>
      </div>
      <label>Message<input name="message" placeholder="ex. réunion tardive, je passe vers 19h"></label>
      <button>Envoyer la demande</button>
      <span class="muted">Amandine reçoit la demande tout de suite et vous répond par email.</span>
    </form>
  </details>
<?php endif ?>

<?php if (!$enfants): ?>
  <div class="card">Aucun enfant enregistré pour cette famille<?= $admin ? ' : ajoutez-en depuis la fiche famille.' : '. Contactez ' . h(nom_affiche()) . '.' ?></div>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <?php foreach ($enfants as $e):
    $mes = [];
    foreach (q('SELECT prestation_id, jour, prevu_debut, prevu_fin FROM reservations WHERE enfant_id = ? AND jour LIKE ?', [$e['id'], "$ym-%"]) as $r) {
        $mes[$r['prestation_id'] . '|' . $r['jour']] = $r;
    }
    // colonnes : seulement les créneaux proposés au moins un jour du mois (pas de colonne « école » en plein été)
    $utiles = [];
    foreach (jours_du_mois($ym) as $d) foreach (prest_du_jour($d) as $p) $utiles[$p['id']] = true;
    $pe = array_values(array_filter($prests, fn($p) => propose($p, $e) && isset($utiles[$p['id']])));
    $pecol = array_values(array_filter($pe, fn($p) => ($p['periode'] ?? '') !== 'vacances')); // colonnes hors vacances
  ?>
  <h2><?= h($e['prenom']) ?><?= $e['cantine'] ? ' <span class="badge">cantine le midi</span>' : '' ?></h2>
  <div class="scroll"><table>
    <tr><th>Jour</th>
      <?php foreach ($pecol as $p): ?>
        <th class="c"><?= h($p['libelle']) ?><br><span class="muted"><?= h(horaires_prest($p)) ?></span><br>
          <button type="button" class="light small" data-toggle="<?= $e['id'] . '-' . $p['id'] ?>">tout</button></th>
      <?php endforeach ?>
    </tr>
    <?php foreach (jours_du_mois($ym) as $d):
      $ids = array_column(array_filter(prest_du_jour($d), fn($p) => propose($p, $e)), 'id');
      if (!$ids) continue;
      $n = (int)date('N', strtotime($d)); ?>
      <?php if (isset($ferme[$d])): ?>
        <tr class="closed"><td><?= JOURS_COURTS[$n] . ' ' . date('d/m', strtotime($d)) ?></td><td colspan="<?= max(1, count($pecol)) ?>">Fermé<?= $ferme[$d] ? ' – ' . h($ferme[$d]) : '' ?></td></tr>
      <?php continue; endif ?>
      <?php $vac = vacances_du_jour($d); ?>
      <tr class="<?= $d === date('Y-m-d') ? 'today' : '' ?>">
        <td><?= JOURS_COURTS[$n] . ' ' . date('d/m', strtotime($d)) ?><?= $vac ? '<br><span class="badge">' . h($vac) . '</span>' : '' ?></td>
        <?php if ($vac): // vacances : les créneaux du jour sur toute la largeur de la ligne (pas besoin de défiler) ?>
          <td colspan="<?= max(1, count($pecol)) ?>">
          <?php foreach ($pe as $p): if (!in_array($p['id'], $ids)) continue;
            $k = $p['id'] . '|' . $d; $coche = isset($mes[$k]);
            $plein = !$coche && ($occup[$p['id']][$d] ?? 0) >= (int)$p['places'];
            $absente = !$coche ? creneau_bloque($d, $p) : null; // fermeture partielle (jamais sur une réservation déjà faite)
            $bloque = !$admin && (verrouille($d) || $plein || $absente); $nom = "[{$e['id']}][$d][{$p['id']}]"; ?>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin:.15rem 0">
              <?php if ($p['libre']): // heures remplies = gardé, vides = pas de garde
                // heures fermées en début ou en fin de journée : on borne les champs (le navigateur refuse une heure hors limites)
                $hMin = $hMax = '';
                if (!$admin && !$coche) foreach (blocages_du_jour($d) as $b) { // pas sur une réservation déjà faite : elle bloquerait tout le formulaire
                    if ($b['prestation_id']) continue;
                    if (minutes($b['fin']) >= minutes($p['fin']) && minutes($b['debut']) > minutes($p['debut'])) $hMax = $hMax === '' || $b['debut'] < $hMax ? substr($b['debut'], 0, 5) : $hMax;
                    if (minutes($b['debut']) <= minutes($p['debut']) && minutes($b['fin']) < minutes($p['fin'])) $hMin = $hMin === '' || $b['fin'] > $hMin ? substr($b['fin'], 0, 5) : $hMin;
                }
                $bornes = ($hMin ? " min=\"$hMin\"" : '') . ($hMax ? " max=\"$hMax\"" : ''); ?>
                <input type="hidden" name="t<?= $nom ?>" value="1" <?= $bloque ? 'disabled' : '' ?>>
                <span class="muted">arrivée</span><input type="time" name="h<?= $nom ?>[debut]" value="<?= h($coche ? substr($mes[$k]['prevu_debut'] ?? $p['debut'], 0, 5) : '') ?>" <?= $bloque ? 'disabled' : '' ?><?= $bornes ?> aria-label="Arrivée prévue">
                <span class="muted">départ</span><input type="time" name="h<?= $nom ?>[fin]" value="<?= h($coche ? substr($mes[$k]['prevu_fin'] ?? $p['fin'], 0, 5) : '') ?>" <?= $bloque ? 'disabled' : '' ?><?= $bornes ?> aria-label="Départ prévu">
                <?php if ($hMin || $hMax): ?><span class="muted">(possible <?= $hMin ? 'à partir de ' . h(str_replace(':', 'h', $hMin)) : '' ?><?= $hMin && $hMax ? ' et ' : '' ?><?= $hMax ? "jusqu'à " . h(str_replace(':', 'h', $hMax)) : '' ?>)</span><?php endif ?>
                <?php if ($coche): ?><span class="badge">réservé</span><?php endif ?>
              <?php else: ?>
              <label style="display:inline-flex;align-items:center;gap:.35rem;margin:0;color:var(--ink)">
                <input type="checkbox" name="r<?= $nom ?>" value="1" data-col="<?= $e['id'] . '-' . $p['id'] ?>" <?= $coche ? 'checked' : '' ?> <?= $bloque ? 'disabled' : '' ?>>
                <?= h($p['libelle']) ?></label>
              <?php endif ?>
              <?php if ($plein): ?><span class="badge full">complet</span><?php endif ?>
              <?php if ($absente): ?><span class="badge full">fermé</span><?php endif ?>
              <?php if ($p['libre']) foreach (blocages_du_jour($d) as $b) if (!$b['prestation_id']): ?>
                <span class="badge warn"><?= h(texte_blocage($b)) ?></span>
              <?php endif ?>
            </div>
          <?php endforeach ?>
          </td>
        <?php else: ?>
        <?php foreach ($pecol as $p):
          if (!in_array($p['id'], $ids)) { echo '<td></td>'; continue; }
          $k = $p['id'] . '|' . $d;
          $coche = isset($mes[$k]);
          $plein = !$coche && ($occup[$p['id']][$d] ?? 0) >= (int)$p['places'];
          $absente = !$coche ? creneau_bloque($d, $p) : null;
          $bloque = !$admin && (verrouille($d) || $plein || $absente);
          $nom = "[{$e['id']}][$d][{$p['id']}]"; ?>
          <td class="c">
            <input type="checkbox" name="r<?= $nom ?>" value="1" data-col="<?= $e['id'] . '-' . $p['id'] ?>"
              <?= $coche ? 'checked' : '' ?> <?= $bloque ? 'disabled' : '' ?> aria-label="<?= h($e['prenom'] . ' ' . $p['libelle'] . ' ' . $d) ?>">
            <?php if ($p['libre']): ?>
              <div class="heures" style="white-space:nowrap;margin-top:.2rem">
                <input type="time" name="h<?= $nom ?>[debut]" value="<?= h(substr($mes[$k]['prevu_debut'] ?? $p['debut'] ?? '', 0, 5)) ?>" <?= $bloque ? 'disabled' : '' ?> aria-label="Arrivée" title="Arrivée prévue">
                <span class="muted">→</span>
                <input type="time" name="h<?= $nom ?>[fin]" value="<?= h(substr($mes[$k]['prevu_fin'] ?? $p['fin'] ?? '', 0, 5)) ?>" <?= $bloque ? 'disabled' : '' ?> aria-label="Départ" title="Départ prévu">
              </div>
            <?php endif ?>
            <?php if ($plein): ?><br><span class="badge full">complet</span><?php endif ?>
            <?php if ($absente): ?><br><span class="badge full" title="<?= h(texte_blocage($absente)) ?>">fermé</span><?php endif ?>
          </td>
        <?php endforeach ?>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
  </table></div>
  <?php endforeach ?>
  <?php $c = calcul_mois($fid, $ym); ?>
  <p class="total">Estimation <?= h(mois_fr($ym)) ?> : <?= euro($c['total']) ?> <span class="muted">(<?= duree_fr($c['heures']) ?> de garde + repas/goûters)</span></p>
  <p class="muted">Tarif à l'heure ; le montant définitif est calculé sur les heures réelles d'arrivée et de départ.</p>
  <button>Enregistrer</button>
</form>
<?php endif;
page_fin();
