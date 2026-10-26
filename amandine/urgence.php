<?php
// Fiche d'urgence imprimable : tous les enfants accueillis (allergies, contacts, personnes autorisées)
require __DIR__ . '/inc.php';
require_admin();

$enfants = q("SELECT e.*, f.nom AS famille, f.tel AS ftel, f.email AS femail, f.adresse AS fadresse
              FROM enfants e JOIN familles f ON f.id = e.famille_id
              WHERE e.actif = 1 AND f.actif = 1 ORDER BY e.prenom")->fetchAll();
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fiche d'urgence – <?= h(nom_affiche()) ?></title>
<style>
  body { font: 12px/1.35 system-ui, "Segoe UI", Roboto, sans-serif; color: #222; background: #f4f4f4; margin: 0; }
  .page { background: #fff; max-width: 900px; margin: 20px auto; padding: 24px; }
  h1 { font-size: 20px; margin: 0; }
  .numeros { display: flex; flex-wrap: wrap; gap: 6px 18px; background: #fbe9e4; border: 2px solid #b4462b; border-radius: 8px; padding: 8px 12px; margin: 10px 0 14px; font-size: 14px; }
  .numeros b { color: #b4462b; }
  .grille { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .enfant { border: 1px solid #bbb; border-radius: 8px; padding: 8px 10px; break-inside: avoid; page-break-inside: avoid; }
  .enfant h2 { font-size: 15px; margin: 0 0 4px; display: flex; justify-content: space-between; }
  .allergie { background: #b4462b; color: #fff; font-weight: 700; padding: 3px 8px; border-radius: 4px; margin: 4px 0; display: inline-block; }
  .l { margin: 2px 0; } .l b { display: inline-block; min-width: 92px; color: #555; font-weight: 600; }
  .muted { color: #777; }
  .bar { max-width: 900px; margin: 20px auto 0; display: flex; gap: 10px; }
  .bar a, .bar button { font: inherit; font-size: 14px; padding: 8px 14px; border-radius: 6px; border: 0; background: #2f7d6d; color: #fff; text-decoration: none; cursor: pointer; }
  @media (max-width: 640px) { .grille { grid-template-columns: 1fr; } }
  @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; padding: 0; max-width: none; } }
</style></head>
<body>
<div class="bar"><button onclick="window.print()">Imprimer / PDF</button><a href="admin.php?p=sante">Retour</a></div>
<div class="page">
  <h1>Fiche d'urgence — enfants accueillis</h1>
  <span class="muted"><?= h(nom_affiche()) ?> · <?= h(str_replace("\n", ', ', setting('adresse'))) ?> · <?= h(setting('tel')) ?> · mise à jour le <?= date('d/m/Y') ?></span>
  <div class="numeros">
    <span><b>SAMU 15</b></span><span><b>Pompiers 18</b></span><span><b>Urgences 112</b></span>
    <span><b>Centre antipoison 04 72 11 69 11</b> (Lyon)</span><span>Sourds/malentendants : SMS 114</span>
  </div>
  <div class="grille">
    <?php foreach ($enfants as $e): $age = age($e['naissance']); ?>
      <div class="enfant">
        <h2><span><?= avatar($e, 48) ?> <?= h(trim($e['prenom'] . ' ' . $e['nom'])) ?></span>
          <span class="muted"><?= $e['naissance'] ? h(date('d/m/Y', strtotime($e['naissance']))) . " ($age ans)" : '' ?></span></h2>
        <?php if ($e['allergies']): ?><div class="allergie">⚠ <?= h($e['allergies']) ?></div><?php endif ?>
        <?php if ($e['ecole'] || $e['classe']): ?><div class="l"><b>École</b> <?= h(trim($e['ecole'] . ' ' . $e['classe'])) ?></div><?php endif ?>
        <div class="l"><b>Parents</b> <?= h($e['famille']) ?> — <?= h($e['ftel'] ?: '—') ?></div>
        <div class="l"><b>Urgence</b> <?= nl2br(h($e['urgence'] ?: '—')) ?></div>
        <div class="l"><b>Autorisés</b> <?= nl2br(h($e['autorises'] ?: '—')) ?></div>
        <?php if ($e['notes']): ?><div class="l"><b>Notes</b> <?= nl2br(h($e['notes'])) ?></div><?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
  <?php if (!$enfants): ?><p>Aucun enfant actif.</p><?php endif ?>
  <p class="muted">Document confidentiel — à conserver près du téléphone et lors des trajets.</p>
</div>
</body></html>
