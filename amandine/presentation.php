<?php
// Page publique de présentation + formulaire de contact pour les nouvelles familles
require __DIR__ . '/inc.php';

$envoye = false;
$erreur = '';
if (post()) {
    $P = fn(string $k): string => trim((string)($_POST[$k] ?? ''));
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'amandine');
    $recent = (int)q("SELECT COUNT(*) FROM demandes WHERE ip = ? AND cree > datetime('now', 'localtime', '-1 hour')", [$ip])->fetchColumn();
    if ($P('site') !== '' || time() - (int)($_SESSION['form_t'] ?? 0) < 3) {
        $envoye = true; // robot : on fait comme si de rien n'était
    } elseif ($recent >= 3) {
        $erreur = 'Trop de demandes envoyées. Merci de réessayer plus tard ou de téléphoner.';
    } elseif ($P('nom') === '' || !filter_var($P('email'), FILTER_VALIDATE_EMAIL)) {
        $erreur = 'Merci d\'indiquer votre nom et une adresse email valide.';
    } else {
        $besoins = implode(', ', array_intersect_key(BESOINS, array_flip(array_keys((array)($_POST['besoins'] ?? [])))));
        q('INSERT INTO demandes (nom, email, tel, enfants, besoins, debut, message, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr($P('nom'), 0, 120), strtolower($P('email')), mb_substr($P('tel'), 0, 40), mb_substr($P('enfants'), 0, 300),
             $besoins, mb_substr($P('debut'), 0, 60), mb_substr($P('message'), 0, 3000), $ip]);
        $dest = setting('email_contact') ?: setting('admin_email');
        if ($dest) {
            envoyer_mail($dest, 'Nouvelle demande de garde – ' . $P('nom'),
                "Nouvelle demande reçue depuis la page de présentation :\n\n"
                . 'Nom : ' . $P('nom') . "\nEmail : " . $P('email') . "\nTéléphone : " . $P('tel')
                . "\nEnfant(s) : " . $P('enfants') . "\nBesoins : " . $besoins . "\nÀ partir de : " . $P('debut')
                . "\n\nMessage :\n" . $P('message') . "\n\nVoir toutes les demandes : " . url_base() . "admin.php?p=demandes\n",
                $P('email'));
        }
        $envoye = true;
    }
}
$_SESSION['form_t'] = time();

$nom = nom_affiche();
$creneaux = prestations();
$jourTxt = function (string $jours): string {
    $j = array_map('intval', explode(',', $jours));
    if ($j === [1, 2, 4, 5]) return 'Lun, mar, jeu, ven';
    if (count($j) === 7) return 'Tous les jours';
    return implode(', ', array_map(fn($k) => JOURS_COURTS[$k], $j));
};
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($nom) ?> – Garde d'enfants à <?= h(setting('ville', 'Bonneville')) ?></title>
<meta name="description" content="<?= h(mb_substr(str_replace("\n", ' ', (setting('presentation') ?: PRESENTATION_DEFAUT)), 0, 155)) ?>">
<link rel="stylesheet" href="style.css"><?= entete_appli() ?>
<style>
  .hero { background: var(--accent); color: #fff; padding: 2.5rem 0 2rem; }
  .hero h1 { font-size: 1.9rem; margin: 0 0 .3rem; }
  .hero p { margin: 0; opacity: .9; font-size: 1.05rem; }
  .hero .row { margin: 1.2rem 0 0; }
  .hero .btn { background: #fff; color: var(--accent); font-weight: 600; }
  .hero .btn.ghost { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.7); }
  .places { display: inline-block; background: #fff3c4; color: #6b5200; font-weight: 600; padding: .35rem .8rem; border-radius: 99px; margin-top: 1rem; }
  section { margin: 2rem 0; }
  .services { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .8rem; }
  .services .card { margin: 0; }
  .services b { display: block; margin-bottom: .2rem; }
  .texte { font-size: 1.05rem; white-space: pre-line; }
  .besoins label { display: inline-flex; align-items: center; gap: .35rem; margin: 0 1rem .4rem 0; color: var(--ink); font-size: .95rem; }
  .besoins input { width: auto; }
  .hp { position: absolute; left: -9999px; }
  footer { color: var(--muted); font-size: .85rem; padding: 2rem 0; text-align: center; }
</style></head>
<body>
<div class="hero"><div class="wrap">
  <h1><?= h($nom) ?></h1>
  <p><?= h((setting('accroche') ?: "Garde d'enfants périscolaire à " . setting('ville', 'Bonneville') . ' – matin, midi, soir, mercredi et vacances')) ?></p>
  <?php if (setting('places_dispo')): ?><div class="places"><?= h(setting('places_dispo')) ?></div><?php endif ?>
  <div class="row">
    <a class="btn" href="#contact">Demander une place</a>
    <?php if (setting('tel')): ?><a class="btn ghost" href="tel:<?= h(preg_replace('/\s+/', '', setting('tel'))) ?>">📞 <?= h(setting('tel')) ?></a><?php endif ?>
    <a class="btn ghost" href="index.php">Espace parents</a>
  </div>
</div></div>

<main class="wrap">
  <section>
    <h2>Présentation</h2>
    <p class="texte"><?= h((setting('presentation') ?: PRESENTATION_DEFAUT)) ?></p>
  </section>

  <section>
    <h2>Accueil et horaires</h2>
    <div class="services">
      <?php foreach ($creneaux as $c): ?>
        <div class="card"><b><?= h($c['libelle']) ?></b>
          <span class="muted"><?= h($jourTxt($c['jours'])) ?> · <?= h(horaires_prest($c)) ?></span>
          <?php $inclus = array_keys(array_filter(['petit-déjeuner' => $c['petitdej'], 'repas' => $c['repas'], 'goûter' => $c['gouter']]));
          if ($inclus): ?><br><span class="muted">avec <?= h(implode(', ', $inclus)) ?></span><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
  </section>

  <?php if (setting('afficher_tarifs') === '1'): ?>
  <section>
    <h2>Tarifs</h2>
    <div class="card">
      Garde : <b><?= euro(tarif('tarif_semaine')) ?> / heure</b> en semaine, <?= euro(tarif('tarif_weekend')) ?> / heure le week-end et les jours fériés.<br>
      Repas <?= euro(tarif('repas')) ?> · goûter <?= euro(tarif('gouter')) ?> · petit-déjeuner <?= euro(tarif('petitdej')) ?>.<br>
      <span class="muted">Facturation mensuelle à l'heure réelle. Réservation et factures en ligne dans l'espace parents.</span>
    </div>
  </section>
  <?php endif ?>

  <section id="contact">
    <h2>Demander une place</h2>
    <?php if ($envoye): ?>
      <div class="flash">Merci ! Votre demande a bien été envoyée, vous serez recontacté(e) rapidement.</div>
    <?php else: ?>
      <?php if ($erreur): ?><div class="flash err"><?= h($erreur) ?></div><?php endif ?>
      <form method="post" action="#contact" class="card">
        <?= csrf_field() ?>
        <div class="grid2">
          <label>Votre nom *<input name="nom" required maxlength="120" value="<?= h($_POST['nom'] ?? '') ?>"></label>
          <label>Email *<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></label>
          <label>Téléphone<input type="tel" name="tel" value="<?= h($_POST['tel'] ?? '') ?>"></label>
          <label>Enfant(s) : prénom et âge<input name="enfants" placeholder="ex. Léa, 5 ans" value="<?= h($_POST['enfants'] ?? '') ?>"></label>
          <label>À partir de quand ?<input name="debut" placeholder="ex. rentrée de septembre" value="<?= h($_POST['debut'] ?? '') ?>"></label>
        </div>
        <div class="besoins"><span class="muted">Besoins :</span><br>
          <?php foreach (BESOINS as $k => $lib): ?><label><input type="checkbox" name="besoins[<?= $k ?>]" <?= isset($_POST['besoins'][$k]) ? 'checked' : '' ?>> <?= h($lib) ?></label><?php endforeach ?>
        </div>
        <label>Message<textarea name="message" rows="4" placeholder="École, horaires, questions…"><?= h($_POST['message'] ?? '') ?></textarea></label>
        <label class="hp" aria-hidden="true">Site<input name="site" tabindex="-1" autocomplete="off"></label>
        <button>Envoyer ma demande</button>
        <p class="muted">Vos informations servent uniquement à répondre à votre demande.</p>
      </form>
    <?php endif ?>
  </section>
</main>
<footer><?= h($nom) ?> · <?= h(setting('ville', 'Bonneville')) ?><?= setting('siret') ? ' · SIRET ' . h(setting('siret')) : '' ?></footer>
</body></html>
