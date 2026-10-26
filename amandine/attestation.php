<?php
// Attestation fiscale annuelle de frais de garde (une page par enfant), à imprimer ou enregistrer en PDF
require __DIR__ . '/inc.php';

$admin = is_admin();
$fid = $admin ? (int)($_GET['f'] ?? 0) : require_parent();
$an = (int)($_GET['a'] ?? date('Y') - 1);
$fam = q('SELECT * FROM familles WHERE id = ?', [$fid])->fetch();
if (!$fam || $an < 2000 || $an > 2100) redirect('index.php');

// Sommes effectivement versées pendant l'année : factures dont le paiement est daté de l'année
$payees = q("SELECT f.mois, f.numero, p.date FROM factures f JOIN paiements p ON p.famille_id = f.famille_id AND p.mois = f.mois
             WHERE f.famille_id = ? AND substr(p.date, 1, 4) = ? ORDER BY f.mois", [$fid, (string)$an])->fetchAll();
$parEnfant = [];
foreach ($payees as $fa) {
    $cf = calcul_facture($fid, $fa['mois']);
    $ratio = $cf['total'] > 0 ? $cf['net'] / $cf['total'] : 1; // avoirs partiels répartis au prorata
    foreach (totaux_par_enfant($cf) as $prenom => $t) {
        foreach (['garde', 'nourriture', 'total'] as $k) $t[$k] = round($t[$k] * $ratio, 2);
        $parEnfant[$prenom][] = $fa + $t;
    }
}
$enfants = [];
foreach (q('SELECT * FROM enfants WHERE famille_id = ? ORDER BY prenom', [$fid]) as $e) $enfants[$e['prenom']] = $e;
$ville = setting('ville', 'Bonneville');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attestation <?= $an ?> – <?= h($fam['nom']) ?></title>
<style>
  body { font: 14px/1.5 system-ui, "Segoe UI", Roboto, sans-serif; color: #222; background: #f4f4f4; margin: 0; }
  .page { background: #fff; max-width: 780px; margin: 20px auto; padding: 40px; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  h1 { font-size: 20px; text-align: center; margin: 24px 0 4px; text-transform: uppercase; }
  .sous { text-align: center; color: #555; margin: 0 0 24px; }
  table { width: 100%; border-collapse: collapse; margin: 14px 0; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #e9f1ef; font-size: 12px; text-transform: uppercase; }
  .r { text-align: right; }
  .tot td { font-weight: 700; background: #f6f6f6; }
  .cle { font-size: 16px; font-weight: 700; border: 2px solid #222; padding: 10px 14px; display: flex; justify-content: space-between; margin: 16px 0; }
  .alerte { background: #fbe9e4; color: #b4462b; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; }
  .muted { color: #777; font-size: 12px; }
  .sign { margin-top: 40px; display: flex; justify-content: flex-end; }
  .sign div { width: 260px; text-align: center; }
  .bar { max-width: 780px; margin: 20px auto 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .bar a, .bar button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 0; background: #2f7d6d; color: #fff; text-decoration: none; cursor: pointer; }
  @media print { body { background: #fff; } .bar, .alerte { display: none; } .page { margin: 0; padding: 20px; max-width: none; } }
</style></head>
<body>
<div class="bar">
  <button onclick="window.print()">Imprimer / PDF</button>
  <a href="?a=<?= $an - 1 ?><?= $admin ? "&f=$fid" : '' ?>">◀ <?= $an - 1 ?></a>
  <a href="?a=<?= $an + 1 ?><?= $admin ? "&f=$fid" : '' ?>"><?= $an + 1 ?> ▶</a>
  <a href="<?= $admin ? "admin.php?p=recap&a=$an" : 'parent.php?v=factures' ?>">Retour</a>
</div>

<?php if (!$parEnfant): ?>
  <div class="page"><p>Aucun paiement enregistré en <?= $an ?> pour <?= h($fam['nom']) ?>.</p>
    <p class="muted">L'attestation reprend les factures marquées « payées » avec une date de paiement en <?= $an ?>.</p></div>
<?php endif;

foreach ($parEnfant as $prenom => $mois):
    $e = $enfants[$prenom] ?? [];
    $garde = array_sum(array_column($mois, 'garde'));
    $nourr = array_sum(array_column($mois, 'nourriture'));
    $heures = array_sum(array_column($mois, 'heures'));
    $age = !empty($e['naissance']) ? (int)(new DateTime($e['naissance']))->diff(new DateTime("$an-01-01"))->y : null;
?>
<div class="page">
  <?php if ($admin && $age === null): ?>
    <div class="alerte">Date de naissance de <?= h($prenom) ?> non renseignée (fiche enfant). Le crédit d'impôt ne concerne que les enfants de moins de 6 ans au 1er janvier <?= $an ?>.</div>
  <?php elseif ($admin && $age >= 6): ?>
    <div class="alerte"><?= h($prenom) ?> avait <?= $age ?> ans au 1er janvier <?= $an ?> : ces frais n'ouvrent normalement pas droit au crédit d'impôt pour frais de garde (enfant de moins de 6 ans).</div>
  <?php endif ?>

  <div>
    <b><?= h(setting('nom')) ?></b><br><?= setting('nom_affiche') && setting('nom_affiche') !== setting('nom') ? '<i>' . h(setting('nom_affiche')) . '</i><br>' : '' ?>
    <?= nl2br(h(setting('adresse'))) ?><br>
    <?= setting('siret') ? 'SIRET : ' . h(setting('siret')) : '' ?><?= ligne_sap() ?><br>
    <?= setting('tel') ? 'Tél : ' . h(setting('tel')) : '' ?>
  </div>

  <h1>Attestation de frais de garde d'enfant</h1>
  <p class="sous">Année <?= $an ?> — à joindre à la déclaration de revenus (article 200 quater B du CGI)</p>

  <p>Je soussignée, <b><?= h(setting('titulaire') ?: setting('nom')) ?></b>, atteste avoir assuré la garde de l'enfant
    <b><?= h(trim($prenom . ' ' . ($e['nom'] ?? ''))) ?></b><?= !empty($e['naissance']) ? ', né(e) le <b>' . h(date('d/m/Y', strtotime($e['naissance']))) . '</b>' : '' ?>,
    et avoir perçu de :</p>
  <p style="margin-left:20px"><b><?= nl2br(h($fam['adresse'] ?: $fam['nom'])) ?></b></p>
  <p>les sommes suivantes au cours de l'année <?= $an ?> :</p>

  <table>
    <tr><th>Facture</th><th>Période</th><th>Payée le</th><th class="r">Heures</th><th class="r">Frais de garde</th><th class="r">Repas / goûters</th><th class="r">Total versé</th></tr>
    <?php foreach ($mois as $m): ?>
      <tr><td>N° <?= (int)$m['numero'] ?></td><td><?= h(mois_fr($m['mois'])) ?></td><td><?= h(date('d/m/Y', strtotime($m['date']))) ?></td>
        <td class="r"><?= duree_fr($m['heures']) ?></td><td class="r"><?= euro($m['garde']) ?></td><td class="r"><?= euro($m['nourriture']) ?></td><td class="r"><?= euro($m['total']) ?></td></tr>
    <?php endforeach ?>
    <tr class="tot"><td colspan="3">Total <?= $an ?></td><td class="r"><?= duree_fr($heures) ?></td><td class="r"><?= euro($garde) ?></td><td class="r"><?= euro($nourr) ?></td><td class="r"><?= euro($garde + $nourr) ?></td></tr>
  </table>

  <div class="cle"><span>Montant des frais de garde (hors nourriture)</span><span><?= euro($garde) ?></span></div>
  <p class="muted">Les frais de nourriture (<?= euro($nourr) ?>) ne sont pas pris en compte pour le crédit d'impôt.
    Les aides perçues (CAF / complément de libre choix du mode de garde) sont à déduire par le déclarant.</p>

  <div class="sign"><div>Fait à <?= h($ville) ?>, le <?= date('d/m/Y') ?><br><br><br>Signature</div></div>
</div>
<?php endforeach ?>
</body></html>
