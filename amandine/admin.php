<?php
// Espace Amandine
require __DIR__ . '/inc.php';
require_admin();

$p = $_GET['p'] ?? 'jour';
$P = fn(string $k, string $def = ''): string => trim((string)($_POST[$k] ?? $def));

switch ($p) {

// ================= JOURNÉE : qui vient, pointage =================
case 'jour':
    $d = date_valide($_GET['d'] ?? null);
    if (post()) {
        if (in_array($_POST['act'] ?? '', ['urg_ok', 'urg_non'], true)) {
            $u = q("SELECT u.*, e.prenom, f.email, f.nom AS famille FROM urgences u JOIN enfants e ON e.id = u.enfant_id
                    JOIN familles f ON f.id = u.famille_id WHERE u.id = ? AND u.statut = 'attente'", [(int)$_POST['id']])->fetch();
            $pr = $u ? q('SELECT * FROM prestations WHERE id = ?', [$u['prestation_id']])->fetch() : null;
            if ($u && $pr) {
                $ok = $_POST['act'] === 'urg_ok';
                $rep = trim((string)($_POST['reponse'] ?? ''));
                if ($ok) {
                    reserver((int)$u['enfant_id'], $pr, $u['jour']);
                    if ($u['debut'] || $u['fin']) {
                        q('UPDATE reservations SET arrivee = COALESCE(?, arrivee), depart = COALESCE(?, depart) WHERE enfant_id = ? AND prestation_id = ? AND jour = ?',
                            [$u['debut'], $u['fin'], $u['enfant_id'], $pr['id'], $u['jour']]);
                    }
                }
                q("UPDATE urgences SET statut = ?, reponse = ?, traitee_le = datetime('now', 'localtime') WHERE id = ?", [$ok ? 'acceptee' : 'refusee', $rep, $u['id']]);
                envoyer_mail($u['email'], ($ok ? 'Garde acceptée' : 'Garde impossible') . " – {$u['prenom']} le " . date('d/m', strtotime($u['jour'])),
                    "Bonjour,\n\n" . ($ok
                        ? "C'est d'accord pour la garde de {$u['prenom']} le " . date_fr($u['jour']) . " ({$pr['libelle']}). La réservation est enregistrée."
                        : "Désolée, la garde de {$u['prenom']} le " . date_fr($u['jour']) . " ({$pr['libelle']}) n'est pas possible.")
                    . ($rep ? "\n\n$rep" : '') . "\n\n" . nom_affiche() . (setting('tel') ? "\n" . setting('tel') : '') . "\n");
                flash($ok ? "Demande acceptée : {$u['prenom']} est ajouté(e) le " . date('d/m', strtotime($u['jour'])) . ', la famille est prévenue par email.'
                          : 'Demande refusée, la famille est prévenue par email.');
                redirect('admin.php?p=jour&d=' . $u['jour']);
            }
            redirect("admin.php?p=jour&d=$d");
        }
        if (isset($_POST['suppr'])) {
            q('DELETE FROM reservations WHERE id = ?', [(int)$_POST['suppr']]);
            flash('Réservation supprimée.');
        } elseif (($_POST['act'] ?? '') === 'ajout') {
            $pr = q('SELECT * FROM prestations WHERE id = ?', [(int)$_POST['prestation']])->fetch();
            if ($pr) {
                reserver((int)$_POST['enfant'], $pr, $d);
                flash('Enfant ajouté.');
            }
        } else {
            foreach ($_POST['pt'] ?? [] as $rid => $v) {
                $pres = $v['present'] ?? '';
                q('UPDATE reservations SET arrivee = ?, depart = ?, present = ?, repas = ?, gouter = ?, petitdej = ?, nuit = ? WHERE id = ?',
                    [($v['arrivee'] ?? '') ?: null, ($v['depart'] ?? '') ?: null, $pres === '' ? null : (int)$pres,
                     isset($v['repas']) ? 1 : 0, isset($v['gouter']) ? 1 : 0, isset($v['petitdej']) ? 1 : 0, isset($v['nuit']) ? 1 : 0, (int)$rid]);
            }
            flash('Pointage enregistré.');
        }
        redirect("admin.php?p=jour&d=$d");
    }
    page_debut(ucfirst(date_fr($d)));
    $urgences = q("SELECT u.*, e.prenom, e.allergies, f.nom AS famille, f.tel, p.libelle, p.debut AS p_debut, p.fin AS p_fin,
                          (SELECT COUNT(*) FROM reservations r WHERE r.prestation_id = u.prestation_id AND r.jour = u.jour) AS pris, p.places
                   FROM urgences u JOIN enfants e ON e.id = u.enfant_id JOIN familles f ON f.id = u.famille_id JOIN prestations p ON p.id = u.prestation_id
                   WHERE u.statut = 'attente' ORDER BY u.jour, u.cree")->fetchAll();
    foreach ($urgences as $u): ?>
      <div class="card" style="border:2px solid var(--warn)">
        <b>⚡ Garde de dernière minute :</b> <?= h($u['prenom']) ?> (<?= h($u['famille']) ?>) –
        <b><?= h(date_fr($u['jour'])) ?></b> – <?= h($u['libelle']) ?>
        <?= h(($u['debut'] ?: $u['p_debut']) . ' – ' . ($u['fin'] ?: $u['p_fin'])) ?>
        <span class="badge<?= $u['pris'] >= $u['places'] ? ' warn' : '' ?>"><?= (int)$u['pris'] ?>/<?= (int)$u['places'] ?> déjà</span>
        <?= $u['allergies'] ? '<span class="badge warn">' . h($u['allergies']) . '</span>' : '' ?>
        <?= $u['message'] ? '<br><span class="muted">« ' . h($u['message']) . ' »</span>' : '' ?>
        <span class="muted"> · demandé le <?= h(date('d/m à H:i', strtotime($u['cree']))) ?> · <?= h($u['tel']) ?></span>
        <form method="post" class="row" style="margin:.5rem 0 0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <input name="reponse" placeholder="Petit mot pour les parents (optionnel)" style="flex:1;min-width:200px">
          <button class="small" name="act" value="urg_ok">Accepter</button>
          <button class="danger small" name="act" value="urg_non" data-confirm="Refuser cette demande ?">Refuser</button>
        </form>
      </div>
    <?php endforeach;
    $retard = array_filter(impayes(), fn($r) => $r['jours'] > 15);
    if ($retard): ?><div class="flash err"><a href="?p=impayes"><?= count($retard) ?> facture(s) impayée(s) depuis plus de 15 jours (<?= euro(array_sum(array_column($retard, 'reste'))) ?>) →</a></div><?php endif;
    $attente = (int)q("SELECT COUNT(*) FROM contrats WHERE statut = 'accepte' AND accepte_le > datetime('now', 'localtime', '-7 day')")->fetchColumn();
    if ($attente): ?><div class="flash"><a href="?p=contrats"><?= $attente ?> devis/contrat(s) accepté(s) cette semaine →</a></div><?php endif;
    if ((int)date('n') <= 3) {
        $anPrec = (int)date('Y') - 1;
        $reste = array_filter(familles_attestation($anPrec), fn($f) => !setting("attest_{$anPrec}_{$f['id']}"));
        if ($reste): ?><div class="flash err"><a href="?p=recap&a=<?= $anPrec ?>">Attestations fiscales <?= $anPrec ?> à remettre avant le 31 mars : <?= count($reste) ?> famille(s) restante(s) →</a></div><?php endif;
    }
    $annivs = anniversaires(7);
    if ($annivs): ?><div class="flash">🎂 <?= implode(' · ', array_map(fn($a) => '<b>' . h($a['prenom']) . '</b> ' . $a['age'] . ' ans '
        . ($a['dans'] === 0 ? "aujourd'hui !" : ($a['dans'] === 1 ? 'demain' : 'le ' . date('d/m', strtotime($a['date'])))), $annivs)) ?></div><?php endif;
    $ferme = q('SELECT motif FROM fermetures WHERE jour = ?', [$d])->fetchColumn();
    $prev = date('Y-m-d', strtotime("$d -1 day"));
    $next = date('Y-m-d', strtotime("$d +1 day"));
    ?>
    <form class="row" method="get">
      <input type="hidden" name="p" value="jour">
      <a class="btn light small" href="?p=jour&d=<?= $prev ?>">◀</a>
      <input type="date" name="d" value="<?= h($d) ?>" onchange="this.form.submit()">
      <a class="btn light small" href="?p=jour&d=<?= $next ?>">▶</a>
      <a class="btn light small" href="?p=jour">Aujourd'hui</a>
      <a class="btn light small" href="?p=photo">📷 Importer une feuille d'heures</a>
    </form>
    <?php if ($nf = count(feuilles_en_attente())): ?><div class="flash err">📷 <?= $nf ?> ligne(s) lue(s) sur des feuilles d'heures, pas encore importée(s).
      <a href="?p=photo&v=verif">Reprendre la vérification</a></div><?php endif ?>
    <?php if ($vac = vacances_du_jour($d)): ?><div class="flash">Vacances scolaires : <?= h($vac) ?></div><?php endif ?>
    <?php if ($blocs = blocages_du_jour($d)): ?><div class="flash err">Fermeture partielle : <?= h(implode(' · ', array_map('texte_blocage', $blocs))) ?>
      <a href="?p=fermetures">gérer</a></div><?php endif ?>
    <?php if ($ferme !== false): ?><div class="flash err">Jour de fermeture<?= $ferme ? ' : ' . h($ferme) : '' ?></div><?php endif;
    $prs = prest_du_jour($d);
    if (!$prs): ?><div class="card muted">Aucune prestation ce jour-là.</div><?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <?php foreach ($prs as $pr):
        $rows = q('SELECT r.*, e.prenom, e.nom, e.ecole, e.classe, e.allergies, e.autorises, e.urgence, e.sans_repas, f.tel, f.nom AS famille
                   FROM reservations r JOIN enfants e ON e.id = r.enfant_id JOIN familles f ON f.id = e.famille_id
                   WHERE r.prestation_id = ? AND r.jour = ? ORDER BY e.prenom', [$pr['id'], $d])->fetchAll(); ?>
        <h2><?= h($pr['libelle']) ?> <span class="muted"><?= h(horaires_prest($pr)) ?></span>
          <span class="badge<?= count($rows) >= $pr['places'] ? ' warn' : '' ?>"><?= count($rows) ?> / <?= (int)$pr['places'] ?></span></h2>
        <?php if (!$rows): ?><p class="muted">Personne.</p><?php else: ?>
        <div class="scroll"><table>
          <tr><th>Enfant</th><th>Infos</th><th>Arrivée</th><th>Départ</th><th>Repas</th><th>Présence</th><th></th></tr>
          <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
            <tr>
              <td><div style="display:flex;gap:.5rem;align-items:center"><?= avatar(['id' => $r['enfant_id'], 'prenom' => $r['prenom']], 40) ?>
                <div><b><?= h($r['prenom'] . ' ' . $r['nom']) ?></b><br><span class="muted"><?= h(trim($r['ecole'] . ' ' . $r['classe'])) ?></span></div></div></td>
              <td><?php if ($r['allergies']): ?><span class="badge warn"><?= h($r['allergies']) ?></span><br><?php endif ?>
                <span class="muted">Parents : <?= h($r['tel'] ?: '—') ?><?= $r['autorises'] ? '<br>Autorisés : ' . h($r['autorises']) : '' ?></span></td>
              <td><input type="time" id="a<?= $id ?>" name="pt[<?= $id ?>][arrivee]" value="<?= h($r['arrivee']) ?>" title="Vide = <?= h($r['prevu_debut'] ?: $pr['debut']) ?> (prévu)">
                <button type="button" class="light small" data-now="a<?= $id ?>" data-pres="p<?= $id ?>">maint.</button>
                <?php if (!$r['arrivee']): ?><br><span class="muted">prévu <?= h(substr($r['prevu_debut'] ?: $pr['debut'], 0, 5)) ?></span><?php endif ?></td>
              <td><input type="time" id="d<?= $id ?>" name="pt[<?= $id ?>][depart]" value="<?= h($r['depart']) ?>" title="Vide = <?= h($r['prevu_fin'] ?: $pr['fin']) ?> (prévu)">
                <button type="button" class="light small" data-now="d<?= $id ?>" data-pres="p<?= $id ?>">maint.</button>
                <?php if (!$r['depart']): ?><br><span class="muted">prévu <?= h(substr($r['prevu_fin'] ?: $pr['fin'], 0, 5)) ?></span><?php endif ?>
                <label style="display:block;margin:.2rem 0 0;color:var(--ink);white-space:nowrap;font-size:.85rem"><input type="checkbox" name="pt[<?= $id ?>][nuit]" <?= !empty($r['nuit']) ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> 🌙 dort ici (départ le lendemain)</label></td>
              <td style="white-space:nowrap"><?php if ($r['sans_repas']): ?><span class="muted">sans repas</span><?php else:
                foreach (['petitdej' => 'P.déj', 'repas' => 'Repas', 'gouter' => 'Goûter'] as $x => $lib): ?>
                  <label style="display:block;margin:0"><input type="checkbox" name="pt[<?= $id ?>][<?= $x ?>]" <?= $r[$x] ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> <?= $lib ?></label>
                <?php endforeach; endif ?></td>
              <td><select id="p<?= $id ?>" name="pt[<?= $id ?>][present]">
                  <option value="" <?= $r['present'] === null ? 'selected' : '' ?>>—</option>
                  <option value="1" <?= $r['present'] === 1 || $r['present'] === '1' ? 'selected' : '' ?>>Présent</option>
                  <option value="0" <?= $r['present'] === 0 || $r['present'] === '0' ? 'selected' : '' ?>>Absent</option>
                </select></td>
              <td><button class="danger small" name="suppr" value="<?= $id ?>" data-confirm="Supprimer cette réservation ?">✕</button></td>
            </tr>
          <?php endforeach ?>
        </table></div>
        <?php endif ?>
      <?php endforeach ?>
      <p><button name="act" value="pointage">Enregistrer le pointage</button>
        <span class="muted">Heures vides = horaires prévus. « Absent » = non facturé.
          <b>Nuit</b> : mettez l'heure de départ du lendemain matin (ex. 07:30) ; les heures de <?= h(str_replace(':', 'h', setting('nuit_debut', '20:00'))) ?>
          à <?= h(str_replace(':', 'h', setting('nuit_fin', '07:00'))) ?> sont facturées au tarif de nuit.</span></p>
    </form>

    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="act" value="ajout">
      <b>Ajouter un enfant ce jour</b>
      <div class="row" style="margin-top:.5rem">
        <select name="enfant" required><option value="">Enfant…</option>
          <?php foreach (q('SELECT e.id, e.prenom, e.nom FROM enfants e JOIN familles f ON f.id = e.famille_id WHERE e.actif = 1 AND f.actif = 1 ORDER BY e.prenom') as $e): ?>
            <option value="<?= $e['id'] ?>"><?= h($e['prenom'] . ' ' . $e['nom']) ?></option>
          <?php endforeach ?></select>
        <select name="prestation"><?php foreach ($prs as $pr): ?><option value="<?= $pr['id'] ?>"><?= h($pr['libelle']) ?></option><?php endforeach ?></select>
        <button>Ajouter</button>
      </div>
    </form>
    <?php endif;
    break;

// ================= PLANNING DU MOIS =================
case 'planning':
    $ym = mois_valide($_GET['m'] ?? null);
    page_debut('Planning – ' . mois_fr($ym));
    $ferme = fermetures($ym);
    // qui est réservé, par jour et par créneau (avec les heures prévues pour le créneau vacances)
    $qui = [];
    foreach (q("SELECT r.jour, r.prestation_id, r.prevu_debut, r.prevu_fin, r.present, e.prenom
                FROM reservations r JOIN enfants e ON e.id = r.enfant_id
                WHERE r.jour LIKE ? ORDER BY e.prenom", ["$ym-%"]) as $r) {
        $t = $r['prenom'] . ($r['prevu_debut'] ? ' ' . hfr(minutes($r['prevu_debut'])) . '–' . hfr(minutes($r['prevu_fin'])) : '');
        $qui[$r['jour']][$r['prestation_id']][] = $r['present'] !== null && (int)$r['present'] === 0 ? "<s>" . h($t) . "</s>" : h($t);
    }
    // semaines du lundi au samedi (le dimanche seulement si un créneau existe ce jour-là)
    $avecDimanche = (bool)array_filter(prestations(), fn($p) => in_array('7', explode(',', $p['jours']), true));
    $nbCol = $avecDimanche ? 7 : 6;
    $premier = strtotime("$ym-01");
    $debut = strtotime('-' . (date('N', $premier) - 1) . ' day', $premier);
    $fin = strtotime(date('Y-m-t', $premier));
    ?>
    <script>document.querySelector('main').classList.add('large')</script>
    <style>
      .cal { display: grid; grid-template-columns: repeat(<?= $nbCol ?>, minmax(0, 1fr)); gap: .5rem; margin-bottom: 1rem; }
      .cal .jc { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: .45rem .55rem; font-size: .85rem; min-height: 5rem; }
      .cal .jc.hors { visibility: hidden; }
      .cal .jc.today { border: 2px solid var(--accent); background: var(--accent-soft); box-shadow: 0 0 0 3px rgba(47,125,109,.15); }
      .cal .auj { background: var(--accent); color: #fff; border-radius: 999px; padding: .05rem .5rem; font-size: .75rem; font-weight: 700; }
      .cal .jc.ferme { background: var(--closed); color: var(--muted); }
      .cal .jc .d { display: flex; justify-content: space-between; align-items: baseline; gap: .3rem; margin-bottom: .25rem; }
      .cal .jc .d a { font-weight: 700; text-decoration: none; color: var(--ink); }
      .cal .cr { margin: .2rem 0; line-height: 1.3; }
      .cal .cr b { font-weight: 600; color: var(--accent); }
      .cal .cr.vide { color: var(--muted); opacity: .6; }
      .cal .semaine { grid-column: 1 / -1; font-weight: 600; color: var(--muted); margin: .6rem 0 -.2rem; font-size: .9rem; }
      @media (max-width: 800px) { .cal { grid-template-columns: 1fr; } .cal .jc.hors, .cal .jc.vide-jour { display: none; } }
    </style>
    <div class="row">
      <a class="btn light small" href="?p=planning&m=<?= mois_decale($ym, -1) ?>">◀</a><b><?= h(mois_fr($ym)) ?></b>
      <a class="btn light small" href="?p=planning&m=<?= mois_decale($ym, 1) ?>">▶</a>
      <span class="muted">Cliquez sur un jour pour le pointage. <s>Barré</s> = absent.</span>
    </div>
    <script>document.addEventListener('DOMContentLoaded', () => document.getElementById('cette-semaine')?.scrollIntoView({ block: 'start' }))</script>
    <div class="cal">
      <?php for ($s = $debut; $s <= $fin; $s = strtotime('+7 day', $s)):
        if (date('Y-m', strtotime('+' . ($nbCol - 1) . ' day', $s)) < $ym) continue; // semaine affichée sans aucun jour du mois (le 1er est un dimanche) ?>
        <?php $courante = date('Y-m-d') >= date('Y-m-d', $s) && date('Y-m-d') <= date('Y-m-d', strtotime('+6 day', $s)); ?>
        <div class="semaine"<?= $courante ? ' id="cette-semaine"' : '' ?>>Semaine du <?= date('d/m', $s) ?> au <?= date('d/m', strtotime('+' . ($nbCol - 1) . ' day', $s)) ?>
          <?= $courante ? ' <span class="auj">Cette semaine' . ((int)date('N') > $nbCol ? " – aujourd'hui " . JOURS_COURTS[(int)date('N')] . ' ' . date('d') : '') . '</span>' : '' ?></div>
        <?php for ($i = 0; $i < $nbCol; $i++):
          $d = date('Y-m-d', strtotime("+$i day", $s));
          if (substr($d, 0, 7) !== $ym): ?><div class="jc hors"></div><?php continue; endif;
          $prs = prest_du_jour($d);
          $cls = ($d === date('Y-m-d') ? ' today' : '') . (isset($ferme[$d]) ? ' ferme' : '') . (!$prs ? ' vide-jour' : '');
          $vac = vacances_du_jour($d); ?>
          <div class="jc<?= $cls ?>">
            <div class="d"><a href="?p=jour&d=<?= $d ?>"><?= JOURS_COURTS[(int)date('N', strtotime($d))] . ' ' . date('d', strtotime($d)) ?></a>
              <?= $d === date('Y-m-d') ? '<span class="auj">Aujourd\'hui</span>' : '' ?>
              <?= $vac ? '<span class="badge">' . h($vac) . '</span>' : '' ?></div>
            <?php if (isset($ferme[$d])): ?>Fermé<?= $ferme[$d] ? ' – ' . h($ferme[$d]) : '' ?>
            <?php else: foreach ($prs as $pr): $l = $qui[$d][$pr['id']] ?? []; $bl = creneau_bloque($d, $pr); ?>
              <div class="cr<?= $l ? '' : ' vide' ?>"><b><?= h($pr['libelle']) ?></b>
                <?= $l ? '<span class="badge' . (count($l) >= $pr['places'] ? ' warn' : '') . '">' . count($l) . '/' . (int)$pr['places'] . '</span><br>' . implode(', ', $l) : '—' ?>
                <?= $bl ? '<span class="badge full">fermé</span>' : '' ?></div>
            <?php endforeach; foreach (blocages_du_jour($d) as $b) if (!$b['prestation_id']): ?>
              <div class="cr"><span class="badge full"><?= h(texte_blocage($b)) ?></span></div>
            <?php endif; endif ?>
          </div>
        <?php endfor ?>
      <?php endfor ?>
    </div>
    <?php
    break;

// ================= VOIR L'ESPACE D'UNE FAMILLE COMME LES PARENTS (lecture seule) =================
case 'voir_comme':
    $vf = q('SELECT id FROM familles WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetchColumn();
    if (!$vf) redirect('admin.php?p=familles');
    $_SESSION['role'] = 'parent';
    $_SESSION['famille'] = (int)$vf;
    $_SESSION['vue_amandine'] = 1;
    redirect('parent.php');

// ================= FAMILLES =================
case 'familles':
    if (post() && in_array($_POST['act'] ?? '', ['inviter', 'inviter_tous'], true)) {
        $ids = ($_POST['act'] === 'inviter') ? [(int)$_POST['id']]
            : array_filter(q('SELECT id FROM familles WHERE actif = 1')->fetchAll(PDO::FETCH_COLUMN), fn($i) => !setting("invit_$i"));
        $ok = $ko = [];
        foreach ($ids as $i) {
            $nom = q('SELECT nom FROM familles WHERE id = ?', [$i])->fetchColumn();
            if (inviter_famille((int)$i)) $ok[] = $nom; else $ko[] = $nom;
        }
        if ($ok) flash('Invitation envoyée (avec un nouveau mot de passe) à : ' . implode(', ', $ok));
        if ($ko) flash("Échec de l'envoi pour : " . implode(', ', $ko), 'err');
        if (!$ok && !$ko) flash('Toutes les familles ont déjà été invitées.');
        redirect(($_POST['retour'] ?? '') === 'famille' ? 'admin.php?p=famille&id=' . (int)$_POST['id'] : 'admin.php?p=familles');
    }
    if (post()) {
        $email = strtolower($P('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $P('nom') === '') {
            flash('Nom et email valides obligatoires.', 'err');
        } elseif ($email === setting('admin_email') || q('SELECT 1 FROM familles WHERE email = ?', [$email])->fetch()) {
            flash('Cet email est déjà utilisé.', 'err');
        } else {
            $mdp = mot_de_passe_aleatoire();
            q('INSERT INTO familles (nom, email, tel, adresse, pass) VALUES (?, ?, ?, ?, ?)',
                [$P('nom'), $email, $P('tel'), $P('adresse'), password_hash($mdp, PASSWORD_DEFAULT)]);
            $id = (int)db()->lastInsertId();
            flash("Famille créée. Identifiants à transmettre aux parents : $email / $mdp (notez-le, il ne sera plus affiché).");
            redirect("admin.php?p=famille&id=$id");
        }
        redirect('admin.php?p=familles');
    }
    page_debut('Familles');
    $fams = q("SELECT f.*, (SELECT GROUP_CONCAT(prenom, ', ') FROM enfants WHERE famille_id = f.id AND actif = 1) AS enfants
               FROM familles f ORDER BY f.actif DESC, f.nom")->fetchAll();
    $nonInvitees = count(array_filter($fams, fn($f) => $f['actif'] && !setting("invit_{$f['id']}")));
    ?>
    <?php if ($nonInvitees): ?>
      <form method="post" class="card"><?= csrf_field() ?>
        <b><?= $nonInvitees ?> famille(s) pas encore invitée(s) à l'espace parents.</b>
        Chaque famille reçoit un email avec un bouton vers l'appli, ses identifiants (un nouveau mot de passe est créé) et le mode d'emploi.
        <p><button name="act" value="inviter_tous" data-confirm="Envoyer l'invitation par email à <?= $nonInvitees ?> famille(s) ?">Inviter toutes ces familles</button></p>
      </form>
    <?php endif ?>
    <div class="scroll"><table>
      <tr><th>Famille</th><th>Enfants</th><th>Contact</th><th>Espace parents</th><th></th></tr>
      <?php foreach ($fams as $f): $inv = setting("invit_{$f['id']}"); ?>
        <tr<?= $f['actif'] ? '' : ' class="closed"' ?>><td><a href="?p=famille&id=<?= $f['id'] ?>"><b><?= h($f['nom']) ?></b></a><?= $f['actif'] ? '' : ' (archivée)' ?></td>
          <td><?= h($f['enfants'] ?: '—') ?></td><td><?= h($f['tel']) ?><br><span class="muted"><?= h($f['email']) ?></span></td>
          <td><?php if ($f['actif']): ?>
            <?= $inv ? '<span class="muted">invitée le ' . h(date('d/m', strtotime($inv))) . '</span><br>' : '' ?>
            <?php if ($inv || $f['derniere_visite']): ?><?= derniere_visite_fr($f['derniere_visite']) ?><br><?php endif ?>
            <?php if ($inv && !$f['derniere_visite'] && ($wa = lien_whatsapp_relance($f))): ?>
              <a class="btn small" href="<?= h($wa) ?>" target="_blank" rel="noopener" style="background:#25d366;margin:.2rem 0">📱 Relancer par WhatsApp</a><br>
            <?php endif ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>">
              <button class="light small" name="act" value="inviter" data-confirm="Envoyer l'invitation à <?= h($f['email']) ?> ? Un nouveau mot de passe sera créé."><?= $inv ? 'réinviter' : 'inviter' ?></button></form>
            <a class="muted" href="?p=apercu_invit&id=<?= $f['id'] ?>" target="_blank">aperçu</a>
          <?php endif ?></td>
          <td style="white-space:nowrap"><a href="parent.php?f=<?= $f['id'] ?>">Réservations</a><br>
            <a class="muted" href="?p=voir_comme&id=<?= $f['id'] ?>">👀 voir comme les parents</a></td></tr>
      <?php endforeach ?>
      <?php if (!$fams): ?><tr><td colspan="5" class="muted">Aucune famille. Ajoutez-en une ci-dessous.</td></tr><?php endif ?>
    </table></div>
    <h2 id="nouvelle">Nouvelle famille</h2>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <div class="grid2">
        <label>Nom (ex. Famille Martin)<input name="nom" required value="<?= h($_GET['nom'] ?? '') ?>"></label>
        <label>Email de connexion<input type="email" name="email" required value="<?= h($_GET['email'] ?? '') ?>"></label>
        <label>Téléphone<input name="tel" value="<?= h($_GET['tel'] ?? '') ?>"></label>
        <label>Adresse (pour la facture)<textarea name="adresse"></textarea></label>
      </div>
      <button>Créer la famille</button> <span class="muted">Un mot de passe sera généré automatiquement.</span>
    </form>
    <?php
    break;

case 'famille':
    $id = (int)($_GET['id'] ?? 0);
    $f = q('SELECT * FROM familles WHERE id = ?', [$id])->fetch();
    if (!$f) redirect('admin.php?p=familles');
    if (post()) {
        $act = $_POST['act'] ?? '';
        if ($act === 'famille') {
            $email = strtolower($P('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $P('nom') === '') {
                flash('Nom et email valides obligatoires.', 'err');
            } elseif ($email === setting('admin_email') || q('SELECT 1 FROM familles WHERE email = ? AND id <> ?', [$email, $id])->fetch()) {
                flash('Cet email est déjà utilisé.', 'err');
            } else {
                q('UPDATE familles SET nom = ?, email = ?, tel = ?, adresse = ?, actif = ? WHERE id = ?',
                    [$P('nom'), $email, $P('tel'), $P('adresse'), isset($_POST['actif']) ? 1 : 0, $id]);
                flash('Famille enregistrée.');
            }
        } elseif ($act === 'mdp') {
            $mdp = mot_de_passe_aleatoire();
            q('UPDATE familles SET pass = ? WHERE id = ?', [password_hash($mdp, PASSWORD_DEFAULT), $id]);
            flash("Nouveau mot de passe : $mdp (à transmettre aux parents, il ne sera plus affiché).");
        } elseif ($act === 'suppr_famille') {
            foreach (q('SELECT id FROM enfants WHERE famille_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $eid) supprimer_photos_enfant((int)$eid);
            q('DELETE FROM familles WHERE id = ?', [$id]);
            flash('Famille supprimée.');
            redirect('admin.php?p=familles');
        } elseif ($act === 'enfant') {
            $eid = (int)($_POST['eid'] ?? 0);
            $th = str_replace(',', '.', $P('tarif_horaire'));
            $vals = [$P('prenom'), $P('nom'), $P('naissance') ?: null, $P('ecole'), $P('classe'), $P('allergies'), $P('autorises'), $P('urgence'), $P('notes'),
                     is_numeric($th) ? (float)$th : null, isset($_POST['tarif_special']) ? 1 : 0, isset($_POST['sans_repas']) ? 1 : 0,
                     isset($_POST['cantine']) ? 1 : 0];
            if ($P('prenom') === '') {
                flash('Le prénom est obligatoire.', 'err');
            } elseif ($eid) {
                q('UPDATE enfants SET prenom = ?, nom = ?, naissance = ?, ecole = ?, classe = ?, allergies = ?, autorises = ?, urgence = ?, notes = ?,
                   tarif_horaire = ?, tarif_special = ?, sans_repas = ?, cantine = ?, actif = ?
                   WHERE id = ? AND famille_id = ?', [...$vals, isset($_POST['actif']) ? 1 : 0, $eid, $id]);
                flash('Fiche enfant enregistrée.');
            } else {
                q('INSERT INTO enfants (prenom, nom, naissance, ecole, classe, allergies, autorises, urgence, notes, tarif_horaire, tarif_special, sans_repas, cantine, famille_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $id]);
                $eid = (int)db()->lastInsertId();
                flash('Enfant ajouté.');
            }
            if ($eid && $P('prenom') !== '' && q('SELECT 1 FROM enfants WHERE id = ? AND famille_id = ?', [$eid, $id])->fetch()) {
                if (isset($_POST['suppr_photo'])) supprimer_photo_enfant($eid);
                elseif ($err = enregistrer_photo_enfant($eid, $_FILES['photo'] ?? [])) flash($err, 'err');
                foreach ((array)($_POST['suppr_fond'] ?? []) as $nom) supprimer_photo_fond($eid, (string)$nom);
                [$nf, $errs] = ajouter_photos_fond($eid, $_FILES['fonds'] ?? []);
                if ($nf) flash("$nf photo(s) de fond ajoutée(s).");
                foreach ($errs as $e) flash($e, 'err');
            }
        } elseif ($act === 'suppr_enfant') {
            q('DELETE FROM enfants WHERE id = ? AND famille_id = ?', [(int)$_POST['eid'], $id]);
            supprimer_photos_enfant((int)$_POST['eid']);
            flash('Enfant supprimé.');
        }
        redirect("admin.php?p=famille&id=$id");
    }
    page_debut($f['nom']);
    $enfants = q('SELECT * FROM enfants WHERE famille_id = ? ORDER BY actif DESC, prenom', [$id])->fetchAll();
    $champsEnfant = function (array $e = []): void { ?>
        <div class="row" style="align-items:center;margin-bottom:.6rem">
          <span class="apercu-profil"><?= !empty($e['id']) ? avatar($e, 72) : '' ?></span>
          <label style="margin:0">Photo de l'enfant (enregistrée dès qu'elle est choisie)<input type="file" name="photo" accept="image/*"<?= !empty($e['id']) ? ' data-photo-profil' : '' ?>></label>
          <?php if (!empty($e['id']) && is_file(photo_enfant_fichier((int)$e['id']))): ?>
            <label style="margin:0;color:var(--ink)"><input type="checkbox" name="suppr_photo" style="display:inline;width:auto"> Retirer la photo</label>
          <?php endif ?>
        </div>
        <div style="margin-bottom:.6rem">
          <label style="margin:0">Album : photos de fond de l'espace parents (une au hasard à chaque ouverture, <?= PHOTOS_FOND_MAX ?> max).
            Choisissez-en plusieurs, en une ou plusieurs fois, puis Enregistrer.
            <input type="file" name="fonds[]" accept="image/*" multiple data-album="Enregistrer"></label>
          <?= !empty($e['id']) ? vignettes_fond((int)$e['id']) : '' ?>
        </div>
        <div class="grid2">
          <label>Prénom<input name="prenom" value="<?= h($e['prenom'] ?? '') ?>" required></label>
          <label>Nom<input name="nom" value="<?= h($e['nom'] ?? '') ?>"></label>
          <label>Date de naissance<input type="date" name="naissance" value="<?= h($e['naissance'] ?? '') ?>"></label>
          <label>École<input name="ecole" value="<?= h($e['ecole'] ?? '') ?>"></label>
          <label>Classe<input name="classe" value="<?= h($e['classe'] ?? '') ?>"></label>
          <label>Allergies / santé<input name="allergies" value="<?= h($e['allergies'] ?? '') ?>"></label>
          <label>Personnes autorisées à le récupérer<textarea name="autorises"><?= h($e['autorises'] ?? '') ?></textarea></label>
          <label>Contact d'urgence<textarea name="urgence"><?= h($e['urgence'] ?? '') ?></textarea></label>
          <label>Notes (privées)<textarea name="notes"><?= h($e['notes'] ?? '') ?></textarea></label>
          <label>Tarif horaire en semaine (€/h, vide = tarif normal <?= h(number_format(tarif('tarif_semaine'), 2, ',', '')) ?>)
            <input name="tarif_horaire" value="<?= isset($e['tarif_horaire']) && $e['tarif_horaire'] !== null ? h(number_format((float)$e['tarif_horaire'], 2, ',', '')) : '' ?>" inputmode="decimal"></label>
          <div>
            <label><input type="checkbox" name="tarif_special" <?= !empty($e['tarif_special']) ? 'checked' : '' ?> style="display:inline;width:auto"> Tarif spécial repas / goûter (<?= euro(tarif('repas_special')) ?> / <?= euro(tarif('gouter_special')) ?>)</label>
            <label><input type="checkbox" name="sans_repas" <?= !empty($e['sans_repas']) ? 'checked' : '' ?> style="display:inline;width:auto"> Sans repas ni goûter</label>
            <label><input type="checkbox" name="cantine" <?= !empty($e['cantine']) ? 'checked' : '' ?> style="display:inline;width:auto"> Mange à la cantine (pas de créneau midi les jours d'école)</label>
          </div>
        </div>
    <?php };
    ?>
    <p><a class="btn" href="parent.php?f=<?= $id ?>">Gérer les réservations</a> <a class="btn light" href="?p=voir_comme&id=<?= $id ?>">👀 Voir comme les parents</a>
      <?php if ($wa = lien_whatsapp_relance($f)): ?><a class="btn" href="<?= h($wa) ?>" target="_blank" rel="noopener" style="background:#25d366">📱 Message WhatsApp</a><?php endif ?>
      <a class="btn light" href="parent.php?f=<?= $id ?>&v=factures">Factures</a>
      <a class="btn light" href="attestation.php?f=<?= $id ?>&a=<?= date('Y') - 1 ?>">Attestation fiscale</a></p>

    <h2>Enfants</h2>
    <?php foreach ($enfants as $e): ?>
      <details class="card"><summary><?= avatar($e, 32) ?> <b><?= h($e['prenom'] . ' ' . $e['nom']) ?></b><?= $e['actif'] ? '' : ' (inactif)' ?>
        <?= $e['naissance'] ? ' <span class="muted">' . age($e['naissance']) . ' ans</span>' : ' <span class="muted">(date de naissance ?)</span>' ?>
        <?= $e['allergies'] ? ' <span class="badge warn">' . h($e['allergies']) . '</span>' : '' ?></summary>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="enfant"><input type="hidden" name="eid" value="<?= $e['id'] ?>">
          <?php $champsEnfant($e) ?>
          <label><input type="checkbox" name="actif" <?= $e['actif'] ? 'checked' : '' ?> style="display:inline;width:auto"> Actif (décocher s'il ne vient plus)</label>
          <button>Enregistrer</button>
        </form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="suppr_enfant"><input type="hidden" name="eid" value="<?= $e['id'] ?>">
          <p><button class="danger small" data-confirm="Supprimer définitivement <?= h($e['prenom']) ?> et toutes ses réservations ? (Pour garder l'historique, décochez plutôt « Actif ».)">Supprimer</button></p>
        </form>
      </details>
    <?php endforeach ?>
    <details class="card"<?= $enfants ? '' : ' open' ?>><summary><b>+ Ajouter un enfant</b></summary>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="enfant"><?php $champsEnfant() ?><button>Ajouter</button></form>
    </details>

    <h2>Coordonnées</h2>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="famille">
      <div class="grid2">
        <label>Nom<input name="nom" value="<?= h($f['nom']) ?>" required></label>
        <label>Email de connexion<input type="email" name="email" value="<?= h($f['email']) ?>" required></label>
        <label>Téléphone<input name="tel" value="<?= h($f['tel']) ?>"></label>
        <label>Adresse<textarea name="adresse"><?= h($f['adresse']) ?></textarea></label>
      </div>
      <label><input type="checkbox" name="actif" <?= $f['actif'] ? 'checked' : '' ?> style="display:inline;width:auto"> Compte actif (décocher pour bloquer la connexion)</label>
      <button>Enregistrer</button>
    </form>
    <div class="row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="mdp">
        <button class="light" data-confirm="Générer un nouveau mot de passe ? L'ancien ne fonctionnera plus.">Nouveau mot de passe</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="suppr_famille">
        <button class="danger" data-confirm="Supprimer définitivement cette famille, ses enfants, réservations et paiements ?">Supprimer la famille</button></form>
    </div>
    <?php
    break;

// ================= FACTURATION =================
case 'factures':
    $ym = mois_valide($_GET['m'] ?? null);
    $familles = q("SELECT DISTINCT f.id, f.nom, f.email, fa.numero, fa.envoye, fa.relance, pa.date AS paye, pa.mode, pa.montant
                   FROM familles f JOIN enfants e ON e.famille_id = f.id JOIN reservations r ON r.enfant_id = e.id AND r.jour LIKE ?
                   LEFT JOIN factures fa ON fa.famille_id = f.id AND fa.mois = ?
                   LEFT JOIN paiements pa ON pa.famille_id = f.id AND pa.mois = ?
                   ORDER BY f.nom", ["$ym-%", $ym, $ym])->fetchAll();
    if (post()) {
        $act = $_POST['act'] ?? '';
        if ($act === 'emettre') {
            $n = 0;
            foreach ($familles as $f) {
                if ($f['numero'] || calcul_mois((int)$f['id'], $ym)['total'] <= 0) continue;
                emettre_facture((int)$f['id'], $ym);
                $n++;
            }
            flash($n ? "$n facture(s) émise(s) : les parents peuvent maintenant les voir." : 'Aucune nouvelle facture à émettre.');
        } elseif ($act === 'refacturer') {
            // Avoir d'annulation de l'ancienne facture (pour son net), puis nouvelle facture avec un nouveau numéro
            $fid = (int)$_POST['famille'];
            $cf = calcul_facture($fid, $ym);
            if ($cf['facture']) {
                $ancien = (int)$cf['facture']['numero'];
                $av = creer_avoir($fid, $ym, $ancien, 'annulation', $cf['net'], "Annulation de la facture N° $ancien (correction des heures)", $cf['facture']);
                $nouveau = emettre_facture($fid, $ym);
                flash("Avoir AV-$av créé pour annuler la facture N° $ancien ; nouvelle facture N° $nouveau émise. Pensez à l'envoyer à la famille.");
            }
        } elseif ($act === 'avoir') {
            $fid = (int)$_POST['famille'];
            $cf = calcul_facture($fid, $ym);
            $m = (float)str_replace(',', '.', $P('montant_avoir'));
            if (!$cf['facture'] || $m <= 0 || $m > $cf['net'] + 0.001 || $P('motif') === '') {
                flash("Avoir impossible : indiquez un motif et un montant entre 0 et " . euro($cf['net']) . '.', 'err');
            } else {
                $av = creer_avoir($fid, $ym, (int)$cf['facture']['numero'], 'partiel', $m, $P('motif'));
                flash("Avoir AV-$av de " . euro($m) . " créé sur la facture N° {$cf['facture']['numero']}.");
            }
        } elseif ($act === 'payer') {
            q('INSERT OR REPLACE INTO paiements (famille_id, mois, montant, date, mode) VALUES (?, ?, ?, ?, ?)',
                [(int)$_POST['famille'], $ym, (float)str_replace(',', '.', $P('montant')), date_valide($_POST['date'] ?? null), $P('mode')]);
            flash('Paiement enregistré.');
        } elseif ($act === 'annuler') {
            q('DELETE FROM paiements WHERE famille_id = ? AND mois = ?', [(int)$_POST['famille'], $ym]);
            flash('Paiement annulé.');
        } elseif (in_array($act, ['mail', 'relance', 'mail_tous'], true)) {
            $cibles = $act === 'mail_tous'
                ? array_filter($familles, fn($f) => $f['numero'] && !$f['envoye'])
                : array_filter($familles, fn($f) => (int)$f['id'] === (int)$_POST['famille'] && $f['numero']);
            $ok = $ko = [];
            foreach ($cibles as $f) {
                if (mail_facture((int)$f['id'], $ym, $act === 'relance')) $ok[] = $f['nom'];
                else $ko[] = $f['nom'];
            }
            if ($ok) flash(($act === 'relance' ? 'Relance envoyée à : ' : 'Facture envoyée par email à : ') . implode(', ', $ok));
            if ($ko) flash("Échec de l'envoi pour : " . implode(', ', $ko) . " (vérifiez l'email de la famille).", 'err');
            if (!$ok && !$ko) flash('Aucune facture à envoyer.');
        }
        redirect("admin.php?p=factures&m=$ym");
    }
    page_debut('Factures – ' . mois_fr($ym));
    $rows = [];
    foreach ($familles as $f) {
        $cf = calcul_facture((int)$f['id'], $ym);
        $live = $f['numero'] ? calcul_mois((int)$f['id'], $ym)['total'] : $cf['total'];
        $rows[] = $f + $cf + ['live' => $live, 'ecart' => $f['numero'] && abs($live - $cf['total']) > 0.009];
    }
    foreach ($rows as &$r) $r['total'] = $r['net'];
    unset($r);
    $tot = array_sum(array_column($rows, 'total'));
    $enc = array_sum(array_map(fn($r) => $r['paye'] ? (float)$r['montant'] : 0, $rows));
    $aEmettre = count(array_filter($rows, fn($r) => !$r['numero'] && $r['total'] > 0));
    ?>
    <div class="row">
      <a class="btn light small" href="?p=factures&m=<?= mois_decale($ym, -1) ?>">◀</a><b><?= h(mois_fr($ym)) ?></b>
      <a class="btn light small" href="?p=factures&m=<?= mois_decale($ym, 1) ?>">▶</a>
    </div>
    <?php $imp = impayes(); if ($imp): ?>
      <p><a class="btn light small" href="?p=impayes">⚠ Impayés toutes périodes : <?= count($imp) ?> facture(s), <?= euro(array_sum(array_column($imp, 'reste'))) ?></a></p>
    <?php endif ?>
    <p>Total : <b><?= euro($tot) ?></b> · Encaissé : <b><?= euro($enc) ?></b> · Reste : <b><?= euro($tot - $enc) ?></b>
      · Heures : <b><?= duree_fr(array_sum(array_column($rows, 'heures'))) ?></b></p>
    <?php if ($aEmettre): ?>
      <form method="post" class="card"><?= csrf_field() ?>
        <b><?= $aEmettre ?> facture(s) en brouillon.</b> Vérifiez le « Relevé » de chaque famille (heures saisies par les parents, à corriger si besoin), puis émettez-les : elles reçoivent un numéro (à partir du n° <?= h(setting('prochain_numero', '1')) ?>) et deviennent visibles par les parents.
        <p><button name="act" value="emettre" data-confirm="Émettre les factures de <?= h(mois_fr($ym)) ?> ?">Émettre les factures</button></p>
      </form>
    <?php endif;
    $nonEnvoyees = count(array_filter($rows, fn($r) => $r['numero'] && !$r['envoye']));
    if ($nonEnvoyees): ?>
      <form method="post" class="card"><?= csrf_field() ?>
        <b><?= $nonEnvoyees ?> facture(s) émise(s) pas encore envoyée(s) par email.</b>
        Chaque famille reçoit le montant, le détail et le lien pour consulter / imprimer sa facture.
        <p><button name="act" value="mail_tous" data-confirm="Envoyer <?= $nonEnvoyees ?> facture(s) par email aux familles ?">Envoyer les factures par email</button></p>
      </form>
    <?php endif ?>
    <div class="scroll"><table>
      <tr><th>Famille</th><th>N°</th><th class="r">Heures</th><th class="r">Montant</th><th>Paiement</th><th>Email</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= h($r['nom']) ?>
            <?php if ($r['ecart']): ?>
              <div class="flash err" style="margin:.4rem 0 0;padding:.4rem .6rem;font-size:.85rem">Pointage modifié après émission : <?= euro($r['net'] + $r['total_avoirs']) ?> facturé → <?= euro($r['live']) ?> recalculé.
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= $r['id'] ?>">
                  <button class="small" name="act" value="refacturer" data-confirm="Créer un avoir qui annule la facture N° <?= (int)$r['numero'] ?> et émettre une nouvelle facture de <?= euro($r['live']) ?> ?">Annuler et refacturer</button></form></div>
            <?php endif ?></td>
          <td><?= $r['numero'] ? (int)$r['numero'] : '<span class="badge full">brouillon</span>' ?></td>
          <td class="r"><?= duree_fr($r['heures']) ?></td>
          <td class="r"><?= euro($r['net']) ?><?= $r['total_avoirs'] > 0 ? '<br><span class="muted">dont avoir −' . euro($r['total_avoirs']) . '</span>' : '' ?>
            <?php if ($r['numero'] && !$r['paye']): ?>
              <details><summary class="muted" style="font-size:.8rem">avoir…</summary>
                <form method="post" style="margin-top:.3rem"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= $r['id'] ?>">
                  <input name="montant_avoir" placeholder="montant €" size="7" inputmode="decimal" required>
                  <input name="motif" placeholder="motif (ex. absence remboursée)" required>
                  <button class="light small" name="act" value="avoir" data-confirm="Créer cet avoir ?">Créer l'avoir</button></form></details>
            <?php endif ?></td>
          <td><?php if ($r['paye']): ?>
              <span class="badge">Payé <?= euro($r['montant']) ?> le <?= h(date('d/m/Y', strtotime($r['paye']))) ?><?= $r['mode'] ? ' (' . h($r['mode']) . ')' : '' ?></span>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= $r['id'] ?>">
                <button class="light small" name="act" value="annuler">annuler</button></form>
            <?php else: ?>
              <form method="post" class="row" style="margin:0"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= $r['id'] ?>">
                <input name="montant" value="<?= h(number_format((float)$r['total'], 2, ',', '')) ?>" size="7" aria-label="Montant">
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" aria-label="Date">
                <select name="mode" aria-label="Mode"><option>Virement</option><option>Chèque</option><option>Espèces</option><option>CESU</option></select>
                <button class="small" name="act" value="payer">Marquer payé</button></form>
            <?php endif ?></td>
          <td><?php if ($r['numero']): ?>
            <?php if ($r['envoye']): ?><span class="muted">envoyée le <?= h(date('d/m', strtotime($r['envoye']))) ?></span><br><?php endif ?>
            <?php if ($r['relance']): ?><span class="muted">relancée le <?= h(date('d/m', strtotime($r['relance']))) ?></span><br><?php endif ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= $r['id'] ?>">
              <?php if (!$r['envoye']): ?>
                <button class="light small" name="act" value="mail" data-confirm="Envoyer la facture à <?= h($r['email']) ?> ?">envoyer</button>
              <?php elseif (!$r['paye']): ?>
                <button class="light small" name="act" value="relance" data-confirm="Envoyer un rappel de paiement à <?= h($r['email']) ?> ?">relancer</button>
              <?php endif ?>
            </form>
          <?php else: ?><span class="muted">—</span><?php endif ?></td>
          <td style="white-space:nowrap"><a href="?p=releve&f=<?= $r['id'] ?>&m=<?= h($ym) ?>">Relevé</a> · <a href="facture.php?f=<?= $r['id'] ?>&m=<?= h($ym) ?>">Facture</a></td></tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Aucune réservation ce mois-ci.</td></tr><?php endif ?>
    </table></div>
    <?php $avoirs = q('SELECT a.*, f.nom FROM avoirs a JOIN familles f ON f.id = a.famille_id WHERE a.mois = ? ORDER BY a.numero', [$ym])->fetchAll();
    if ($avoirs): ?>
      <h2>Avoirs de <?= h(mois_fr($ym)) ?></h2>
      <div class="scroll"><table>
        <tr><th>N°</th><th>Famille</th><th>Sur facture</th><th>Motif</th><th class="r">Montant</th><th></th></tr>
        <?php foreach ($avoirs as $a): ?>
          <tr><td>AV-<?= (int)$a['numero'] ?></td><td><?= h($a['nom']) ?></td><td>N° <?= (int)$a['facture_numero'] ?></td>
            <td><?= h($a['motif']) ?><?= $a['type'] === 'annulation' ? ' <span class="badge full">annulation</span>' : '' ?></td>
            <td class="r">−<?= euro($a['montant']) ?></td><td><a href="avoir.php?id=<?= (int)$a['id'] ?>">Voir / imprimer</a></td></tr>
        <?php endforeach ?>
      </table></div>
    <?php endif ?>
    <p class="muted">Une facture émise est figée : si le pointage change ensuite, utilisez « Annuler et refacturer ». Pour un geste commercial ou une absence remboursée, faites un avoir.</p>
    <?php
    break;

// ================= RELEVÉ DES HEURES DU MOIS D'UNE FAMILLE (à vérifier avant d'émettre la facture) =================
case 'releve':
    $ym = mois_valide($_GET['m'] ?? null);
    $fid = (int)($_GET['f'] ?? 0);
    $fam = q('SELECT * FROM familles WHERE id = ?', [$fid])->fetch();
    if (!$fam) redirect("admin.php?p=factures&m=$ym");
    $retour = "admin.php?p=releve&f=$fid&m=$ym";
    if (post()) {
        if (isset($_POST['suppr'])) {
            q('DELETE FROM reservations WHERE id = ? AND enfant_id IN (SELECT id FROM enfants WHERE famille_id = ?)', [(int)$_POST['suppr'], $fid]);
            flash('Ligne supprimée.');
        } elseif (($_POST['act'] ?? '') === 'ajout') {
            $d = date_valide($_POST['jour'] ?? null);
            $pr = q('SELECT * FROM prestations WHERE id = ?', [(int)$_POST['prestation']])->fetch();
            $enf = q('SELECT id FROM enfants WHERE id = ? AND famille_id = ?', [(int)$_POST['enfant'], $fid])->fetchColumn();
            if ($pr && $enf && str_starts_with($d, $ym)) {
                reserver((int)$enf, $pr, $d);
                $ha = heure_valide($_POST['arrivee'] ?? null); $hd = heure_valide($_POST['depart'] ?? null);
                if ($ha || $hd) q('UPDATE reservations SET arrivee = ?, depart = ? WHERE enfant_id = ? AND prestation_id = ? AND jour = ?', [$ha, $hd, $enf, $pr['id'], $d]);
                flash('Ligne ajoutée.');
            } else flash("Ajout impossible : choisissez l'enfant, une date de " . mois_fr($ym) . ' et le créneau.', 'err');
        } else {
            foreach ($_POST['pt'] ?? [] as $rid => $v) {
                $pres = $v['present'] ?? '';
                q('UPDATE reservations SET arrivee = ?, depart = ?, present = ?, repas = ?, gouter = ?, petitdej = ?, nuit = ?
                   WHERE id = ? AND enfant_id IN (SELECT id FROM enfants WHERE famille_id = ?)',
                    [heure_valide($v['arrivee'] ?? null), heure_valide($v['depart'] ?? null), $pres === '' ? null : (int)$pres,
                     isset($v['repas']) ? 1 : 0, isset($v['gouter']) ? 1 : 0, isset($v['petitdej']) ? 1 : 0, isset($v['nuit']) ? 1 : 0, (int)$rid, $fid]);
            }
            flash('Relevé enregistré.');
        }
        redirect($retour);
    }
    page_debut('Relevé des heures – ' . $fam['nom'] . ' – ' . mois_fr($ym));
    $rows = q('SELECT r.*, e.prenom, e.sans_repas, p.libelle, p.debut AS p_debut, p.fin AS p_fin
               FROM reservations r JOIN enfants e ON e.id = r.enfant_id JOIN prestations p ON p.id = r.prestation_id
               WHERE e.famille_id = ? AND r.jour LIKE ? ORDER BY r.jour, p.ordre, e.prenom', [$fid, "$ym-%"])->fetchAll();
    $calc = calcul_mois($fid, $ym);
    $parJour = [];
    foreach ($calc['jours'] as $j) $parJour[$j['jour']][] = $j['prenom'] . ' ' . $j['horaires'] . ' = ' . duree_fr($j['heures'])
        . (!empty($j['heures_nuit']) ? ' dont nuit ' . duree_fr($j['heures_nuit']) : '');
    $facture = q('SELECT numero FROM factures WHERE famille_id = ? AND mois = ?', [$fid, $ym])->fetchColumn();
    $enfants = q('SELECT id, prenom FROM enfants WHERE famille_id = ? AND actif = 1 ORDER BY prenom', [$fid])->fetchAll();
    ?>
    <style>
      .rl { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .8rem; padding: .45rem 0; border-top: 1px solid var(--line); }
      .rl label { margin: 0; }
      .rl .coche { display: inline-flex; align-items: center; gap: .25rem; color: var(--ink); }
      .rl .coche input { width: 1.1rem; height: 1.1rem; }
    </style>
    <div class="row">
      <a class="btn light small" href="?p=releve&f=<?= $fid ?>&m=<?= mois_decale($ym, -1) ?>">◀</a><b><?= h(mois_fr($ym)) ?></b>
      <a class="btn light small" href="?p=releve&f=<?= $fid ?>&m=<?= mois_decale($ym, 1) ?>">▶</a>
      <a class="btn light small" href="?p=factures&m=<?= h($ym) ?>">← Factures</a>
    </div>
    <div class="card">
      <b><?= duree_fr($calc['heures']) ?></b> de garde · <b><?= euro($calc['total']) ?></b>
      <span class="muted">(garde <?= euro($calc['total_garde']) ?> + repas/goûters <?= euro($calc['total_extras']) ?>)</span>
      <?php if ($facture): ?>
        <div class="flash err" style="margin:.5rem 0 0">Facture N° <?= (int)$facture ?> déjà émise : si vous changez des heures ici, utilisez ensuite « Annuler et refacturer » dans Factures.</div>
      <?php else: ?>
        <p class="muted" style="margin:.4rem 0 0">Heures tapées par les parents (ou horaires du créneau) déjà reprises. Corrigez seulement ce qui a changé, puis émettez la facture depuis Factures.</p>
      <?php endif ?>
    </div>
    <form method="post"><?= csrf_field() ?>
      <?php $jourPrec = null; foreach ($rows as $r): $id = (int)$r['id'];
        if ($r['jour'] !== $jourPrec): $jourPrec = $r['jour']; ?>
          <h3 style="margin:1rem 0 .2rem"><?= JOURS_COURTS[(int)date('N', strtotime($r['jour']))] . ' ' . date('d/m', strtotime($r['jour'])) ?>
            <span class="muted" style="font-weight:normal;font-size:.85rem"><?= h(implode(' · ', $parJour[$r['jour']] ?? ['non facturé'])) ?></span></h3>
        <?php endif ?>
        <div class="rl"<?= $r['present'] !== null && (int)$r['present'] === 0 ? ' style="opacity:.55"' : '' ?>>
          <span style="min-width:11rem"><b><?= h($r['prenom']) ?></b> · <?= h($r['libelle']) ?><br>
            <span class="muted">prévu <?= h(substr($r['prevu_debut'] ?: $r['p_debut'], 0, 5) . '–' . substr($r['prevu_fin'] ?: $r['p_fin'], 0, 5)) ?><?= $r['prevu_debut'] ? ' (parents)' : '' ?></span></span>
          <label>Arrivée<input type="time" name="pt[<?= $id ?>][arrivee]" value="<?= h($r['arrivee']) ?>"></label>
          <label>Départ<input type="time" name="pt[<?= $id ?>][depart]" value="<?= h($r['depart']) ?>"></label>
          <label class="coche"><input type="checkbox" name="pt[<?= $id ?>][nuit]" <?= !empty($r['nuit']) ? 'checked' : '' ?>> 🌙 nuit (départ le lendemain)</label>
          <?php if ($r['sans_repas']): ?><span class="muted">sans repas</span><?php else:
            foreach (['petitdej' => 'P.déj', 'repas' => 'Repas', 'gouter' => 'Goûter'] as $x => $lib): ?>
              <label class="coche"><input type="checkbox" name="pt[<?= $id ?>][<?= $x ?>]" <?= $r[$x] ? 'checked' : '' ?>> <?= $lib ?></label>
          <?php endforeach; endif ?>
          <select name="pt[<?= $id ?>][present]" aria-label="Présence">
            <option value="" <?= $r['present'] === null ? 'selected' : '' ?>>—</option>
            <option value="1" <?= $r['present'] !== null && (int)$r['present'] === 1 ? 'selected' : '' ?>>Présent</option>
            <option value="0" <?= $r['present'] !== null && (int)$r['present'] === 0 ? 'selected' : '' ?>>Absent</option>
          </select>
          <button class="danger small" name="suppr" value="<?= $id ?>" data-confirm="Supprimer cette ligne ?">✕</button>
        </div>
      <?php endforeach ?>
      <?php if (!$rows): ?><p class="card muted">Aucune garde ce mois-ci.</p><?php else: ?>
        <p style="margin-top:1rem"><button>Enregistrer le relevé</button>
          <span class="muted">Arrivée / départ vides = heures prévues. « Absent » = non facturé.</span></p>
      <?php endif ?>
    </form>
    <?php if ($enfants): ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="ajout">
      <b>Ajouter une garde oubliée</b>
      <div class="row" style="margin-top:.5rem">
        <input type="date" name="jour" min="<?= h($ym) ?>-01" max="<?= date('Y-m-t', strtotime("$ym-01")) ?>" required aria-label="Date">
        <select name="enfant" aria-label="Enfant"><?php foreach ($enfants as $e): ?><option value="<?= $e['id'] ?>"><?= h($e['prenom']) ?></option><?php endforeach ?></select>
        <select name="prestation" aria-label="Créneau"><?php foreach (prestations() as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['libelle']) ?></option><?php endforeach ?></select>
        <input type="time" name="arrivee" aria-label="Arrivée" title="Arrivée (optionnel)">
        <input type="time" name="depart" aria-label="Départ" title="Départ (optionnel)">
        <button>Ajouter</button>
      </div>
    </form>
    <?php endif;
    break;

// ================= APERÇU DE L'EMAIL D'INVITATION (rien n'est envoyé) =================
case 'apercu_invit':
    $fam = q('SELECT * FROM familles WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$fam) redirect('admin.php?p=familles');
    $html = mail_html(texte_invitation($fam, '(mot de passe créé au moment de l\'envoi)'));
    echo str_replace('<body style="', '<body style="padding-top:0;', preg_replace('#<body([^>]*)>#', '<body$1><div style="background:#fffbe6;border-bottom:2px solid #e0b000;padding:10px 16px;font-family:Arial,sans-serif;font-size:14px">'
        . '<b>Aperçu</b> de l\'email que recevra <b>' . h($fam['nom']) . '</b> (' . h($fam['email']) . ') — objet : « Votre espace parents – ' . h(nom_affiche()) . ' ». Rien n\'est envoyé. '
        . '<a href="admin.php?p=familles">← Retour</a></div>', $html, 1));
    exit;

// ================= DEVIS / CONTRATS =================
case 'contrats':
    if (post()) {
        $fid = (int)($_POST['famille'] ?? 0);
        if (!q('SELECT 1 FROM familles WHERE id = ?', [$fid])->fetch()) { flash('Choisissez une famille.', 'err'); redirect('admin.php?p=contrats'); }
        $num = max((int)setting('prochain_contrat', '111'), (int)q('SELECT COALESCE(MAX(numero), 0) + 1 FROM contrats')->fetchColumn());
        set_setting('prochain_contrat', (string)($num + 1));
        $extras = [];
        foreach (['petitdej', 'repas', 'gouter'] as $x) $extras[$x] = ['qte' => 0, 'pu' => tarif($x), 'dates' => ''];
        q('INSERT INTO contrats (numero, type, famille_id, titre, lignes, extras, conditions, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$num, $_POST['type'] === 'devis' ? 'devis' : 'contrat', $fid, $P('titre'), '[]', json_encode($extras), CONDITIONS_DEFAUT, date('Y-m-d')]);
        redirect('admin.php?p=contrat&id=' . db()->lastInsertId());
    }
    page_debut('Devis et contrats');
    $liste = q('SELECT c.*, f.nom FROM contrats c JOIN familles f ON f.id = c.famille_id ORDER BY c.numero DESC')->fetchAll();
    ?>
    <form method="post" class="card"><?= csrf_field() ?>
      <b>Nouveau document</b>
      <div class="row" style="margin:.5rem 0 0">
        <select name="type"><option value="contrat">Contrat</option><option value="devis">Devis</option></select>
        <select name="famille" required><option value="">Famille…</option>
          <?php foreach (q('SELECT id, nom FROM familles WHERE actif = 1 ORDER BY nom') as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['nom']) ?></option><?php endforeach ?></select>
        <input name="titre" placeholder="ex. Garderie Louis juillet – août 2026" style="flex:1;min-width:220px">
        <button>Créer</button>
      </div>
      <p class="muted">Créez d'abord la famille dans « Familles » si c'est une nouvelle famille.</p>
    </form>
    <div class="scroll"><table>
      <tr><th>N°</th><th>Type</th><th>Famille</th><th>Objet</th><th class="r">Montant</th><th>Statut</th><th></th></tr>
      <?php foreach ($liste as $c): ?>
        <tr><td><?= (int)$c['numero'] ?></td><td><?= $c['type'] === 'devis' ? 'Devis' : 'Contrat' ?></td><td><?= h($c['nom']) ?></td><td><?= h($c['titre']) ?></td>
          <td class="r"><?= euro(calcul_contrat($c)['total']) ?></td>
          <td><span class="badge<?= $c['statut'] === 'accepte' ? '' : ($c['statut'] === 'envoye' ? ' warn' : ' full') ?>"><?= h(STATUTS_CONTRAT[$c['statut']] ?? $c['statut']) ?></span>
            <?= $c['statut'] === 'accepte' ? '<br><span class="muted">le ' . h(date('d/m/Y', strtotime($c['accepte_le']))) . '</span>' : '' ?></td>
          <td><a href="?p=contrat&id=<?= $c['id'] ?>">ouvrir</a></td></tr>
      <?php endforeach ?>
      <?php if (!$liste): ?><tr><td colspan="7" class="muted">Aucun devis ni contrat.</td></tr><?php endif ?>
    </table></div>
    <?php
    break;

case 'contrat':
    $c = q('SELECT * FROM contrats WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$c) redirect('admin.php?p=contrats');
    $fam = q('SELECT * FROM familles WHERE id = ?', [$c['famille_id']])->fetch();
    $enfants = q('SELECT * FROM enfants WHERE famille_id = ? ORDER BY actif DESC, prenom', [$c['famille_id']])->fetchAll();
    $parPrenom = array_column($enfants, null, 'prenom');
    $url = 'admin.php?p=contrat&id=' . $c['id'];
    if (post()) {
        $act = $_POST['act'] ?? 'save';
        if ($act === 'suppr') {
            q('DELETE FROM contrats WHERE id = ?', [$c['id']]);
            flash('Document supprimé.');
            redirect('admin.php?p=contrats');
        }
        if ($act === 'dupliquer') {
            $num = max((int)setting('prochain_contrat', '111'), (int)q('SELECT COALESCE(MAX(numero), 0) + 1 FROM contrats')->fetchColumn());
            set_setting('prochain_contrat', (string)($num + 1));
            q('INSERT INTO contrats (numero, type, famille_id, titre, lignes, extras, conditions, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$num, $c['type'], $c['famille_id'], $c['titre'] . ' (copie)', $c['lignes'], $c['extras'], $c['conditions'], date('Y-m-d')]);
            flash("Copie créée (n° $num).");
            redirect('admin.php?p=contrat&id=' . db()->lastInsertId());
        }
        if ($act === 'brouillon' && $c['statut'] === 'envoye') {
            q("UPDATE contrats SET statut = 'brouillon' WHERE id = ?", [$c['id']]);
            flash('Le document repasse en brouillon : les parents ne le voient plus.');
            redirect($url);
        }
        if ($c['statut'] !== 'brouillon') redirect($url); // un document envoyé ou accepté ne se modifie plus

        // Lignes saisies
        $lignes = [];
        foreach ($_POST['l'] ?? [] as $l) {
            $jour = trim((string)($l['jour'] ?? ''));
            if ($jour === '' && empty($l['debut'])) continue;
            $lignes[] = ['jour' => $jour, 'enfant' => (string)($l['enfant'] ?? ''), 'debut' => (string)($l['debut'] ?? ''),
                         'fin' => (string)($l['fin'] ?? ''), 'tarif' => (float)str_replace(',', '.', (string)($l['tarif'] ?? 0))];
        }
        $extras = [];
        foreach (['petitdej', 'repas', 'gouter'] as $x) {
            $e = $_POST['x'][$x] ?? [];
            $extras[$x] = ['qte' => max(0, (int)($e['qte'] ?? 0)), 'pu' => (float)str_replace(',', '.', (string)($e['pu'] ?? tarif($x))), 'dates' => trim((string)($e['dates'] ?? ''))];
        }
        // Générateur de jours : du … au …, jours cochés, mêmes horaires
        $sd = $_POST['s'] ?? [];
        if (!empty($sd['du']) && !empty($sd['jours']) && !empty($sd['debut']) && !empty($sd['fin'])) {
            $du = date_valide($sd['du']);
            $au = min(max($du, date_valide(($sd['au'] ?? '') ?: $du)), date('Y-m-d', strtotime("$du +1 year")));
            $joursOk = array_map('intval', array_keys($sd['jours']));
            $enf = $parPrenom[$sd['enfant'] ?? ''] ?? null;
            $ferme = q('SELECT jour FROM fermetures WHERE jour BETWEEN ? AND ?', [$du, $au])->fetchAll(PDO::FETCH_COLUMN);
            $n = 0;
            $datesExtras = [];
            for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                if (!in_array((int)date('N', strtotime($d)), $joursOk, true) || in_array($d, $ferme, true)) continue;
                if (empty($sd['feries']) && isset(jours_feries((int)substr($d, 0, 4))[$d])) continue;
                $tarif = trim((string)($sd['tarif'] ?? '')) !== '' ? (float)str_replace(',', '.', $sd['tarif']) : tarif_horaire($enf ?: [], $d);
                $lignes[] = ['jour' => date('d/m/Y', strtotime($d)), 'enfant' => $enf['prenom'] ?? '', 'debut' => $sd['debut'], 'fin' => $sd['fin'], 'tarif' => $tarif];
                $datesExtras[] = date('j/m', strtotime($d));
                $n++;
            }
            foreach (['petitdej', 'repas', 'gouter'] as $x) {
                if (empty($sd[$x]) || !$n || !empty($enf['sans_repas'])) continue;
                $extras[$x]['qte'] += $n;
                $extras[$x]['dates'] = trim($extras[$x]['dates'] . ' ' . implode(', ', $datesExtras));
                if (!empty($enf['tarif_special']) && $x !== 'petitdej') $extras[$x]['pu'] = tarif("{$x}_special");
            }
            flash("$n jour(s) ajouté(s).");
        }
        q('UPDATE contrats SET type = ?, titre = ?, date = ?, lignes = ?, extras = ?, conditions = ? WHERE id = ?',
            [$_POST['type'] === 'devis' ? 'devis' : 'contrat', $P('titre'), date_valide($_POST['date'] ?? null),
             json_encode($lignes, JSON_UNESCAPED_UNICODE), json_encode($extras, JSON_UNESCAPED_UNICODE), $P('conditions'), $c['id']]);
        if ($act === 'envoyer') {
            $c = q('SELECT * FROM contrats WHERE id = ?', [$c['id']])->fetch();
            if (!json_decode($c['lignes'], true)) { flash('Ajoutez au moins une ligne avant d\'envoyer.', 'err'); redirect($url); }
            q("UPDATE contrats SET statut = 'envoye', envoye = datetime('now', 'localtime') WHERE id = ?", [$c['id']]);
            flash(mail_contrat($c) ? "Envoyé par email à {$fam['email']} : la famille peut le lire et donner son accord en ligne."
                                   : "Document visible par la famille dans « Contrats », mais l'email n'a pas pu partir.", 'ok');
        } else {
            flash('Enregistré.');
        }
        redirect($url);
    }
    $doc = $c['type'] === 'devis' ? 'Devis' : 'Contrat';
    page_debut("$doc n° {$c['numero']} – {$fam['nom']}");
    $k = calcul_contrat($c);
    $ex = json_decode((string)$c['extras'], true) ?: [];
    $modif = $c['statut'] === 'brouillon';
    ?>
    <div class="row">
      <span class="badge<?= $c['statut'] === 'accepte' ? '' : ($c['statut'] === 'envoye' ? ' warn' : ' full') ?>"><?= h(STATUTS_CONTRAT[$c['statut']]) ?></span>
      <?php if ($c['statut'] === 'accepte'): ?><span>Bon pour accord le <?= h(date('d/m/Y à H:i', strtotime($c['accepte_le']))) ?> par <b><?= h($c['accepte_par']) ?></b></span><?php endif ?>
      <?php if ($c['statut'] === 'envoye'): ?><span class="muted">envoyé le <?= h(date('d/m/Y', strtotime($c['envoye']))) ?>, en attente de l'accord de la famille</span><?php endif ?>
      <a class="btn light small" href="contrat.php?id=<?= $c['id'] ?>" target="_blank">Voir / imprimer</a>
    </div>
    <p class="total">Total : <?= euro($k['total']) ?> <span class="muted">(<?= duree_fr($k['heures']) ?> de garde = <?= euro($k['total_garde']) ?> + repas/goûters <?= euro($k['total_extras']) ?>)</span></p>

    <form method="post"><?= csrf_field() ?>
    <fieldset <?= $modif ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
      <div class="card grid2">
        <label>Type<select name="type"><option value="contrat">Contrat</option><option value="devis" <?= $c['type'] === 'devis' ? 'selected' : '' ?>>Devis</option></select></label>
        <label>Date<input type="date" name="date" value="<?= h($c['date']) ?>"></label>
        <label style="grid-column:1/-1">Objet<input name="titre" value="<?= h($c['titre']) ?>" placeholder="ex. Garderie Louis juillet – août 2026"></label>
      </div>

      <h2>Jours de garde</h2>
      <div class="scroll"><table>
        <tr><th>Jour</th><th>Enfant</th><th>Début</th><th>Fin</th><th>Tarif €/h</th><th class="r">Durée</th><th class="r">Total</th><th></th></tr>
        <?php $lignes = $k['lignes'];
        if ($modif) for ($i = 0; $i < 3; $i++) $lignes[] = ['jour' => '', 'enfant' => $enfants[0]['prenom'] ?? '', 'debut' => '', 'fin' => '', 'tarif' => tarif('tarif_semaine'), 'heures' => 0, 'total' => 0, 'vide' => true];
        foreach ($lignes as $i => $l): ?>
          <tr>
            <td><input name="l[<?= $i ?>][jour]" value="<?= h($l['jour']) ?>" placeholder="<?= empty($l['vide']) ? '' : 'ex. 08/07/2026 ou Chaque mercredi' ?>" size="16"></td>
            <td><select name="l[<?= $i ?>][enfant]"><?php foreach ($enfants as $e): ?><option <?= $e['prenom'] === $l['enfant'] ? 'selected' : '' ?>><?= h($e['prenom']) ?></option><?php endforeach ?></select></td>
            <td><input type="time" name="l[<?= $i ?>][debut]" value="<?= h($l['debut']) ?>"></td>
            <td><input type="time" name="l[<?= $i ?>][fin]" value="<?= h($l['fin']) ?>"></td>
            <td><input name="l[<?= $i ?>][tarif]" value="<?= h(number_format((float)$l['tarif'], 2, ',', '')) ?>" size="5" inputmode="decimal"></td>
            <td class="r"><?= empty($l['vide']) ? duree_fr($l['heures']) : '' ?></td>
            <td class="r"><?= empty($l['vide']) ? euro($l['total']) : '' ?></td>
            <td><?php if (empty($l['vide']) && $modif): ?><button type="button" class="light small" onclick="this.closest('tr').querySelectorAll('input').forEach(i => i.value = '')" title="Vider la ligne (puis Enregistrer)">✕</button><?php endif ?></td>
          </tr>
        <?php endforeach ?>
      </table></div>

      <?php if ($modif): ?>
      <details class="card" open><summary><b>Ajouter plusieurs jours d'un coup</b> <span class="muted">(vacances, tous les mercredis…)</span></summary>
        <div class="grid2" style="margin-top:.5rem">
          <label>Du<input type="date" name="s[du]"></label>
          <label>Au<input type="date" name="s[au]"></label>
          <label>Enfant<select name="s[enfant]"><?php foreach ($enfants as $e): ?><option><?= h($e['prenom']) ?></option><?php endforeach ?></select></label>
          <label>Début<input type="time" name="s[debut]"></label>
          <label>Fin<input type="time" name="s[fin]"></label>
          <label>Tarif €/h (vide = tarif de l'enfant / week-end / férié)<input name="s[tarif]" inputmode="decimal"></label>
        </div>
        <div><?php foreach ([1, 2, 3, 4, 5, 6, 7] as $j): ?>
          <label style="display:inline-block;margin-right:.8rem;color:var(--ink)"><input type="checkbox" name="s[jours][<?= $j ?>]" <?= $j <= 5 ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> <?= JOURS_COURTS[$j] ?></label>
        <?php endforeach ?></div>
        <div style="margin-top:.4rem"><?php foreach (['petitdej' => 'petit-déjeuner', 'repas' => 'repas', 'gouter' => 'goûter'] as $x => $lib): ?>
          <label style="display:inline-block;margin-right:.8rem;color:var(--ink)"><input type="checkbox" name="s[<?= $x ?>]" <?= $x !== 'petitdej' ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> + <?= $lib ?> chaque jour</label>
        <?php endforeach ?>
          <label style="display:inline-block;color:var(--ink)"><input type="checkbox" name="s[feries]" style="display:inline;width:auto;height:auto"> inclure les jours fériés</label></div>
        <p class="muted">Les jours de fermeture sont sautés. Cliquez sur « Enregistrer » pour ajouter les jours.</p>
      </details>
      <?php endif ?>

      <h2>Repas / goûter / petit-déjeuner</h2>
      <div class="scroll"><table>
        <tr><th>Prestation</th><th>Quantité</th><th>Prix unit. €</th><th>Dates</th></tr>
        <?php foreach (['petitdej' => 'Petit-déjeuner', 'repas' => 'Repas', 'gouter' => 'Goûter'] as $x => $lib): $e = $ex[$x] ?? ['qte' => 0, 'pu' => tarif($x), 'dates' => '']; ?>
          <tr><td><?= $lib ?></td>
            <td><input type="number" min="0" name="x[<?= $x ?>][qte]" value="<?= (int)$e['qte'] ?>" style="width:5rem"></td>
            <td><input name="x[<?= $x ?>][pu]" value="<?= h(number_format((float)$e['pu'], 2, ',', '')) ?>" size="5" inputmode="decimal"></td>
            <td><input name="x[<?= $x ?>][dates]" value="<?= h($e['dates']) ?>" style="width:100%"></td></tr>
        <?php endforeach ?>
      </table></div>

      <label style="margin-top:1rem">Conditions<textarea name="conditions" rows="4"><?= h($c['conditions']) ?></textarea></label>
      <?php if ($modif): ?>
        <div class="row">
          <button name="act" value="save">Enregistrer</button>
          <button name="act" value="envoyer" class="light" data-confirm="Enregistrer et envoyer ce document à <?= h($fam['email']) ?> pour accord ?">Envoyer à la famille pour accord</button>
        </div>
      <?php endif ?>
    </fieldset>
    </form>
    <div class="row">
      <?php if ($c['statut'] === 'envoye'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><button name="act" value="brouillon" class="light small">Repasser en brouillon pour modifier</button></form>
      <?php endif ?>
      <form method="post" class="inline"><?= csrf_field() ?><button name="act" value="dupliquer" class="light small">Dupliquer</button></form>
      <form method="post" class="inline"><?= csrf_field() ?><button name="act" value="suppr" class="danger small" data-confirm="Supprimer ce document ?">Supprimer</button></form>
    </div>
    <?php
    break;

// ================= IMPAYÉS (toutes périodes) =================
case 'impayes':
    if (post()) {
        $fid = (int)($_POST['famille'] ?? 0);
        $ym = mois_valide($_POST['mois'] ?? null);
        if (($_POST['act'] ?? '') === 'relance') {
            flash(mail_facture($fid, $ym, true) ? 'Relance envoyée.' : "La relance n'a pas pu être envoyée.", 'ok');
        } elseif (($_POST['act'] ?? '') === 'payer') {
            q('INSERT OR REPLACE INTO paiements (famille_id, mois, montant, date, mode) VALUES (?, ?, ?, ?, ?)',
                [$fid, $ym, (float)str_replace(',', '.', $P('montant')), date_valide($_POST['date'] ?? null), $P('mode')]);
            flash('Paiement enregistré.');
        }
        redirect('admin.php?p=impayes');
    }
    page_debut('Factures impayées');
    $imp = impayes();
    $parFam = [];
    foreach ($imp as $r) { $parFam[$r['nom']] = ($parFam[$r['nom']] ?? 0) + $r['reste']; }
    arsort($parFam);
    ?>
    <p><a href="?p=factures">← Factures du mois</a></p>
    <div class="grid2">
      <div class="card"><span class="muted">Reste à encaisser</span><br><span class="total"><?= euro(array_sum(array_column($imp, 'reste'))) ?></span></div>
      <div class="card"><span class="muted">Factures impayées</span><br><span class="total"><?= count($imp) ?></span>
        <?php $retard = count(array_filter($imp, fn($r) => $r['jours'] > 15)); if ($retard): ?> <span class="badge warn"><?= $retard ?> depuis + de 15 jours</span><?php endif ?></div>
    </div>
    <?php if ($parFam): ?>
      <h2>Par famille</h2>
      <div class="scroll"><table><tr><th>Famille</th><th class="r">Doit</th></tr>
        <?php foreach ($parFam as $nom => $m): ?><tr><td><?= h($nom) ?></td><td class="r"><b><?= euro($m) ?></b></td></tr><?php endforeach ?>
      </table></div>
    <?php endif ?>
    <h2>Détail</h2>
    <div class="scroll"><table>
      <tr><th>Famille</th><th>Facture</th><th class="r">Reste dû</th><th>Depuis</th><th>Relance</th><th>Encaisser</th></tr>
      <?php foreach ($imp as $r): ?>
        <tr><td><?= h($r['nom']) ?><br><span class="muted"><?= h($r['tel']) ?></span></td>
          <td><a href="facture.php?f=<?= (int)$r['famille_id'] ?>&m=<?= h($r['mois']) ?>">N° <?= (int)$r['numero'] ?></a><br><span class="muted"><?= h(mois_fr($r['mois'])) ?></span></td>
          <td class="r"><b><?= euro($r['reste']) ?></b><?= $r['paye_le'] ? '<br><span class="muted">sur ' . euro($r['total']) . '</span>' : '' ?></td>
          <td><span class="badge<?= $r['jours'] > 15 ? ' warn' : ' full' ?>"><?= $r['jours'] ?> j</span></td>
          <td><?= $r['relance'] ? '<span class="muted">relancée le ' . h(date('d/m', strtotime($r['relance']))) . '</span><br>' : '' ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= (int)$r['famille_id'] ?>"><input type="hidden" name="mois" value="<?= h($r['mois']) ?>">
              <button class="light small" name="act" value="relance" data-confirm="Envoyer un rappel à <?= h($r['email']) ?> ?">relancer</button></form></td>
          <td><form method="post" class="row" style="margin:0"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= (int)$r['famille_id'] ?>"><input type="hidden" name="mois" value="<?= h($r['mois']) ?>">
              <input name="montant" value="<?= h(number_format($r['total'], 2, ',', '')) ?>" size="7" aria-label="Montant total payé">
              <input type="date" name="date" value="<?= date('Y-m-d') ?>" aria-label="Date">
              <select name="mode" aria-label="Mode"><option>Virement</option><option>Chèque</option><option>Espèces</option><option>CESU</option></select>
              <button class="small" name="act" value="payer">Payé</button></form></td></tr>
      <?php endforeach ?>
      <?php if (!$imp): ?><tr><td colspan="6" class="muted">Aucune facture impayée 🎉</td></tr><?php endif ?>
    </table></div>
    <?php
    break;

// ================= IMPORT D'UNE PHOTO DE FEUILLE D'HEURES =================
case 'photo':
    $enfantsActifs = q('SELECT e.id, e.prenom, e.nom FROM enfants e JOIN familles f ON f.id = e.famille_id WHERE e.actif = 1 AND f.actif = 1 ORDER BY e.prenom')->fetchAll();
    $sansAccent = fn(string $s): string => strtolower(strtr(trim($s), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']));
    if (post()) {
        $act = $_POST['act'] ?? '';
        // Lit une photo envoyée et rattache chaque prénom lu à un enfant de l'appli
        $lireFichier = function (array $f) use ($enfantsActifs, $sansAccent): array {
            $mime = is_uploaded_file($f['tmp_name'] ?? '') ? (string)mime_content_type($f['tmp_name']) : '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException("« {$f['name']} » n'est pas une photo (JPEG ou PNG).");
            [$donnees, $type] = preparer_photo($f['tmp_name'], $mime);
            $lignes = lire_photo_heures($donnees, $type, mois_valide($_POST['mois'] ?? null));
            $copie = garder_feuille($donnees); // la photo reste consultable pendant la vérification (et 3 mois après)
            foreach ($lignes as &$l) {
                $l['enfant_id'] = 0;
                $l['photo'] = (string)$f['name'];
                $l['fichier'] = $copie;
                foreach ($enfantsActifs as $e) {
                    if ($sansAccent($l['enfant']) === $sansAccent($e['prenom']) || $sansAccent($l['enfant']) === $sansAccent(trim($e['prenom'] . ' ' . $e['nom']))) { $l['enfant_id'] = (int)$e['id']; break; }
                }
            }
            return $lignes;
        };
        if ($act === 'lire_ajout') { // plusieurs photos : une requête par photo (appelée par la page), résultats cumulés
            set_time_limit(180);
            header('Content-Type: application/json');
            try { // les lignes s'ajoutent à la vérification en cours (doublons repérés à l'affichage)
                $lignes = $lireFichier($_FILES['photo'] ?? []);
                feuilles_en_attente(array_merge(feuilles_en_attente(), $lignes));
                exit(json_encode(['ok' => true, 'n' => count($lignes)]));
            } catch (RuntimeException $e) {
                exit(json_encode(['ok' => false, 'erreur' => $e->getMessage()]));
            }
        }
        if ($act === 'lire') { // sans JavaScript : une seule photo
            set_time_limit(180);
            try {
                feuilles_en_attente($lireFichier($_FILES['photo'] ?? []));
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'err');
                redirect('admin.php?p=photo');
            }
            redirect('admin.php?p=photo&v=verif');
        }
        if ($act === 'importer') {
            $n = $nAbs = 0; $jours = []; $sansResa = [];
            foreach ($_POST['l'] ?? [] as $l) {
                if (empty($l['ok'])) continue;
                $eid = (int)($l['enfant'] ?? 0);
                $jour = date_valide($l['date'] ?? null);
                if (!empty($l['absent'])) { // « pas là » : réservations du jour marquées absentes (non facturées)
                    if (!$eid) continue;
                    $nb = q('UPDATE reservations SET present = 0 WHERE enfant_id = ? AND jour = ?', [$eid, $jour])->rowCount();
                    if ($nb) { $nAbs++; $jours[$jour] = true; }
                    else $sansResa[] = q('SELECT prenom FROM enfants WHERE id = ?', [$eid])->fetchColumn() . ' le ' . date('d/m', strtotime($jour));
                    continue;
                }
                $a = (string)($l['arrivee'] ?? ''); $b = (string)($l['depart'] ?? '');
                if (!$eid || minutes($a) === null || minutes($b) === null || ($pr = creneau_pour($jour, $a, $b)) === null) continue;
                reserver($eid, $pr, $jour);
                q('UPDATE reservations SET arrivee = ?, depart = ?, present = 1, repas = MAX(repas, ?), gouter = MAX(gouter, ?), petitdej = MAX(petitdej, ?)
                   WHERE enfant_id = ? AND prestation_id = ? AND jour = ?',
                    [$a, $b, isset($l['repas']) ? 1 : 0, isset($l['gouter']) ? 1 : 0, isset($l['petitdej']) ? 1 : 0, $eid, $pr['id'], $jour]);
                $n++; $jours[$jour] = true;
            }
            feuilles_en_attente([]);
            ksort($jours);
            flash("$n présence(s) importée(s) dans le pointage" . ($nAbs ? ", $nAbs absence(s) enregistrée(s)" : '')
                . ($jours ? ' (' . implode(', ', array_map(fn($j) => date('d/m', strtotime($j)), array_keys($jours))) . ')' : '') . '.');
            if ($sansResa) flash('Absent sans réservation ce jour-là (rien à faire) : ' . implode(', ', $sansResa) . '.');
            redirect('admin.php?p=jour' . ($jours ? '&d=' . array_key_first($jours) : ''));
        }
        if ($act === 'abandonner') {
            feuilles_en_attente([]);
            flash('Vérification abandonnée (les photos restent dans « Feuilles déjà lues »).');
        }
        redirect('admin.php?p=photo');
    }
    page_debut("Importer une feuille d'heures");
    // la vérification en cours est gardée sur le serveur : on la retrouve même après avoir quitté la page
    $lignes = ($_GET['v'] ?? '') === 'verif' ? feuilles_en_attente() : [];
    if (!$lignes && feuilles_en_attente()): ?>
      <div class="flash err"><b><?= count(feuilles_en_attente()) ?> ligne(s) lue(s) pas encore importée(s).</b>
        <a class="btn small" href="?p=photo&v=verif">Reprendre la vérification</a></div>
    <?php endif;
    usort($lignes, fn($a, $b) => [$a['date'], $a['arrivee'] ?? '', $a['enfant']] <=> [$b['date'], $b['arrivee'] ?? '', $b['enfant']]);
    // Doublons (même ligne sur deux photos) et lignes déjà présentes dans le pointage : décochées
    $vues = [];
    foreach ($lignes as &$l) {
        $cle = implode('|', [$l['enfant_id'] ?: $sansAccent($l['enfant']), $l['date'], !empty($l['absent']) ? 'absent' : $l['arrivee'] . '-' . $l['depart']]);
        $l['doublon'] = isset($vues[$cle]);
        $vues[$cle] = true;
        $l['deja'] = false;
        if ($l['enfant_id'] && !$l['doublon']) {
            $l['deja'] = !empty($l['absent'])
                ? (bool)q('SELECT 1 FROM reservations WHERE enfant_id = ? AND jour = ? AND present = 0', [$l['enfant_id'], $l['date']])->fetch()
                : (bool)q('SELECT 1 FROM reservations WHERE enfant_id = ? AND jour = ? AND arrivee = ? AND depart = ?', [$l['enfant_id'], $l['date'], $l['arrivee'], $l['depart']])->fetch();
        }
    }
    unset($l);
    $nbDoublons = count(array_filter($lignes, fn($l) => $l['doublon'] || $l['deja']));
    if ($lignes): ?><div class="flash"><?= count($lignes) ?> ligne(s) lue(s) sur <?= count(array_unique(array_column($lignes, 'photo'))) ?> photo(s) : vérifiez avant d'importer.
      <?= $nbDoublons ? "<br>$nbDoublons doublon(s) ou ligne(s) déjà importée(s) : décochée(s) automatiquement." : '' ?></div><?php endif;
    if (!$lignes): ?>
      <?php if (setting('anthropic_key') === ''): ?>
        <div class="flash err">La clé API Anthropic n'est pas encore renseignée : ajoutez-la dans <a href="?p=reglages#cle">Réglages</a>.</div>
      <?php endif ?>
      <form method="post" enctype="multipart/form-data" class="card narrow" id="form-photos"><?= csrf_field() ?><input type="hidden" name="act" value="lire">
        <p>Prenez en photo les pages où sont notés les prénoms, les jours et les heures d'arrivée / de départ (une page par photo).
          Touchez <b>« Prendre une photo » autant de fois que nécessaire</b> (ou choisissez-en plusieurs dans la galerie), puis lisez-les toutes d'un coup.</p>
        <div class="row" style="margin:.4rem 0">
          <label class="btn light" style="margin:0;color:var(--accent)">📷 Prendre une photo<input type="file" id="camera" accept="image/*" capture="environment" style="display:none"></label>
          <label class="btn light" style="margin:0;color:var(--accent)">🖼 Choisir dans la galerie<input type="file" id="galerie" accept="image/*" multiple style="display:none"></label>
        </div>
        <div id="miniatures" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem"></div>
        <label>Mois des feuilles (si la date n'est pas complète)<input type="month" name="mois" value="<?= date('Y-m') ?>"></label>
        <button disabled>Lire les photos</button>
        <div id="progression" class="muted" style="margin-top:.6rem"></div>
        <p class="muted">Conseils : page bien à plat, entière dans la photo, date visible, bonne lumière. Environ 10 secondes par photo.</p>
      </form>
      <?php $anciennes = glob(dossier_feuilles() . '/*.jpg') ?: []; rsort($anciennes); if ($anciennes): ?>
        <details class="card narrow"><summary><b>Feuilles déjà lues</b> <span class="muted">(<?= count($anciennes) ?> photo(s), gardées 3 mois)</span></summary>
          <div class="row" style="margin-top:.6rem">
            <?php foreach (array_slice($anciennes, 0, 40) as $ph): $n = basename($ph); ?>
              <a href="feuille.php?f=<?= h($n) ?>" target="_blank" title="<?= h(date('d/m/Y H:i', filemtime($ph))) ?>" style="text-align:center;font-size:.75rem;color:var(--muted);text-decoration:none">
                <img src="feuille.php?f=<?= h($n) ?>" alt="" loading="lazy" style="width:80px;height:106px;object-fit:cover;border-radius:6px;border:1px solid var(--line);display:block">
                <?= h(date('d/m', filemtime($ph))) ?></a>
            <?php endforeach ?>
          </div>
        </details>
      <?php endif ?>
      <script>
      // Photos ajoutées une par une (appareil photo) ou plusieurs (galerie), avec miniatures
      const files = [], mini = document.getElementById('miniatures'), btnLire = document.querySelector('#form-photos button');
      const afficher = () => {
        mini.innerHTML = '';
        files.forEach((f, i) => {
          const d = document.createElement('div');
          d.style.cssText = 'position:relative;width:76px;height:96px;border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#fff';
          const img = document.createElement('img'); img.src = URL.createObjectURL(f); img.style.cssText = 'width:100%;height:100%;object-fit:cover';
          const x = document.createElement('button'); x.type = 'button'; x.textContent = '✕'; x.title = 'Retirer';
          x.style.cssText = 'position:absolute;top:2px;right:2px;padding:0 6px;border-radius:50%;background:var(--warn);color:#fff;border:0;font-size:12px';
          x.onclick = () => { files.splice(i, 1); afficher(); };
          d.append(img, x); mini.append(d);
        });
        btnLire.disabled = !files.length;
        btnLire.textContent = files.length ? `Lire les ${files.length} photo(s)` : 'Lire les photos';
      };
      for (const id of ['camera', 'galerie']) {
        document.getElementById(id).addEventListener('change', (e) => {
          const cle = f => f.name + '|' + f.size + '|' + f.lastModified;
          let ignorees = 0;
          for (const f of e.target.files) { if (files.some(g => cle(g) === cle(f))) ignorees++; else files.push(f); }
          if (ignorees) alert(ignorees + ' photo(s) déjà dans la liste : ignorée(s).');
          e.target.value = ''; afficher();
        });
      }
      document.getElementById('form-photos').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const form = ev.target, prog = document.getElementById('progression'), btn = btnLire;
        if (!files.length) return;
        btn.disabled = true;
        let lues = 0, erreurs = [];
        for (let i = 0; i < files.length; i++) {
          prog.textContent = `Lecture de la photo ${i + 1} / ${files.length}… (${lues} ligne(s) lue(s) pour l'instant)`;
          const fd = new FormData();
          fd.append('csrf', form.csrf.value); fd.append('act', 'lire_ajout'); fd.append('mois', form.mois.value);
          if (i === 0) fd.append('debut', '1');
          fd.append('photo', files[i], files[i].name);
          try {
            const r = await (await fetch('admin.php?p=photo', {method: 'POST', body: fd})).json();
            if (r.ok) lues += r.n; else erreurs.push(files[i].name + ' : ' + r.erreur);
          } catch (e) { erreurs.push(files[i].name + ' : erreur réseau, réessayez.'); }
        }
        if (lues) {
          if (erreurs.length) alert('Certaines photos n\'ont pas pu être lues :\n' + erreurs.join('\n'));
          location.href = 'admin.php?p=photo&v=verif';
        } else { prog.innerHTML = '<span style="color:var(--warn)">' + (erreurs.join('<br>') || 'Aucune ligne lue.') + '</span>'; btn.disabled = false; }
      });
      </script>
    <?php else: ?>
      <p class="muted">Vérifiez chaque ligne. Les lignes en rouge (lecture incertaine ou enfant non reconnu) sont décochées : corrigez-les puis cochez-les pour les importer.
        Chaque plage est rangée dans le créneau du jour qui lui correspond (matin, midi, soir…).
        <b>Vous pouvez quitter cette page et y revenir plus tard : la vérification est gardée.</b></p>
      <?php $photosLues = array_values(array_unique(array_filter(array_column($lignes, 'fichier')))); if ($photosLues): ?>
        <div class="row" style="margin-bottom:.6rem">
          <?php foreach ($photosLues as $ph): ?>
            <a href="feuille.php?f=<?= h($ph) ?>" target="_blank" title="Ouvrir la photo en grand"><img src="feuille.php?f=<?= h($ph) ?>" alt="" style="width:90px;height:120px;object-fit:cover;border-radius:6px;border:1px solid var(--line)"></a>
          <?php endforeach ?>
          <span class="muted">Touchez une photo pour la voir en grand et comparer.</span>
        </div>
      <?php endif ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="importer">
      <div class="scroll"><table>
        <tr><th>Importer</th><th>Enfant</th><th>Date</th><th>Arrivée</th><th>Départ</th><th>Repas</th><th>Remarque</th></tr>
        <?php foreach ($lignes as $i => $l): $doute = !empty($l['incertain']) || !$l['enfant_id']; $ecarte = $l['doublon'] || $l['deja']; ?>
          <tr<?= $ecarte ? ' style="opacity:.55"' : ($doute ? ' style="background:var(--warn-soft)"' : '') ?>>
            <td class="c"><input type="checkbox" name="l[<?= $i ?>][ok]" <?= $l['enfant_id'] && empty($l['incertain']) && !$ecarte ? 'checked' : '' ?>>
              <?= $l['doublon'] ? '<br><span class="badge full">doublon</span>' : ($l['deja'] ? '<br><span class="badge full">déjà importé</span>' : '') ?></td>
            <td><select name="l[<?= $i ?>][enfant]"><option value="0">? <?= h($l['enfant']) ?></option>
              <?php foreach ($enfantsActifs as $e): ?><option value="<?= $e['id'] ?>" <?= (int)$e['id'] === (int)$l['enfant_id'] ? 'selected' : '' ?>><?= h($e['prenom']) ?></option><?php endforeach ?></select></td>
            <td><input type="date" name="l[<?= $i ?>][date]" value="<?= h($l['date']) ?>"></td>
            <?php if (!empty($l['absent'])): ?>
              <td colspan="3"><input type="hidden" name="l[<?= $i ?>][absent]" value="1"><span class="badge warn">Absent</span>
                <span class="muted">marqué absent ce jour-là (non facturé) s'il était réservé</span></td>
            <?php else: ?>
            <td><input type="time" name="l[<?= $i ?>][arrivee]" value="<?= h($l['arrivee']) ?>"></td>
            <td><input type="time" name="l[<?= $i ?>][depart]" value="<?= h($l['depart']) ?>"></td>
            <td style="white-space:nowrap"><?php foreach (['petitdej' => 'P.déj', 'repas' => 'Repas', 'gouter' => 'Goûter'] as $x => $lib): ?>
              <label style="display:block;margin:0;color:var(--ink)"><input type="checkbox" name="l[<?= $i ?>][<?= $x ?>]" <?= !empty($l[$x]) ? 'checked' : '' ?> style="display:inline;width:auto;height:auto"> <?= $lib ?></label>
            <?php endforeach ?></td>
            <?php endif ?>
            <td class="muted"><?= h($l['note']) ?><?= !$l['enfant_id'] ? ' Enfant non reconnu : choisissez-le.' : '' ?>
              <?= !empty($l['fichier']) ? '<br><small><a href="feuille.php?f=' . h($l['fichier']) . '" target="_blank">voir la photo</a></small>'
                  : (!empty($l['photo']) ? '<br><small>photo : ' . h($l['photo']) . '</small>' : '') ?></td>
          </tr>
        <?php endforeach ?>
      </table></div>
      <div class="row"><button>Importer dans le pointage</button> <a class="btn light" href="?p=photo">Ajouter d'autres photos</a>
        <button class="danger small" name="act" value="abandonner" formnovalidate data-confirm="Abandonner cette vérification ? Les lignes lues seront effacées (pas les photos).">Abandonner</button></div>
      </form>
    <?php endif;
    break;

// ================= SANTÉ : fiche d'urgence, registre des médicaments, anniversaires =================
case 'sante':
    $ym = mois_valide($_GET['m'] ?? null);
    if (post()) {
        if (($_POST['act'] ?? '') === 'suppr') {
            q('DELETE FROM medicaments WHERE id = ?', [(int)$_POST['id']]);
            flash('Ligne supprimée.');
        } elseif ($P('medicament') === '' || !(int)($_POST['enfant'] ?? 0)) {
            flash('Enfant et médicament obligatoires.', 'err');
        } else {
            $jour = date_valide($_POST['jour'] ?? null);
            q('INSERT INTO medicaments (enfant_id, jour, heure, medicament, dose, motif, ordonnance, autorise_par, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$_POST['enfant'], $jour, $P('heure') ?: null, $P('medicament'), $P('dose'), $P('motif'), isset($_POST['ordonnance']) ? 1 : 0, $P('autorise_par'), $P('notes')]);
            flash('Médicament enregistré dans le registre.');
            $ym = substr($jour, 0, 7);
        }
        redirect("admin.php?p=sante&m=$ym");
    }
    page_debut('Santé');
    $enfants = q('SELECT e.id, e.prenom, e.nom, e.allergies FROM enfants e JOIN familles f ON f.id = e.famille_id WHERE e.actif = 1 AND f.actif = 1 ORDER BY e.prenom')->fetchAll();
    $registre = q('SELECT m.*, e.prenom, e.nom FROM medicaments m JOIN enfants e ON e.id = m.enfant_id WHERE m.jour LIKE ? ORDER BY m.jour DESC, m.heure DESC', ["$ym-%"])->fetchAll();
    $sansNaissance = q("SELECT GROUP_CONCAT(e.prenom, ', ') FROM enfants e JOIN familles f ON f.id = e.famille_id WHERE e.actif = 1 AND f.actif = 1 AND (e.naissance IS NULL OR e.naissance = '')")->fetchColumn();
    ?>
    <div class="grid2">
      <div class="card"><h2 style="margin-top:0">Fiche d'urgence</h2>
        <p class="muted">Tous les enfants sur une page : allergies, parents, contacts d'urgence, personnes autorisées, numéros utiles.</p>
        <a class="btn" href="urgence.php" target="_blank">Ouvrir / imprimer</a></div>
      <div class="card"><h2 style="margin-top:0">Anniversaires (60 prochains jours)</h2>
        <?php foreach (anniversaires(60) as $a): ?>
          <div>🎂 <b><?= h($a['prenom']) ?></b> — <?= $a['age'] ?> ans le <?= h(date('d/m', strtotime($a['date']))) ?>
            <span class="muted"><?= $a['dans'] === 0 ? "aujourd'hui !" : 'dans ' . $a['dans'] . ' j' ?></span></div>
        <?php endforeach ?>
        <?php if (!anniversaires(60)): ?><p class="muted">Aucun.</p><?php endif ?>
        <?php if ($sansNaissance): ?><p class="muted">Date de naissance manquante : <?= h($sansNaissance) ?> (à compléter dans Familles).</p><?php endif ?>
      </div>
    </div>

    <h2>Registre des médicaments</h2>
    <form method="post" class="card"><?= csrf_field() ?>
      <div class="grid2">
        <label>Enfant<select name="enfant" required><option value="">…</option>
          <?php foreach ($enfants as $e): ?><option value="<?= $e['id'] ?>"><?= h(trim($e['prenom'] . ' ' . $e['nom'])) ?><?= $e['allergies'] ? ' ⚠ ' . h($e['allergies']) : '' ?></option><?php endforeach ?></select></label>
        <label>Date<input type="date" name="jour" value="<?= date('Y-m-d') ?>" required></label>
        <label>Heure<input type="time" name="heure" value="<?= date('H:i') ?>"></label>
        <label>Médicament<input name="medicament" required placeholder="ex. Doliprane sirop"></label>
        <label>Dose<input name="dose" placeholder="ex. 5 ml / 1 dose-poids"></label>
        <label>Motif<input name="motif" placeholder="ex. fièvre 38,5°"></label>
        <label>Autorisé par (parent)<input name="autorise_par" placeholder="ex. Mme Martin, par SMS à 11h"></label>
        <label>Notes<input name="notes"></label>
      </div>
      <label><input type="checkbox" name="ordonnance" style="display:inline;width:auto"> Ordonnance fournie</label>
      <button>Enregistrer</button>
    </form>
    <div class="row">
      <a class="btn light small" href="?p=sante&m=<?= mois_decale($ym, -1) ?>">◀</a><b><?= h(mois_fr($ym)) ?></b>
      <a class="btn light small" href="?p=sante&m=<?= mois_decale($ym, 1) ?>">▶</a>
      <button type="button" class="light small" onclick="window.print()">Imprimer</button>
    </div>
    <div class="scroll"><table>
      <tr><th>Date</th><th>Enfant</th><th>Médicament</th><th>Dose</th><th>Motif</th><th>Ordonnance</th><th>Autorisé par</th><th></th></tr>
      <?php foreach ($registre as $m): ?>
        <tr><td><?= h(date('d/m/Y', strtotime($m['jour']))) ?><?= $m['heure'] ? ' ' . h(str_replace(':', 'h', $m['heure'])) : '' ?></td>
          <td><?= h(trim($m['prenom'] . ' ' . $m['nom'])) ?></td><td><b><?= h($m['medicament']) ?></b><?= $m['notes'] ? '<br><span class="muted">' . h($m['notes']) . '</span>' : '' ?></td>
          <td><?= h($m['dose']) ?></td><td><?= h($m['motif']) ?></td><td><?= $m['ordonnance'] ? 'oui' : 'non' ?></td><td><?= h($m['autorise_par']) ?></td>
          <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="light small" name="act" value="suppr" data-confirm="Supprimer cette ligne du registre ?">✕</button></form></td></tr>
      <?php endforeach ?>
      <?php if (!$registre): ?><tr><td colspan="8" class="muted">Aucun médicament donné en <?= h(mois_fr($ym)) ?>.</td></tr><?php endif ?>
    </table></div>
    <p class="muted">Les parents voient les médicaments donnés à leurs enfants dans « Mes enfants ».</p>
    <?php
    break;

// ================= DEMANDES (formulaire de la page de présentation) =================
case 'demandes':
    if (post()) {
        $did = (int)($_POST['id'] ?? 0);
        if (($_POST['act'] ?? '') === 'suppr') q('DELETE FROM demandes WHERE id = ?', [$did]);
        else q('UPDATE demandes SET traitee = 1 - traitee WHERE id = ?', [$did]);
        redirect('admin.php?p=demandes');
    }
    page_debut('Demandes de nouvelles familles');
    $dem = q('SELECT * FROM demandes ORDER BY traitee, cree DESC')->fetchAll();
    ?>
    <p class="muted">Demandes envoyées depuis la page de présentation :
      <a href="presentation.php" target="_blank"><?= h(url_base()) ?>presentation.php</a> — à partager (annonces, réseaux sociaux, Nounou Top, Yoopies…).</p>
    <?php foreach ($dem as $d): ?>
      <div class="card"<?= $d['traitee'] ? ' style="opacity:.6"' : '' ?>>
        <div class="row" style="justify-content:space-between;margin:0">
          <b><?= h($d['nom']) ?><?= $d['traitee'] ? ' <span class="badge full">traitée</span>' : ' <span class="badge warn">nouvelle</span>' ?></b>
          <span class="muted"><?= h(date('d/m/Y H:i', strtotime($d['cree']))) ?></span>
        </div>
        <p><a href="mailto:<?= h($d['email']) ?>"><?= h($d['email']) ?></a><?= $d['tel'] ? ' · <a href="tel:' . h(preg_replace('/\s+/', '', $d['tel'])) . '">' . h($d['tel']) . '</a>' : '' ?></p>
        <?php if ($d['enfants']): ?><p><b>Enfant(s) :</b> <?= h($d['enfants']) ?></p><?php endif ?>
        <?php if ($d['besoins']): ?><p><b>Besoins :</b> <?= h($d['besoins']) ?></p><?php endif ?>
        <?php if ($d['debut']): ?><p><b>À partir de :</b> <?= h($d['debut']) ?></p><?php endif ?>
        <?php if ($d['message']): ?><p style="white-space:pre-line"><?= h($d['message']) ?></p><?php endif ?>
        <div class="row" style="margin:0">
          <a class="btn small" href="?p=familles&nom=<?= urlencode('Famille ' . $d['nom']) ?>&email=<?= urlencode($d['email']) ?>&tel=<?= urlencode((string)$d['tel']) ?>#nouvelle">Créer la famille</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button class="light small"><?= $d['traitee'] ? 'Remettre en nouvelle' : 'Marquer traitée' ?></button></form>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button class="danger small" name="act" value="suppr" data-confirm="Supprimer cette demande ?">Supprimer</button></form>
        </div>
      </div>
    <?php endforeach ?>
    <?php if (!$dem): ?><div class="card muted">Aucune demande pour le moment.</div><?php endif ?>
    <?php
    break;

// ================= RÉCAPITULATIF ANNUEL =================
case 'recap':
    $an = (int)($_GET['a'] ?? date('Y'));
    if ($an < 2000 || $an > 2100) $an = (int)date('Y');
    if (post()) {
        $cibles = familles_attestation($an);
        if (($_POST['act'] ?? '') === 'attest_un') $cibles = array_filter($cibles, fn($f) => (int)$f['id'] === (int)$_POST['famille']);
        elseif (($_POST['act'] ?? '') === 'attest_tous') $cibles = array_filter($cibles, fn($f) => !setting("attest_{$an}_{$f['id']}"));
        $ok = $ko = [];
        foreach ($cibles as $f) { if (mail_attestation($f, $an)) $ok[] = $f['nom']; else $ko[] = $f['nom']; }
        if ($ok) flash("Attestation $an envoyée à : " . implode(', ', $ok));
        if ($ko) flash("Échec de l'envoi pour : " . implode(', ', $ko), 'err');
        if (!$ok && !$ko) flash('Aucune attestation à envoyer.');
        redirect("admin.php?p=recap&a=$an");
    }
    page_debut("Récapitulatif $an");
    $fams = q("SELECT DISTINCT f.id, f.nom FROM familles f JOIN enfants e ON e.famille_id = f.id
               JOIN reservations r ON r.enfant_id = e.id AND r.jour LIKE ? ORDER BY f.nom", ["$an-%"])->fetchAll();
    $parEnfant = $parMois = [];
    $zero = ['heures' => 0.0, 'garde' => 0.0, 'repas' => 0, 'gouter' => 0, 'petitdej' => 0, 'nourriture' => 0.0, 'total' => 0.0];
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%d-%02d', $an, $m);
        $parMois[$ym] = $zero;
        foreach ($fams as $f) {
            foreach (totaux_par_enfant(calcul_mois((int)$f['id'], $ym)) as $prenom => $t) {
                $k = $prenom . '|' . $f['id'];
                $parEnfant[$k] ??= ['prenom' => $prenom, 'famille' => $f['nom'], 'fid' => $f['id']] + $zero;
                foreach ($zero as $c => $_) { $parEnfant[$k][$c] += $t[$c]; $parMois[$ym][$c] += $t[$c]; }
            }
        }
    }
    uasort($parEnfant, fn($a, $b) => strcmp($a['prenom'], $b['prenom']));
    $total = $zero;
    foreach ($parMois as $t) foreach ($zero as $c => $_) $total[$c] += $t[$c];
    $encaisse = (float)q("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE substr(date, 1, 4) = ?", [(string)$an])->fetchColumn();
    $ligne = fn(array $t) => '<td class="r">' . duree_fr($t['heures']) . '</td><td class="r">' . $t['petitdej'] . '</td><td class="r">' . $t['repas']
        . '</td><td class="r">' . $t['gouter'] . '</td><td class="r">' . euro($t['garde']) . '</td><td class="r">' . euro($t['nourriture'])
        . '</td><td class="r"><b>' . euro($t['total']) . '</b></td>';
    $entete = '<th class="r">Heures</th><th class="r">P.déj</th><th class="r">Repas</th><th class="r">Goûters</th><th class="r">Garde</th><th class="r">Nourriture</th><th class="r">Total</th>';
    ?>
    <div class="row">
      <a class="btn light small" href="?p=recap&a=<?= $an - 1 ?>">◀</a><b><?= $an ?></b>
      <a class="btn light small" href="?p=recap&a=<?= $an + 1 ?>">▶</a>
    </div>
    <div class="grid2">
      <div class="card"><span class="muted">Montant facturé <?= $an ?></span><br><span class="total"><?= euro($total['total']) ?></span></div>
      <div class="card"><span class="muted">Encaissé en <?= $an ?></span><br><span class="total"><?= euro($encaisse) ?></span></div>
      <div class="card"><span class="muted">Heures de garde</span><br><span class="total"><?= duree_fr($total['heures']) ?></span></div>
      <div class="card"><span class="muted">Repas + goûters + p.déj</span><br><span class="total"><?= $total['repas'] + $total['gouter'] + $total['petitdej'] ?></span></div>
    </div>

    <?php $fa = familles_attestation($an); ?>
    <h2>Attestations fiscales <?= $an ?></h2>
    <div class="card">
      <p class="muted" style="margin-top:0">À remettre aux familles avant le 31 mars <?= $an + 1 ?>. Elles reprennent les factures payées en <?= $an ?>, hors repas.</p>
      <?php if ($fa): ?>
        <div class="scroll"><table>
          <tr><th>Famille</th><th>Envoyée</th><th></th></tr>
          <?php foreach ($fa as $f): $env = setting("attest_{$an}_{$f['id']}"); ?>
            <tr><td><?= h($f['nom']) ?></td><td><?= $env ? 'le ' . h(date('d/m/Y', strtotime($env))) : '<span class="badge warn">non</span>' ?></td>
              <td><a href="attestation.php?f=<?= (int)$f['id'] ?>&a=<?= $an ?>" target="_blank">voir</a>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="famille" value="<?= (int)$f['id'] ?>">
                  <button class="light small" name="act" value="attest_un" data-confirm="Envoyer l'attestation <?= $an ?> à <?= h($f['email']) ?> ?"><?= $env ? 'renvoyer' : 'envoyer' ?></button></form></td></tr>
          <?php endforeach ?>
        </table></div>
        <form method="post" style="margin-top:.6rem"><?= csrf_field() ?>
          <button name="act" value="attest_tous" data-confirm="Envoyer par email les attestations <?= $an ?> pas encore envoyées ?">Envoyer toutes les attestations non envoyées</button></form>
      <?php else: ?><p class="muted">Aucun paiement enregistré en <?= $an ?>.</p><?php endif ?>
    </div>

    <h2>Par enfant</h2>
    <div class="scroll"><table>
      <tr><th>Enfant</th><th>Famille</th><?= $entete ?><th>Attestation</th></tr>
      <?php foreach ($parEnfant as $e): ?>
        <tr><td><b><?= h($e['prenom']) ?></b></td><td><?= h($e['famille']) ?></td><?= $ligne($e) ?>
          <td><a href="attestation.php?f=<?= (int)$e['fid'] ?>&a=<?= $an ?>">attestation <?= $an ?></a></td></tr>
      <?php endforeach ?>
      <?php if (!$parEnfant): ?><tr><td colspan="10" class="muted">Aucune garde en <?= $an ?>.</td></tr><?php endif ?>
      <tr class="sous"><td colspan="2"><b>TOTAL</b></td><?= $ligne($total) ?><td></td></tr>
    </table></div>

    <h2>Par mois</h2>
    <div class="scroll"><table>
      <tr><th>Mois</th><?= $entete ?></tr>
      <?php foreach ($parMois as $ym => $t): ?>
        <tr><td><a href="?p=factures&m=<?= $ym ?>"><?= h(mois_fr($ym)) ?></a></td><?= $ligne($t) ?></tr>
      <?php endforeach ?>
      <tr class="sous"><td><b>TOTAL <?= $an ?></b></td><?= $ligne($total) ?></tr>
    </table></div>
    <p class="muted">Garde = heures × tarif ; nourriture = repas, goûters et petits-déjeuners. Les attestations fiscales reprennent les factures payées dans l'année, sans la nourriture.</p>
    <?php
    break;

// ================= PRESTATIONS & TARIFS =================
case 'prestations':
    if (post()) {
        foreach ($_POST['pr'] ?? [] as $pid => $v) {
            $jours = implode(',', array_map('intval', array_keys($v['jours'] ?? [])));
            $lib = trim((string)($v['libelle'] ?? ''));
            if ($lib === '' || $jours === '') continue;
            $vals = [$lib, (string)($v['debut'] ?? '') ?: null, (string)($v['fin'] ?? '') ?: null, $jours,
                     max(1, (int)($v['places'] ?? 1)), (int)($v['ordre'] ?? 0), isset($v['actif']) ? 1 : 0,
                     isset($v['repas']) ? 1 : 0, isset($v['gouter']) ? 1 : 0, isset($v['petitdej']) ? 1 : 0,
                     isset(PERIODES[$v['periode'] ?? '']) ? $v['periode'] : 'toujours', isset($v['libre']) ? 1 : 0];
            if ($pid === 'new') {
                q('INSERT INTO prestations (libelle, debut, fin, jours, places, ordre, actif, repas, gouter, petitdej, periode, libre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            } else {
                q('UPDATE prestations SET libelle = ?, debut = ?, fin = ?, jours = ?, places = ?, ordre = ?, actif = ?, repas = ?, gouter = ?, petitdej = ?, periode = ?, libre = ? WHERE id = ?', [...$vals, (int)$pid]);
            }
        }
        flash('Créneaux enregistrés.');
        redirect('admin.php?p=prestations');
    }
    page_debut('Créneaux de garde');
    $all = q('SELECT * FROM prestations ORDER BY ordre, id')->fetchAll();
    $all[] = ['id' => 'new', 'libelle' => '', 'debut' => '', 'fin' => '', 'jours' => '', 'places' => 4, 'ordre' => count($all) + 1, 'actif' => 1, 'repas' => 0, 'gouter' => 0, 'petitdej' => 0, 'periode' => 'toujours', 'libre' => 0];
    ?>
    <p class="muted">Les créneaux que les parents peuvent réserver. Les horaires servent à facturer quand l'heure réelle n'est pas pointée.
      <b>Période</b> : « Période scolaire » = proposé seulement hors vacances ; « Vacances scolaires » = seulement pendant les vacances (dates dans <a href="?p=fermetures">Fermetures</a>).
      <b>Heures parents</b> : les parents indiquent eux-mêmes l'heure d'arrivée et de départ prévues.
      Repas / goûter / petit-déj = cochés par défaut à la réservation (modifiables au pointage). Places = nombre maximum d'enfants.
      Les tarifs (€/heure, repas…) sont dans <a href="?p=reglages">Réglages</a>.</p>
    <form method="post"><?= csrf_field() ?>
    <style>
      .cren { display: flex; flex-wrap: wrap; gap: .4rem 1rem; align-items: flex-end; }
      .cren > label { margin: 0; }
      .cren .coche { display: inline-flex; align-items: center; gap: .3rem; color: var(--ink); white-space: nowrap; }
      .cren .coche input { display: inline; width: 1.25rem; height: 1.25rem; margin: 0; }
      .cren .jours { display: flex; gap: .35rem; }
      .cren .jours label { margin: 0; text-align: center; font-size: .8rem; }
      .cren .jours input { display: block; margin: .15rem auto 0; width: 1.25rem; }
    </style>
    <?php foreach ($all as $pr): $j = explode(',', (string)$pr['jours']); $n = 'pr[' . $pr['id'] . ']'; ?>
      <div class="card"<?= $pr['actif'] ? '' : ' style="opacity:.6"' ?>>
        <?php if ($pr['id'] === 'new'): ?><b>➕ Nouveau créneau</b> <span class="muted">(remplir seulement pour en ajouter un)</span><?php endif ?>
        <div class="cren">
          <label style="flex:1 1 14rem">Nom<input name="<?= $n ?>[libelle]" value="<?= h($pr['libelle']) ?>" placeholder="<?= $pr['id'] === 'new' ? 'ex. Mercredi soir' : '' ?>"></label>
          <label>Début<input type="time" name="<?= $n ?>[debut]" value="<?= h($pr['debut']) ?>"></label>
          <label>Fin<input type="time" name="<?= $n ?>[fin]" value="<?= h($pr['fin']) ?>"></label>
          <label>Places<input type="number" min="1" name="<?= $n ?>[places]" value="<?= (int)$pr['places'] ?>" style="width:4.5rem"></label>
          <label>Ordre<input type="number" name="<?= $n ?>[ordre]" value="<?= (int)$pr['ordre'] ?>" style="width:4rem"></label>
        </div>
        <div class="cren" style="margin-top:.6rem">
          <div class="jours"><?php foreach ([1, 2, 3, 4, 5, 6, 7] as $k): ?>
            <label><?= JOURS_COURTS[$k] ?><input type="checkbox" name="<?= $n ?>[jours][<?= $k ?>]" <?= in_array((string)$k, $j, true) ? 'checked' : '' ?>></label>
          <?php endforeach ?></div>
          <label>Période<select name="<?= $n ?>[periode]"><?php foreach (PERIODES as $cle => $lib): ?><option value="<?= $cle ?>" <?= ($pr['periode'] ?? 'toujours') === $cle ? 'selected' : '' ?>><?= $lib ?></option><?php endforeach ?></select></label>
        </div>
        <div class="cren" style="margin-top:.6rem">
          <?php foreach (['petitdej' => 'P.déj', 'repas' => 'Repas', 'gouter' => 'Goûter', 'libre' => 'Heures parents', 'actif' => 'Actif'] as $x => $lib): ?>
            <label class="coche"><input type="checkbox" name="<?= $n ?>[<?= $x ?>]" <?= !empty($pr[$x]) ? 'checked' : '' ?>> <?= $lib ?></label>
          <?php endforeach ?>
        </div>
      </div>
    <?php endforeach ?>
    <p><button>Enregistrer</button></p>
    </form>
    <?php
    break;

// ================= FERMETURES (vacances, jours fériés) =================
case 'fermetures':
    if (post()) {
        if (($_POST['act'] ?? '') === 'vac_ajout') {
            $du = date_valide($_POST['du'] ?? null);
            $au = max($du, date_valide(($_POST['au'] ?? '') ?: $du));
            q('INSERT INTO vacances (nom, debut, fin) VALUES (?, ?, ?)', [$P('nom') ?: 'Vacances', $du, $au]);
            flash('Période de vacances ajoutée.');
        } elseif (($_POST['act'] ?? '') === 'bloc_ajout') {
            // fermeture partielle : créneaux cochés et/ou plage d'heures, sur un jour
            $d = date_valide($_POST['jour'] ?? null);
            $hd = heure_valide($_POST['debut'] ?? null); $hf = heure_valide($_POST['fin'] ?? null);
            $prs = array_map('intval', array_keys((array)($_POST['prest'] ?? [])));
            $ids = [];
            foreach ($prs as $pid) {
                q('INSERT INTO blocages (jour, prestation_id, motif) VALUES (?, ?, ?)', [$d, $pid, $P('motif')]);
                $ids[] = (int)db()->lastInsertId();
            }
            if ($hd && $hf && minutes($hf) > minutes($hd)) {
                q('INSERT INTO blocages (jour, debut, fin, motif) VALUES (?, ?, ?, ?)', [$d, $hd, $hf, $P('motif')]);
                $ids[] = (int)db()->lastInsertId();
            }
            if (!$ids) {
                flash('Cochez au moins un créneau, ou indiquez une heure de début et de fin.', 'err');
            } else {
                $annul = 0;
                if (isset($_POST['annuler'])) {
                    foreach ($ids as $bid) {
                        $b = q('SELECT * FROM blocages WHERE id = ?', [$bid])->fetch();
                        foreach (reservations_bloquees($b) as $r) $annul += q('DELETE FROM reservations WHERE id = ?', [$r['id']])->rowCount();
                    }
                }
                flash('Fermeture partielle ajoutée le ' . date('d/m', strtotime($d)) . ($annul ? ", $annul réservation(s) annulée(s) (prévenez les familles)" : '') . '.');
            }
        } elseif (isset($_POST['bloc_suppr'])) {
            q('DELETE FROM blocages WHERE id = ?', [(int)$_POST['bloc_suppr']]);
            flash('Fermeture partielle retirée : les parents peuvent de nouveau réserver.');
        } elseif (isset($_POST['bloc_annuler'])) {
            $b = q('SELECT * FROM blocages WHERE id = ?', [(int)$_POST['bloc_annuler']])->fetch();
            $n = 0;
            if ($b) foreach (reservations_bloquees($b) as $r) $n += q('DELETE FROM reservations WHERE id = ?', [$r['id']])->rowCount();
            flash("$n réservation(s) annulée(s). Pensez à prévenir les familles.");
        } elseif (isset($_POST['vac_suppr'])) {
            q('DELETE FROM vacances WHERE id = ?', [(int)$_POST['vac_suppr']]);
            flash('Période de vacances retirée.');
        } elseif (isset($_POST['suppr'])) {
            q('DELETE FROM fermetures WHERE jour = ?', [date_valide($_POST['suppr'])]);
            flash('Fermeture supprimée.');
        } elseif (isset($_POST['suppr_plage'])) {
            [$du, $au] = array_map('date_valide', explode('|', (string)$_POST['suppr_plage']) + [1 => '']);
            $n = q('DELETE FROM fermetures WHERE jour BETWEEN ? AND ?', [$du, max($du, $au)])->rowCount();
            flash("Fermeture retirée ($n jour(s)) : les parents peuvent de nouveau réserver.");
        } else {
            $du = date_valide($_POST['du'] ?? null);
            $au = min(max($du, date_valide(($_POST['au'] ?? '') ?: $du)), date('Y-m-d', strtotime("$du +1 year")));
            $nb = $annul = 0;
            for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                q('INSERT OR REPLACE INTO fermetures (jour, motif) VALUES (?, ?)', [$d, $P('motif')]);
                if (isset($_POST['annuler'])) $annul += q('DELETE FROM reservations WHERE jour = ?', [$d])->rowCount();
                $nb++;
            }
            flash("$nb jour(s) de fermeture ajouté(s)" . ($annul ? ", $annul réservation(s) annulée(s)" : '') . '.');
        }
        redirect('admin.php?p=fermetures');
    }
    page_debut('Fermetures');
    $liste = q('SELECT f.*, (SELECT COUNT(*) FROM reservations r WHERE r.jour = f.jour) AS n FROM fermetures f WHERE f.jour >= ? ORDER BY f.jour', [date('Y-m-d')])->fetchAll();
    ?>
    <h2 style="margin-top:0">Rendez-vous / fermer une partie de la journée</h2>
    <p class="muted">Un rendez-vous, un après-midi d'absence… Les parents voient ces créneaux « fermé » ce jour-là ; le reste de la journée reste réservable.
      Les heures fermées s'appliquent aussi au créneau des vacances (les parents ne peuvent pas choisir d'heures pendant l'absence).</p>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="bloc_ajout">
      <div class="grid2">
        <label>Date<input type="date" name="jour" required min="<?= date('Y-m-d') ?>"></label>
        <label>Motif (vu par les parents)<input name="motif" placeholder="ex. rendez-vous médical"></label>
      </div>
      <b>Créneaux fermés</b>
      <div class="row" style="margin:.3rem 0 .6rem">
        <?php foreach (prestations() as $p): ?>
          <label style="margin:0;color:var(--ink);white-space:nowrap"><input type="checkbox" name="prest[<?= (int)$p['id'] ?>]" style="display:inline;width:auto"> <?= h($p['libelle']) ?></label>
        <?php endforeach ?>
      </div>
      <div class="row" style="align-items:flex-end">
        <b style="align-self:center">et / ou des heures :</b>
        <label style="margin:0">fermé de<input type="time" name="debut"></label>
        <label style="margin:0">à<input type="time" name="fin"></label>
      </div>
      <label style="margin-top:.6rem"><input type="checkbox" name="annuler" style="display:inline;width:auto"> Annuler les réservations déjà faites sur ces créneaux / heures</label>
      <button>Ajouter</button>
    </form>
    <?php $blocs = q('SELECT * FROM blocages WHERE jour >= ? ORDER BY jour, debut, id', [date('Y-m-d')])->fetchAll(); ?>
    <form method="post"><?= csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>Date</th><th>Fermé</th><th>Enfants déjà réservés</th><th></th></tr>
      <?php foreach ($blocs as $b): $touchees = reservations_bloquees($b); ?>
        <tr><td><?= h(ucfirst(date_fr($b['jour']))) ?></td><td><?= h(texte_blocage($b)) ?></td>
          <td><?php if ($touchees): ?><span class="badge warn"><?= count($touchees) ?></span>
              <?= h(implode(', ', array_map(fn($r) => $r['prenom'] . ' (' . $r['famille'] . ')', $touchees))) ?>
              <button class="light small" name="bloc_annuler" value="<?= (int)$b['id'] ?>" data-confirm="Annuler ces <?= count($touchees) ?> réservation(s) ? Pensez à prévenir les familles.">annuler ces réservations</button>
            <?php else: ?><span class="muted">aucun</span><?php endif ?></td>
          <td><button class="light small" name="bloc_suppr" value="<?= (int)$b['id'] ?>">retirer</button></td></tr>
      <?php endforeach ?>
      <?php if (!$blocs): ?><tr><td colspan="4" class="muted">Aucune fermeture partielle à venir.</td></tr><?php endif ?>
    </table></div></form>

    <h2>Fermer des journées entières</h2>
    <?php // jours consécutifs avec le même motif regroupés sur une ligne « du … au … »
    $plages = [];
    foreach ($liste as $f) {
        $der = $plages ? count($plages) - 1 : -1;
        if ($der >= 0 && $plages[$der]['motif'] === (string)$f['motif'] && date('Y-m-d', strtotime($plages[$der]['au'] . ' +1 day')) === $f['jour']) {
            $plages[$der]['au'] = $f['jour']; $plages[$der]['n'] += (int)$f['n'];
        } else $plages[] = ['du' => $f['jour'], 'au' => $f['jour'], 'motif' => (string)$f['motif'], 'n' => (int)$f['n']];
    } ?>
    <form method="post"><?= csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>Fermé</th><th>Motif</th><th></th></tr>
      <?php foreach ($plages as $pl): ?>
        <tr><td><?= $pl['du'] === $pl['au'] ? h(ucfirst(date_fr($pl['du'])))
                : 'du ' . h(date_fr($pl['du'])) . '<br>au ' . h(date_fr($pl['au'])) . ' <span class="muted">(' . (1 + (int)round((strtotime($pl['au']) - strtotime($pl['du'])) / 86400)) . ' jours)</span>' ?></td>
          <td><?= h($pl['motif']) ?><?= $pl['n'] ? ' <span class="badge warn">' . $pl['n'] . ' réservation(s)</span>' : '' ?></td>
          <td><button class="light small" name="suppr_plage" value="<?= h($pl['du'] . '|' . $pl['au']) ?>"
                data-confirm="Retirer cette fermeture ? Les parents pourront de nouveau réserver ces jours.">retirer</button></td></tr>
      <?php endforeach ?>
      <?php if (!$plages): ?><tr><td colspan="3" class="muted">Aucune fermeture à venir.</td></tr><?php endif ?>
    </table></div></form>
    <form method="post" class="card"><?= csrf_field() ?>
      <b>Ajouter une période de fermeture</b> <span class="muted">(congés, jours fériés…)</span>
      <div class="grid2" style="margin-top:.5rem">
        <label>Du<input type="date" name="du" required></label>
        <label>Au (inclus)<input type="date" name="au"></label>
        <label>Motif<input name="motif" placeholder="ex. congés"></label>
      </div>
      <label><input type="checkbox" name="annuler" checked style="display:inline;width:auto"> Annuler les réservations déjà faites sur ces jours</label>
      <button>Ajouter</button>
    </form>

    <h2>Vacances scolaires (zone A)</h2>
    <p class="muted">Pendant ces périodes, les parents ne voient plus les créneaux d'école (matin + trajet, midi, soir, mercredi) mais le créneau
      « Journée (vacances) », où ils indiquent leurs heures d'arrivée et de départ. Ce ne sont pas des fermetures : Amandine travaille.</p>
    <form method="post"><?= csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>Période</th><th>Du</th><th>Au (inclus)</th><th></th></tr>
      <?php foreach (q('SELECT * FROM vacances WHERE fin >= ? ORDER BY debut', [date('Y-m-d', strtotime('-60 day'))]) as $v): ?>
        <tr><td><?= h($v['nom']) ?></td><td><?= h(date('d/m/Y', strtotime($v['debut']))) ?></td><td><?= h(date('d/m/Y', strtotime($v['fin']))) ?></td>
          <td><button class="light small" name="vac_suppr" value="<?= (int)$v['id'] ?>" data-confirm="Retirer cette période de vacances ?">retirer</button></td></tr>
      <?php endforeach ?>
    </table></div></form>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="vac_ajout">
      <b>Ajouter des vacances</b>
      <div class="grid2" style="margin-top:.5rem">
        <label>Nom<input name="nom" placeholder="ex. Toussaint 2027"></label>
        <label>Premier jour de vacances<input type="date" name="du" required></label>
        <label>Dernier jour de vacances<input type="date" name="au" required></label>
      </div>
      <button>Ajouter</button>
    </form>
    <?php
    break;

// ================= RÉGLAGES =================
case 'reglages':
    if (post()) {
        if (($_POST['act'] ?? '') === 'cle_api') {
            $cle = trim((string)($_POST['anthropic_key'] ?? ''));
            if (isset($_POST['noms_ignores'])) set_setting('noms_ignores', $P('noms_ignores'));
            if (isset($_POST['supprimer'])) { set_setting('anthropic_key', ''); flash('Clé API supprimée.'); }
            elseif ($cle === '') flash('Réglages de lecture des photos enregistrés.');
            elseif (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $cle)) flash('Cette clé ne ressemble pas à une clé Anthropic (elle commence par sk-ant-).', 'err');
            else { set_setting('anthropic_key', $cle); flash('Clé API enregistrée.'); }
            redirect('admin.php?p=reglages#cle');
        }
        if (($_POST['act'] ?? '') === 'ics_new') {
            set_setting('ics_token', bin2hex(random_bytes(16)));
            flash("Nouveau lien d'agenda créé. L'ancien ne fonctionne plus : réabonnez le téléphone avec le nouveau lien.");
            redirect('admin.php?p=reglages#agenda');
        }
        if (in_array($_POST['act'] ?? '', ['ntfy_activer', 'ntfy_test', 'ntfy_couper'], true)) {
            if ($_POST['act'] === 'ntfy_activer') { set_setting('ntfy_topic', 'garde-' . bin2hex(random_bytes(8))); flash('Notifications activées : abonnez le téléphone au sujet ci-dessous.'); }
            elseif ($_POST['act'] === 'ntfy_couper') { set_setting('ntfy_topic', ''); flash('Notifications ntfy désactivées.'); }
            else {
                $ok = alerte_ntfy('🔔 Test – ' . nom_affiche(), 'Les notifications de réservation fonctionnent.', url_base() . 'admin.php');
                flash($ok ? 'Notification de test envoyée : elle doit apparaître sur le téléphone.' : "L'envoi vers ntfy a échoué.", $ok ? 'ok' : 'err');
            }
            redirect('admin.php?p=reglages#ntfy');
        }
        if (in_array($_POST['act'] ?? '', ['wa_ajout', 'wa_retirer', 'wa_test'], true)) {
            $liste = destinataires_whatsapp();
            if ($_POST['act'] === 'wa_ajout') {
                $d = ['nom' => mb_substr($P('nom_wa'), 0, 40), 'tel' => tel_international($P('tel_wa')), 'cle' => preg_replace('/\s/', '', $P('cle_wa'))];
                if (strlen($d['tel']) < 10 || $d['cle'] === '') flash('Il faut le numéro WhatsApp et la clé CallMeBot.', 'err');
                else {
                    $liste = array_values(array_filter($liste, fn($x) => $x['tel'] !== $d['tel']));
                    $liste[] = $d;
                    set_setting('whatsapp', json_encode($liste));
                    flash('Téléphone ajouté. Réponse de CallMeBot – ' . implode(' · ', alerte_whatsapp('🔔 Test des alertes de ' . nom_affiche() . ' : tout fonctionne.', $d)));
                }
            } elseif ($_POST['act'] === 'wa_retirer') {
                set_setting('whatsapp', json_encode(array_values(array_filter($liste, fn($x) => $x['tel'] !== $P('tel_wa')))));
                flash('Téléphone retiré des alertes.');
            } else {
                flash($liste ? 'Test envoyé. Réponses de CallMeBot – ' . implode(' · ', alerte_whatsapp('🔔 Test des alertes de ' . nom_affiche() . ' : tout fonctionne.')) : 'Aucun téléphone configuré.', $liste ? 'ok' : 'err');
            }
            redirect('admin.php?p=reglages#whatsapp');
        }
        if (($_POST['act'] ?? '') === 'test_mail') {
            $dest = setting('email_contact') ?: setting('admin_email');
            $ok = envoyer_mail($dest, 'Test – ' . nom_affiche(), "Ceci est un email de test envoyé depuis le logiciel de garde.\nSi vous le recevez, l'envoi des factures par email fonctionne.\n");
            flash($ok ? "Email de test envoyé à $dest : vérifiez la boîte de réception (et les spams)." : "Le serveur a refusé l'envoi de l'email.", $ok ? 'ok' : 'err');
            redirect('admin.php?p=reglages');
        }
        if (($_POST['act'] ?? '') === 'compte') {
            $email = strtolower($P('admin_email'));
            if (!password_verify((string)($_POST['actuel'] ?? ''), setting('admin_pass'))) {
                flash('Mot de passe actuel incorrect.', 'err');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || q('SELECT 1 FROM familles WHERE email = ?', [$email])->fetch()) {
                flash('Email invalide ou déjà utilisé par une famille.', 'err');
            } else {
                set_setting('admin_email', $email);
                $nouveau = (string)($_POST['nouveau'] ?? '');
                if ($nouveau !== '') {
                    if (strlen($nouveau) < 8) { flash('Nouveau mot de passe trop court (8 caractères min).', 'err'); redirect('admin.php?p=reglages'); }
                    set_setting('admin_pass', password_hash($nouveau, PASSWORD_DEFAULT));
                }
                flash('Compte mis à jour.');
            }
        } else {
            foreach (['nom', 'nom_affiche', 'adresse', 'ville', 'tel', 'email_contact', 'siret', 'num_sap', 'titulaire', 'iban', 'bic', 'domiciliation', 'mention',
                      'accroche', 'presentation', 'places_dispo'] as $k) set_setting($k, $P($k));
            set_setting('afficher_tarifs', isset($_POST['afficher_tarifs']) ? '1' : '0');
            set_setting('delai_jours', (string)max(0, (int)$P('delai_jours', '1')));
            set_setting('prochain_numero', (string)max(1, (int)$P('prochain_numero', '1')));
            set_setting('prochain_contrat', (string)max(1, (int)$P('prochain_contrat', '111')));
            set_setting('prochain_avoir', (string)max(1, (int)$P('prochain_avoir', '1')));
            foreach (array_keys(TARIFS_DEFAUT) as $k) {
                $v = str_replace(',', '.', $P($k));
                if (is_numeric($v)) set_setting($k, (string)(float)$v);
            }
            foreach (['nuit_debut', 'nuit_fin'] as $k) if ($hv = heure_valide($P($k))) set_setting($k, $hv);
            flash('Réglages enregistrés.');
        }
        redirect('admin.php?p=reglages');
    }
    page_debut('Réglages');
    if (setting('sauvegarde_token') === '') set_setting('sauvegarde_token', bin2hex(random_bytes(16))); // lien de la tâche planifiée PC → NAS
    ?>
    <script>document.querySelector('main').classList.add('large')</script><!-- page plus large : 3 colonnes d'encadrés sur PC -->
    <?php $tf = fn(string $k) => h(number_format(tarif($k), 2, ',', '')); ?>
    <form method="post"><?= csrf_field() ?>
    <div class="reg-grille"><!-- encadrés côte à côte sur grand écran, l'un sous l'autre sur téléphone -->
    <section class="card">
      <h2 style="margin-top:0">Tarifs</h2>
      <div class="grid2">
        <label>Heure en semaine (lun–ven) €<input name="tarif_semaine" value="<?= $tf('tarif_semaine') ?>" inputmode="decimal"></label>
        <label>Heure le week-end €<input name="tarif_weekend" value="<?= $tf('tarif_weekend') ?>" inputmode="decimal"></label>
        <label>Heure jour férié €<input name="tarif_ferie" value="<?= $tf('tarif_ferie') ?>" inputmode="decimal"></label>
        <label>Petit-déjeuner €<input name="petitdej" value="<?= $tf('petitdej') ?>" inputmode="decimal"></label>
        <label>Repas (standard) €<input name="repas" value="<?= $tf('repas') ?>" inputmode="decimal"></label>
        <label>Repas (tarif spécial) €<input name="repas_special" value="<?= $tf('repas_special') ?>" inputmode="decimal"></label>
        <label>Goûter (standard) €<input name="gouter" value="<?= $tf('gouter') ?>" inputmode="decimal"></label>
        <label>Goûter (tarif spécial) €<input name="gouter_special" value="<?= $tf('gouter_special') ?>" inputmode="decimal"></label>
        <label>Heure de nuit €<input name="tarif_nuit" value="<?= $tf('tarif_nuit') ?>" inputmode="decimal"></label>
        <label>Nuit : de … à … (le lendemain)
          <span style="display:flex;gap:.4rem"><input type="time" name="nuit_debut" value="<?= h(setting('nuit_debut', '20:00')) ?>" aria-label="Début de la nuit">
            <input type="time" name="nuit_fin" value="<?= h(setting('nuit_fin', '07:00')) ?>" aria-label="Fin de la nuit"></span></label>
      </div>
      <p class="muted">Tarif horaire personnalisé, tarif spécial repas et « sans repas » se règlent dans la fiche de chaque enfant. Jours fériés reconnus automatiquement.</p>
      <button>Enregistrer</button>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Activité</h2>
      <div class="grid2">
        <label>Nom légal (factures, contrats, attestations)<input name="nom" value="<?= h(setting('nom')) ?>" required></label>
        <label>Nom affiché (appli, emails, présentation)<input name="nom_affiche" value="<?= h(setting('nom_affiche')) ?>" placeholder="ex. Nounou Amandine"></label>
        <label>Téléphone<input name="tel" value="<?= h(setting('tel')) ?>"></label>
        <label>Email de contact<input type="email" name="email_contact" value="<?= h(setting('email_contact')) ?>"></label>
        <label>Adresse<textarea name="adresse"><?= h(setting('adresse')) ?></textarea></label>
        <label>Ville (« Bonneville, le … »)<input name="ville" value="<?= h(setting('ville', 'Bonneville')) ?>"></label>
        <label>SIRET<input name="siret" value="<?= h(setting('siret')) ?>"></label>
        <label>N° de déclaration SAP ou d'agrément (optionnel, imprimé sous le SIRET)<input name="num_sap" value="<?= h(setting('num_sap')) ?>" placeholder="ex. Déclaration SAP n° SAP123456789"></label>
      </div>
      <button>Enregistrer</button>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Factures et réservations</h2>
      <div class="grid2">
        <label>Prochain numéro de facture<input type="number" min="1" name="prochain_numero" value="<?= h(setting('prochain_numero', '1')) ?>"></label>
        <label>Prochain numéro de devis / contrat<input type="number" min="1" name="prochain_contrat" value="<?= h(setting('prochain_contrat', '111')) ?>"></label>
        <label>Prochain numéro d'avoir (AV-…)<input type="number" min="1" name="prochain_avoir" value="<?= h(setting('prochain_avoir', '1')) ?>"></label>
        <label>Mention en bas de facture<textarea name="mention" placeholder="TVA non applicable, art. 293 B du CGI"><?= h(setting('mention')) ?></textarea></label>
        <label>Délai de réservation / annulation (jours avant)<input type="number" min="0" name="delai_jours" value="<?= h(setting('delai_jours', '1')) ?>">
          <span class="muted">1 = jusqu'à la veille, 0 = jusqu'au jour même</span></label>
      </div>
      <button>Enregistrer</button>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Page de présentation publique</h2>
      <p class="muted">Adresse à partager : <a href="presentation.php" target="_blank"><?= h(url_base()) ?>presentation.php</a></p>
      <div class="grid2">
        <label>Phrase d'accroche<input name="accroche" value="<?= h(setting('accroche')) ?>" placeholder="Garde d'enfants périscolaire à Bonneville – matin, midi, soir, mercredi et vacances"></label>
        <label>Places disponibles (bandeau jaune, vide = masqué)<input name="places_dispo" value="<?= h(setting('places_dispo')) ?>" placeholder="ex. 2 places disponibles pour janvier"></label>
      </div>
      <label>Texte de présentation<textarea name="presentation" rows="6"><?= h(setting('presentation', PRESENTATION_DEFAUT)) ?></textarea></label>
      <label><input type="checkbox" name="afficher_tarifs" <?= setting('afficher_tarifs') === '1' ? 'checked' : '' ?> style="display:inline;width:auto"> Afficher les tarifs sur la page</label>
      <button>Enregistrer</button>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Coordonnées bancaires (sur les factures)</h2>
      <div class="grid2">
        <label>Titulaire<input name="titulaire" value="<?= h(setting('titulaire')) ?>"></label>
        <label>IBAN<input name="iban" value="<?= h(setting('iban')) ?>"></label>
        <label>BIC<input name="bic" value="<?= h(setting('bic')) ?>"></label>
        <label>Domiciliation<input name="domiciliation" value="<?= h(setting('domiciliation')) ?>"></label>
      </div>
      <button>Enregistrer</button>
    </section>
    </div>
    </form>

    <div class="reg-grille" style="margin-top:1rem">
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="compte">
      <h2 style="margin-top:0">Mon compte</h2>
      <label>Email de connexion<input type="email" name="admin_email" value="<?= h(setting('admin_email')) ?>" required></label>
      <label>Nouveau mot de passe (laisser vide pour ne pas changer)<input type="password" name="nouveau" autocomplete="new-password"></label>
      <label>Mot de passe actuel<input type="password" name="actuel" required autocomplete="current-password"></label>
      <button>Mettre à jour</button>
    </form>

    <?php if (setting('ics_token') === '') set_setting('ics_token', bin2hex(random_bytes(16)));
    $ics = url_base() . 'agenda.php?k=' . setting('ics_token'); ?>
    <div class="card" id="agenda"><h2 style="margin-top:0">Agenda du téléphone</h2>
      <p class="muted">Les créneaux réservés (avec les prénoms et les allergies), les fermetures et les anniversaires s'affichent dans l'agenda de votre téléphone.
        Lien secret : ne le partagez pas.</p>
      <input value="<?= h($ics) ?>" readonly onclick="this.select()" style="width:100%">
      <div class="row" style="margin:.6rem 0">
        <button type="button" class="light small" onclick="navigator.clipboard.writeText('<?= h($ics) ?>'); this.textContent='Lien copié ✓'">Copier le lien</button>
        <a class="btn light small" href="<?= h(preg_replace('#^https?://#', 'webcal://', $ics)) ?>">Ouvrir sur iPhone / Mac</a>
      </div>
      <details><summary><b>Mode d'emploi</b></summary>
        <p><b>Google Agenda (Android)</b> : sur un ordinateur, ouvrez calendar.google.com → à gauche « Autres agendas » → <b>+</b> → « À partir de l'URL » → collez le lien → « Ajouter l'agenda ». Il apparaît ensuite sur le téléphone (mise à jour toutes les quelques heures par Google).</p>
        <p><b>iPhone</b> : ouvrez cette page sur l'iPhone et touchez « Ouvrir sur iPhone / Mac » → « S'abonner ». Ou : Réglages → Calendrier → Comptes → Ajouter un compte → Autre → « Ajouter un calendrier avec abonnement » → collez le lien.</p>
      </details>
      <form method="post" style="margin-top:.6rem"><?= csrf_field() ?><input type="hidden" name="act" value="ics_new">
        <button class="light small" data-confirm="Créer un nouveau lien ? L'ancien ne marchera plus et il faudra réabonner le téléphone.">Générer un nouveau lien (si l'ancien a été partagé)</button></form>
    </div>

    <form method="post" class="card" id="cle"><?= csrf_field() ?><input type="hidden" name="act" value="cle_api">
      <h2 style="margin-top:0">Lecture des photos (clé API Claude)</h2>
      <p class="muted">Sert uniquement au bouton « 📷 Importer une feuille d'heures ». Clé à créer sur console.anthropic.com → Settings → API Keys (environ 1 à 2 centimes par photo).</p>
      <?php $k = setting('anthropic_key'); ?>
      <p>Clé actuelle : <b><?= $k ? 'enregistrée (…' . h(substr($k, -4)) . ')' : 'aucune' ?></b></p>
      <label>Nouvelle clé (laisser vide pour garder l'actuelle)<input type="password" name="anthropic_key" autocomplete="off" placeholder="sk-ant-…"></label>
      <label>Prénoms à ne jamais importer (rendez-vous personnels), séparés par des virgules
        <input name="noms_ignores" value="<?= h(setting('noms_ignores', 'Enzo, Amandine, Christophe')) ?>"></label>
      <button>Enregistrer</button>
      <?php if ($k): ?><button class="light" name="supprimer" value="1" formnovalidate data-confirm="Supprimer la clé ?">Supprimer</button><?php endif ?>
    </form>

    <div class="card" id="ntfy"><h2 style="margin-top:0">Notifications sur le téléphone (ntfy)</h2>
      <p class="muted">Une notification arrive sur le téléphone quand une famille réserve, annule ou change des heures, et pour chaque demande de dernière minute.
        Appli gratuite, sans compte. Toucher la notification ouvre le planning.</p>
      <?php $topic = setting('ntfy_topic'); ?>
      <?php if ($topic === ''): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ntfy_activer"><button>Activer les notifications</button></form>
      <?php else: ?>
        <p>Sujet à recopier dans l'appli (secret, ne pas le partager) :</p>
        <input value="<?= h($topic) ?>" readonly onclick="this.select()" style="width:100%;font-size:1.1rem;font-weight:600">
        <div class="row" style="margin:.6rem 0">
          <button type="button" class="light small" onclick="navigator.clipboard.writeText('<?= h($topic) ?>'); this.textContent='Copié ✓'">Copier</button>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="ntfy_test"><button class="small">Envoyer une notification de test</button></form>
        </div>
        <details open><summary><b>Installer sur le téléphone (2 minutes)</b></summary>
          <ol>
            <li>Installer l'appli <b>ntfy</b> : <a href="https://play.google.com/store/apps/details?id=io.heckel.ntfy" target="_blank" rel="noopener">Android (Play Store)</a> ·
              <a href="https://apps.apple.com/app/ntfy/id1625396347" target="_blank" rel="noopener">iPhone (App Store)</a>.</li>
            <li>Ouvrir ntfy → bouton <b>+</b> → « Topic name » : coller le sujet ci-dessus → <b>Subscribe</b>.</li>
            <li>Accepter les notifications, puis cliquer ici sur « Envoyer une notification de test ».</li>
          </ol>
        </details>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ntfy_couper"><button class="light small" data-confirm="Désactiver les notifications ntfy ?">Désactiver</button></form>
      <?php endif ?>
    </div>

    <div class="card" id="whatsapp"><h2 style="margin-top:0">Alertes WhatsApp</h2>
      <p class="muted">Un message WhatsApp part quand une famille réserve, annule ou change des heures, et pour chaque demande de dernière minute.
        Sans ntfy ni WhatsApp configuré, les alertes de réservation arrivent par email.</p>
      <?php $wa = destinataires_whatsapp(); ?>
      <?php foreach ($wa as $d): ?>
        <form method="post" class="row" style="margin:.3rem 0"><?= csrf_field() ?><input type="hidden" name="act" value="wa_retirer"><input type="hidden" name="tel_wa" value="<?= h($d['tel']) ?>">
          <span><b><?= h($d['nom'] ?: 'Téléphone') ?></b> · +<?= h($d['tel']) ?></span>
          <button class="light small" data-confirm="Ne plus envoyer les alertes à ce téléphone ?">Retirer</button></form>
      <?php endforeach ?>
      <?php if ($wa): ?>
        <form method="post" style="margin:.4rem 0"><?= csrf_field() ?><input type="hidden" name="act" value="wa_test"><button class="light small">Envoyer un test</button></form>
      <?php else: ?><p><b>Aucun téléphone pour l'instant.</b></p><?php endif ?>
      <details<?= $wa ? '' : ' open' ?>><summary><b>➕ Ajouter un téléphone</b></summary>
        <p class="muted">1. Sur le téléphone, ouvrez <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">la page CallMeBot</a> (service gratuit) :
          enregistrez le numéro indiqué dans les contacts, puis envoyez-lui sur WhatsApp « I allow callmebot to send me messages ».<br>
          2. CallMeBot répond avec une clé (« apikey ») : recopiez-la ci-dessous. Si ce téléphone reçoit déjà les alertes du gîte, c'est la même clé.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="wa_ajout">
          <div class="grid2">
            <label>Prénom<input name="nom_wa" placeholder="Amandine"></label>
            <label>Numéro WhatsApp<input name="tel_wa" inputmode="tel" placeholder="06 12 34 56 78" required></label>
          </div>
          <label>Clé CallMeBot (apikey)<input name="cle_wa" autocomplete="off" required></label>
          <button>Ajouter et envoyer un test</button>
        </form>
      </details>
    </div>

    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="test_mail">
      <h2 style="margin-top:0">Emails</h2>
      <p class="muted">Les factures et relances partent de l'adresse « noreply » du site ; les réponses des parents arrivent sur l'email de contact.</p>
      <button class="light">Envoyer un email de test à <?= h(setting('email_contact') ?: setting('admin_email')) ?></button>
    </form>

    <div class="card"><h2 style="margin-top:0">Sauvegarde</h2>
      <p class="muted">Téléchargez régulièrement une copie de toutes les données.</p>
      <a class="btn light" href="?p=sauvegarde">Télécharger la sauvegarde</a>
      <?php $copies = glob(dirname(DB_FILE) . '/sauvegardes/amandine-*.sqlite') ?: []; rsort($copies); ?>
      <p class="muted" style="margin-top:.8rem"><b>Automatique :</b> une copie est faite chaque jour sur le serveur
        (<?= count($copies) ?> copie(s) gardée(s) : les 30 derniers jours + le 1er de chaque mois pour toujours<?= $copies ? ', la plus récente du ' . h(date('d/m/Y', strtotime(substr(basename($copies[0]), 9, 10)))) : '' ?>).
        <?php if (setting('sauvegarde_derniere')): ?><br>Dernière copie récupérée sur le NAS : <b><?= h(date('d/m/Y à H:i', strtotime(setting('sauvegarde_derniere')))) ?></b>.<?php endif ?></p>
      <details><summary class="muted">Lien secret de la copie automatique (pour le PC / NAS)</summary>
        <input id="lien-sauvegarde" value="<?= h(url_base() . 'sauvegarde.php?k=' . setting('sauvegarde_token')) ?>" readonly onclick="this.select()" style="width:100%"></details>
    </div>
    </div>
    <?php
    break;

case 'sauvegarde':
    db();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="sauvegarde-' . date('Y-m-d') . '.sqlite"');
    header('Content-Length: ' . filesize(DB_FILE));
    readfile(DB_FILE);
    exit;

default:
    redirect('admin.php?p=jour');
}

page_fin();
