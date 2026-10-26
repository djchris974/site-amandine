<?php
// Devis / contrat imprimable, avec acceptation en ligne (« Bon pour accord ») par les parents
require __DIR__ . '/inc.php';

$admin = is_admin();
$fid = $admin ? 0 : require_parent();
$c = q('SELECT * FROM contrats WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$c || (!$admin && ((int)$c['famille_id'] !== $fid || $c['statut'] === 'brouillon'))) redirect($admin ? 'admin.php?p=contrats' : 'parent.php?v=contrats');
$fam = q('SELECT * FROM familles WHERE id = ?', [$c['famille_id']])->fetch();
$doc = $c['type'] === 'devis' ? 'Devis' : 'Contrat';
$erreur = '';

if (!$admin && !vue_amandine() && $c['statut'] === 'envoye' && post()) { // pas d'accord signé pendant l'aperçu d'Amandine
    $nom = trim((string)($_POST['nom'] ?? ''));
    if (mb_strlen($nom) < 3 || empty($_POST['accord'])) {
        $erreur = 'Cochez la case et tapez votre nom et prénom pour donner votre accord.';
    } else {
        q("UPDATE contrats SET statut = 'accepte', accepte_le = datetime('now', 'localtime'), accepte_par = ?, accepte_ip = ? WHERE id = ? AND statut = 'envoye'",
            [mb_substr($nom, 0, 120), $_SERVER['REMOTE_ADDR'] ?? '', $c['id']]);
        $dest = setting('email_contact') ?: setting('admin_email');
        if ($dest) envoyer_mail($dest, "$doc N° {$c['numero']} accepté – {$fam['nom']}",
            "$nom a donné son accord en ligne pour le " . strtolower($doc) . " N° {$c['numero']} (" . ($c['titre'] ?: $fam['nom']) . ").\n\n"
            . url_base() . 'contrat.php?id=' . $c['id'] . "\n", $fam['email']);
        redirect('contrat.php?id=' . $c['id']);
    }
}

$k = calcul_contrat($c);
$plusieurs = count(array_unique(array_column($k['lignes'], 'enfant'))) > 1;
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $doc ?> <?= (int)$c['numero'] ?> – <?= h($fam['nom']) ?></title>
<style>
  body { font: 13px/1.4 system-ui, "Segoe UI", Roboto, sans-serif; color: #222; background: #f4f4f4; margin: 0; }
  .page { background: #fff; max-width: 800px; margin: 20px auto; padding: 36px; position: relative; }
  .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
  h1 { font-size: 26px; margin: 0 0 14px; letter-spacing: 2px; text-transform: uppercase; }
  h2 { font-size: 15px; margin: 22px 0 6px; text-transform: uppercase; }
  .titre { text-align: center; font-weight: 700; font-size: 15px; margin: 22px 0 6px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; vertical-align: top; }
  th { background: #e9f1ef; font-size: 11px; text-transform: uppercase; }
  .r { text-align: right; } .muted { color: #777; font-size: 12px; }
  .sous td { font-weight: 700; background: #f6f6f6; }
  .net { margin-top: 18px; font-size: 18px; font-weight: 700; display: flex; justify-content: space-between; border: 2px solid #222; padding: 10px 14px; }
  .cond { white-space: pre-line; margin-top: 16px; }
  .accord { margin-top: 26px; border: 2px dashed #999; padding: 14px; min-height: 70px; }
  .ok { border: 2px solid #2f7d6d; color: #1e5a4e; background: #e2f1ec; }
  .form { margin-top: 20px; border: 2px solid #2f7d6d; border-radius: 8px; padding: 16px; background: #f4faf8; }
  .form input[type=text] { font: inherit; padding: 8px; width: 100%; max-width: 360px; border: 1px solid #aaa; border-radius: 6px; margin: 6px 0 10px; }
  .form button, .bar a, .bar button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 0; background: #2f7d6d; color: #fff; text-decoration: none; cursor: pointer; }
  .err { color: #b4462b; font-weight: 600; }
  .bar { max-width: 800px; margin: 20px auto 0; display: flex; gap: 10px; }
  .brouillon { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 80px; color: rgba(200,0,0,.12); transform: rotate(-20deg); pointer-events: none; }
  @media print { body { background: #fff; } .bar, .form { display: none; } .page { margin: 0; padding: 0; max-width: none; } }
</style></head>
<body>
<div class="bar"><button onclick="window.print()">Imprimer / PDF</button>
  <a href="<?= $admin ? 'admin.php?p=contrat&id=' . (int)$c['id'] : 'parent.php?v=contrats' ?>">Retour</a></div>
<div class="page">
  <?php if ($c['statut'] === 'brouillon'): ?><div class="brouillon">BROUILLON</div><?php endif ?>
  <h1><?= $doc ?></h1>
  <div class="head">
    <div>
      <b><?= h(setting('nom')) ?></b><br><?= setting('nom_affiche') && setting('nom_affiche') !== setting('nom') ? '<i>' . h(setting('nom_affiche')) . '</i><br>' : '' ?>
      <?= nl2br(h(setting('adresse'))) ?><br>
      <?= setting('tel') ? 'Tél : ' . h(setting('tel')) . '<br>' : '' ?>
      <?= setting('email_contact') ? 'Mail : ' . h(setting('email_contact')) . '<br>' : '' ?>
      <?= setting('siret') ? 'SIRET : ' . h(setting('siret')) : '' ?><?= ligne_sap() ?>
    </div>
    <div>
      <b><?= $doc ?> n° <?= (int)$c['numero'] ?></b><br>
      <?= h(setting('ville', 'Bonneville')) ?>, le <?= h(date('d/m/Y', strtotime($c['date'] ?: 'now'))) ?><br><br>
      <b><?= nl2br(h($fam['adresse'] ?: $fam['nom'])) ?></b><br>
      <?= h($fam['tel']) ?><br><?= h($fam['email']) ?>
    </div>
  </div>

  <div class="titre"><?= h($c['titre'] ?: 'Garderie') ?></div>

  <table>
    <tr><th>Jour de garde</th><?= $plusieurs ? '<th>Enfant</th>' : '' ?><th>Horaires</th><th class="r">Durée</th><th class="r">Tarif/h</th><th class="r">Total HT</th></tr>
    <?php foreach ($k['lignes'] as $l): ?>
      <tr><td><?= h($l['jour']) ?><?= !$plusieurs && $l['enfant'] ? ' <span class="muted">(' . h($l['enfant']) . ')</span>' : '' ?></td><?= $plusieurs ? '<td>' . h($l['enfant']) . '</td>' : '' ?>
        <td><?= h($l['horaires']) ?></td><td class="r"><?= duree_fr($l['heures']) ?></td><td class="r"><?= euro($l['tarif']) ?></td><td class="r"><?= euro($l['total']) ?></td></tr>
    <?php endforeach ?>
    <?php if (!$k['lignes']): ?><tr><td colspan="6" class="muted">Aucune ligne.</td></tr><?php endif ?>
    <tr class="sous"><td colspan="<?= $plusieurs ? 3 : 2 ?>">Total heures : <?= duree_fr($k['heures']) ?></td><td colspan="2" class="r">TOTAL GARDERIE</td><td class="r"><?= euro($k['total_garde']) ?></td></tr>
  </table>

  <?php if ($k['extras']): ?>
    <h2>Repas / goûter / petit-déjeuner</h2>
    <table>
      <tr><th>Prestation</th><th>Dates</th><th class="r">Qté</th><th class="r">Prix unit.</th><th class="r">Total HT</th></tr>
      <?php foreach ($k['extras'] as $x): ?>
        <tr><td><?= h($x['type']) ?></td><td><?= h($x['dates']) ?></td><td class="r"><?= $x['qte'] ?></td><td class="r"><?= euro($x['pu']) ?></td><td class="r"><?= euro($x['total']) ?></td></tr>
      <?php endforeach ?>
      <tr class="sous"><td colspan="4" class="r">TOTAL REPAS / GOÛTER / PETIT-DÉJ.</td><td class="r"><?= euro($k['total_extras']) ?></td></tr>
    </table>
  <?php endif ?>

  <div class="net"><span>TOTAL NET</span><span><?= euro($k['total']) ?></span></div>

  <?php if ($c['conditions']): ?><div class="cond"><?= h($c['conditions']) ?></div><?php endif ?>
  <p>Mode de règlement : comptant dès réception de la facture.</p>
  <?php if (setting('mention')): ?><p class="muted"><?= nl2br(h(setting('mention'))) ?></p><?php endif ?>

  <?php if ($c['statut'] === 'accepte'): ?>
    <div class="accord ok"><b>✔ Bon pour accord</b> — accepté en ligne le <?= h(date('d/m/Y à H:i', strtotime($c['accepte_le']))) ?>
      par <b><?= h($c['accepte_par']) ?></b><?= $admin && $c['accepte_ip'] ? ' <span class="muted">(adresse IP ' . h($c['accepte_ip']) . ')</span>' : '' ?>.</div>
  <?php else: ?>
    <div class="accord">Date et signature avec la mention « Bon pour accord » :</div>
  <?php endif ?>

  <?php if (!$admin && $c['statut'] === 'envoye'): ?>
    <form method="post" class="form"><?= csrf_field() ?>
      <b>Donner mon accord en ligne</b>
      <?php if ($erreur): ?><p class="err"><?= h($erreur) ?></p><?php endif ?>
      <p><label><input type="checkbox" name="accord" value="1"> J'ai lu ce <?= strtolower($doc) ?> et je donne mon accord (« Bon pour accord »).</label></p>
      <label>Nom et prénom<br><input type="text" name="nom" required autocomplete="name"></label><br>
      <button>Bon pour accord</button>
      <p class="muted">La date, l'heure et votre nom seront enregistrés et valent signature.</p>
    </form>
  <?php endif ?>
</div>
</body></html>
