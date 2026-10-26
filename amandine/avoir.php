<?php
// Avoir (facture rectificative) imprimable
require __DIR__ . '/inc.php';

$admin = is_admin() || acces_robot();
$fid = $admin ? 0 : require_parent();
$a = q('SELECT * FROM avoirs WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$a || (!$admin && (int)$a['famille_id'] !== $fid)) redirect($admin ? 'admin.php?p=factures' : 'parent.php?v=factures');
$fam = q('SELECT * FROM familles WHERE id = ?', [$a['famille_id']])->fetch();
$ancienne = $a['ancienne'] ? json_decode($a['ancienne'], true) : null;
$detail = $ancienne && $ancienne['detail'] ? json_decode($ancienne['detail'], true) : null;
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avoir AV-<?= (int)$a['numero'] ?> – <?= h($fam['nom']) ?></title>
<style>
  body { font: 13px/1.4 system-ui, "Segoe UI", Roboto, sans-serif; color: #222; background: #f4f4f4; margin: 0; }
  .page { background: #fff; max-width: 800px; margin: 20px auto; padding: 36px; }
  .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
  h1 { font-size: 26px; margin: 0 0 14px; letter-spacing: 2px; }
  h2 { font-size: 14px; margin: 22px 0 6px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; }
  th { background: #e9f1ef; font-size: 11px; text-transform: uppercase; }
  .r { text-align: right; } .muted { color: #777; font-size: 12px; }
  .net { margin-top: 18px; font-size: 18px; font-weight: 700; display: flex; justify-content: space-between; border: 2px solid #222; padding: 10px 14px; }
  .bar { max-width: 800px; margin: 20px auto 0; display: flex; gap: 10px; }
  .bar a, .bar button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 0; background: #2f7d6d; color: #fff; text-decoration: none; cursor: pointer; }
  @page { size: A4; margin: 10mm 12mm; }
  @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; padding: 0; max-width: none; } }
</style></head>
<body>
<div class="bar"><button onclick="window.print()">Imprimer / PDF</button>
  <a href="<?= $admin ? 'admin.php?p=factures&m=' . h($a['mois']) : 'parent.php?v=factures' ?>">Retour</a></div>
<div class="page">
  <h1>AVOIR</h1>
  <div class="head">
    <div>
      <b><?= h(setting('nom')) ?></b><br><?= setting('nom_affiche') && setting('nom_affiche') !== setting('nom') ? '<i>' . h(setting('nom_affiche')) . '</i><br>' : '' ?>
      <?= nl2br(h(setting('adresse'))) ?><br>
      <?= setting('tel') ? 'Tél : ' . h(setting('tel')) . '<br>' : '' ?>
      <?= setting('siret') ? 'SIRET : ' . h(setting('siret')) : '' ?><?= ligne_sap() ?>
    </div>
    <div>
      <b>Avoir N° AV-<?= (int)$a['numero'] ?></b><br>
      <?= h(setting('ville', 'Bonneville')) ?>, le <?= h(date('d/m/Y', strtotime($a['date']))) ?><br>
      Sur facture N° <?= (int)$a['facture_numero'] ?><?= $ancienne ? ' du ' . h(date('d/m/Y', strtotime($ancienne['date']))) : '' ?><br><br>
      <b><?= nl2br(h($fam['adresse'] ?: $fam['nom'])) ?></b>
    </div>
  </div>

  <table>
    <tr><th>Désignation</th><th>Période</th><th class="r">Montant</th></tr>
    <tr><td><?= h($a['motif']) ?></td><td><?= h(mois_fr($a['mois'])) ?></td><td class="r">−<?= euro($a['montant']) ?></td></tr>
  </table>
  <div class="net"><span>MONTANT DE L'AVOIR</span><span>−<?= euro($a['montant']) ?></span></div>

  <?php if ($a['type'] === 'annulation'): ?>
    <p>Cet avoir annule la facture N° <?= (int)$a['facture_numero'] ?>. Une nouvelle facture corrigée a été émise pour la même période.</p>
  <?php else: ?>
    <p>Ce montant est déduit de la facture N° <?= (int)$a['facture_numero'] ?>.</p>
  <?php endif ?>

  <?php if ($detail): ?>
    <h2>Rappel de la facture annulée N° <?= (int)$a['facture_numero'] ?></h2>
    <table>
      <tr><th>Jour</th><th>Enfant</th><th>Horaires</th><th class="r">Durée</th><th class="r">Total</th></tr>
      <?php foreach ($detail['jours'] as $j): ?>
        <tr><td><?= h(date('d/m/Y', strtotime($j['jour']))) ?></td><td><?= h($j['prenom']) ?></td><td><?= h($j['horaires']) ?></td>
          <td class="r"><?= duree_fr((float)$j['heures']) ?></td><td class="r"><?= euro($j['total']) ?></td></tr>
      <?php endforeach ?>
      <?php foreach ($detail['extras'] as $x): ?>
        <tr><td colspan="3"><?= h($x['type']) ?> – <?= h($x['prenom']) ?> (<?= h($x['dates']) ?>)</td><td class="r"><?= (int)$x['qte'] ?> × <?= euro($x['pu']) ?></td><td class="r"><?= euro($x['total']) ?></td></tr>
      <?php endforeach ?>
      <tr><td colspan="4" class="r"><b>Total facture annulée</b></td><td class="r"><b><?= euro($detail['total']) ?></b></td></tr>
    </table>
  <?php endif ?>
  <?php if (setting('mention')): ?><p class="muted"><?= nl2br(h(setting('mention'))) ?></p><?php endif ?>
</div>
</body></html>
