<?php
// Facture mensuelle imprimable (Imprimer → « Enregistrer au format PDF »), même présentation que le modèle Excel
require __DIR__ . '/inc.php';

$admin = is_admin() || acces_robot();
$fid = $admin ? (int)($_GET['f'] ?? 0) : require_parent();
$ym = mois_valide($_GET['m'] ?? null);
$fam = q('SELECT * FROM familles WHERE id = ?', [$fid])->fetch();
if (!$fam) redirect('index.php');
$fac = q('SELECT * FROM factures WHERE famille_id = ? AND mois = ?', [$fid, $ym])->fetch();
if (!$fac && !$admin) redirect('parent.php?v=factures'); // les parents ne voient que les factures émises

$c = calcul_facture($fid, $ym);
$paye = q('SELECT * FROM paiements WHERE famille_id = ? AND mois = ?', [$fid, $ym])->fetch();
$prenoms = implode(', ', array_unique(array_column($c['jours'], 'prenom')))
    ?: implode(', ', q('SELECT prenom FROM enfants WHERE famille_id = ? AND actif = 1', [$fid])->fetchAll(PDO::FETCH_COLUMN));
$dateFacture = $fac ? $fac['date'] : date('Y-m-d');
$ville = setting('ville', 'Bonneville');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facture <?= $fac ? (int)$fac['numero'] : 'brouillon' ?> – <?= h($fam['nom']) ?></title>
<style>
  body { font: 13px/1.4 system-ui, "Segoe UI", Roboto, sans-serif; color: #222; background: #f4f4f4; margin: 0; }
  .page { background: #fff; max-width: 800px; margin: 20px auto; padding: 36px; position: relative; }
  .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
  h1 { font-size: 26px; margin: 0 0 14px; letter-spacing: 2px; }
  h2 { font-size: 15px; margin: 22px 0 6px; text-transform: uppercase; }
  .titre { text-align: center; font-weight: 700; font-size: 15px; margin: 22px 0 4px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; vertical-align: top; }
  th { background: #e9f1ef; font-size: 11px; text-transform: uppercase; }
  .r { text-align: right; white-space: nowrap; } .c { text-align: center; } .muted { color: #777; font-size: 12px; }
  .sous td { font-weight: 700; background: #f6f6f6; }
  .net { margin-top: 18px; font-size: 18px; font-weight: 700; display: flex; justify-content: space-between; border: 2px solid #222; padding: 10px 14px; }
  .bar { max-width: 800px; margin: 20px auto 0; display: flex; gap: 10px; }
  .bar a, .bar button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 0; background: #2f7d6d; color: #fff; text-decoration: none; cursor: pointer; }
  .paid { display: inline-block; border: 2px solid #2f7d6d; color: #2f7d6d; padding: 4px 10px; border-radius: 6px; font-weight: 700; }
  .brouillon { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 80px; color: rgba(200,0,0,.12); transform: rotate(-20deg); pointer-events: none; }
  .bank td { border: 0; padding: 2px 8px 2px 0; }
  @page { size: A4; margin: 10mm 12mm; }
  @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; padding: 0; max-width: none; } }
</style></head>
<body>
<div class="bar"><button onclick="window.print()">Imprimer / PDF</button>
  <a href="<?= $admin ? 'admin.php?p=factures&m=' . h($ym) : 'parent.php?v=factures' ?>">Retour</a></div>
<div class="page">
  <?php if (!$fac): ?><div class="brouillon">BROUILLON</div><?php endif ?>
  <h1>FACTURE</h1>
  <div class="head">
    <div>
      <b><?= h(setting('nom')) ?></b><br><?= setting('nom_affiche') && setting('nom_affiche') !== setting('nom') ? '<i>' . h(setting('nom_affiche')) . '</i><br>' : '' ?>
      <?= nl2br(h(setting('adresse'))) ?><br>
      <?= setting('tel') ? 'Tél : ' . h(setting('tel')) . '<br>' : '' ?>
      <?= setting('email_contact') ? 'Mail : ' . h(setting('email_contact')) . '<br>' : '' ?>
      <?= setting('siret') ? 'SIRET : ' . h(setting('siret')) : '' ?><?= ligne_sap() ?>
    </div>
    <div>
      <b>N° <?= $fac ? (int)$fac['numero'] : '—' ?></b><br>
      <?= h($ville) ?>, le <?= h(date('d/m/Y', strtotime($dateFacture))) ?><br><br>
      <b><?= nl2br(h($fam['adresse'] ?: $fam['nom'])) ?></b><br>
      <?= h($fam['tel']) ?><br><?= h($fam['email']) ?>
    </div>
  </div>

  <div class="titre">Garderie <?= h(mois_fr($ym)) ?> — <?= h(mb_strtoupper($prenoms)) ?></div>

  <?php $plusieurs = count(array_unique(array_column($c['jours'], 'prenom'))) > 1;
  $types = ['petitdej' => 'Petit-déjeuner', 'repas' => 'Repas', 'gouter' => 'Goûter'];
  $coche = fn(array $j, string $x) => !empty($j['ex'][$x]) ? '✓' : ''; ?>
  <table>
    <tr><th>Jour de garde</th><?= $plusieurs ? '<th>Enfant</th>' : '' ?><th>Horaires de garde</th><th class="r">Durée</th><th class="r">Tarif/h</th><th class="r">Total HT</th>
      <th class="c">P.déj</th><th class="c">Repas</th><th class="c">Goûter</th></tr>
    <?php foreach ($c['jours'] as $j): ?>
      <tr><td><?= h(date('d/m/Y', strtotime($j['jour']))) ?></td><?= $plusieurs ? '<td>' . h($j['prenom']) . '</td>' : '' ?>
        <td><?= h($j['horaires']) ?></td>
        <?php if (!empty($j['heures_nuit'])): // journée + nuit : deux tarifs sur la même ligne ?>
          <td class="r"><?= duree_fr($j['heures'] - $j['heures_nuit']) ?><br><small>+ nuit <?= duree_fr($j['heures_nuit']) ?></small></td>
          <td class="r"><?= euro($j['tarif']) ?><br><small>nuit <?= euro($j['tarif_nuit']) ?></small></td>
        <?php else: ?>
          <td class="r"><?= duree_fr($j['heures']) ?></td><td class="r"><?= euro($j['tarif']) ?></td>
        <?php endif ?>
        <td class="r"><?= euro($j['total']) ?></td>
        <?php foreach (array_keys($types) as $x): ?><td class="c"><?= $coche($j, $x) ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    <?php if (!$c['jours']): ?><tr><td colspan="8" class="muted">Aucune garde ce mois-ci.</td></tr><?php endif ?>
    <tr class="sous"><td colspan="<?= $plusieurs ? 3 : 2 ?>">Total heures travaillées : <?= duree_fr($c['heures']) ?></td><td colspan="2" class="r">TOTAL GARDERIE</td><td class="r"><?= euro($c['total_garde']) ?></td>
      <?php foreach (array_keys($types) as $x): ?><td class="c"><?= count(array_filter($c['jours'], fn($j) => !empty($j['ex'][$x]))) ?></td><?php endforeach ?></tr>
  </table>

  <?php // Récapitulatif toujours affiché : chaque type pour chaque enfant, même à zéro
  $infos = [];
  foreach (q('SELECT prenom, tarif_special, sans_repas FROM enfants WHERE famille_id = ?', [$fid]) as $e) $infos[$e['prenom']] = $e;
  $faits = [];
  foreach ($c['extras'] as $x) $faits[$x['prenom'] . '|' . $x['type']] = $x;
  $recap = [];
  foreach (array_unique(array_column($c['jours'], 'prenom')) as $prenom) {
      if (!empty($infos[$prenom]['sans_repas'])) continue;
      foreach ($types as $x => $nom) {
          $recap[] = $faits[$prenom . '|' . $nom] ?? ['type' => $nom, 'prenom' => $prenom, 'dates' => '', 'qte' => 0,
              'pu' => $x === 'petitdej' ? tarif('petitdej') : tarif(!empty($infos[$prenom]['tarif_special']) ? "{$x}_special" : $x), 'total' => 0];
      }
  }
  if ($recap): ?>
    <h2>Récapitulatif repas / goûters / petits-déjeuners</h2>
    <table>
      <tr><th>Prestation</th><th>Dates (jour du mois)</th><th class="r">Qté</th><th class="r">Prix unit.</th><th class="r">Total HT</th></tr>
      <?php foreach ($recap as $x): ?>
        <tr<?= $x['qte'] ? '' : ' class="muted"' ?>><td><?= h($x['type']) ?><?= $plusieurs ? ' – ' . h($x['prenom']) : '' ?></td><td><?= h($x['dates'] ?: '—') ?></td>
          <td class="r"><?= (int)$x['qte'] ?></td><td class="r"><?= euro($x['pu']) ?></td><td class="r"><?= euro($x['total']) ?></td></tr>
      <?php endforeach ?>
      <tr class="sous"><td colspan="4" class="r">TOTAL REPAS / GOÛTER / PETIT-DÉJ.</td><td class="r"><?= euro($c['total_extras']) ?></td></tr>
    </table>
  <?php endif ?>
  <?php $fournis = array_values(array_filter(array_unique(array_column($c['jours'], 'prenom')), fn($p) => !empty($infos[$p]['sans_repas'])));
  if ($fournis): ?>
    <p class="muted" style="margin-top:10px">Repas et goûters fournis par les parents pour <?= h(implode(', ', $fournis)) ?> : non facturés.</p>
  <?php endif ?>
  <table style="margin-top:14px">
    <tr><td>Total garderie</td><td class="r" style="width:140px"><?= euro($c['total_garde']) ?></td></tr>
    <tr><td>Total repas / goûters / petits-déjeuners</td><td class="r"><?= euro($c['total_extras']) ?></td></tr>
  </table>

  <?php if ($c['avoirs']): ?>
    <h2>Avoirs</h2>
    <table>
      <?php foreach ($c['avoirs'] as $a): ?>
        <tr><td>Avoir AV-<?= (int)$a['numero'] ?> du <?= h(date('d/m/Y', strtotime($a['date']))) ?> – <?= h($a['motif']) ?></td><td class="r" style="width:120px">−<?= euro($a['montant']) ?></td></tr>
      <?php endforeach ?>
      <tr class="sous"><td class="r">Total facture <?= euro($c['total']) ?> − avoirs</td><td class="r">−<?= euro($c['total_avoirs']) ?></td></tr>
    </table>
  <?php endif ?>

  <div class="net"><span>TOTAL NET À PAYER</span><span><?= euro($c['net']) ?></span></div>

  <?php if ($paye): ?>
    <p><span class="paid">PAYÉE le <?= h(date('d/m/Y', strtotime($paye['date']))) ?><?= $paye['mode'] ? ' – ' . h($paye['mode']) : '' ?></span></p>
  <?php endif ?>
  <p>Mode de règlement : comptant dès réception de la facture.</p>
  <?php if (setting('mention')): ?><p class="muted"><?= nl2br(h(setting('mention'))) ?></p><?php endif ?>
  <?php if (setting('iban')): ?>
    <h2>Coordonnées bancaires — virement</h2>
    <table class="bank">
      <?php if (setting('titulaire')): ?><tr><td>Titulaire :</td><td><?= h(setting('titulaire')) ?></td></tr><?php endif ?>
      <tr><td>IBAN :</td><td><?= h(setting('iban')) ?></td></tr>
      <?php if (setting('bic')): ?><tr><td>BIC :</td><td><?= h(setting('bic')) ?></td></tr><?php endif ?>
      <?php if (setting('domiciliation')): ?><tr><td>Domiciliation :</td><td><?= h(setting('domiciliation')) ?></td></tr><?php endif ?>
      <?php if ($fac): ?><tr><td>Référence :</td><td>Facture <?= (int)$fac['numero'] ?></td></tr><?php endif ?>
    </table>
  <?php endif ?>
</div>
</body></html>
