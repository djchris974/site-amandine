<?php
// Mot de passe oublié : lien valable 1 heure envoyé par email, utilisable une seule fois
// (la signature contient l'ancien mot de passe chiffré : dès qu'il change, le lien ne marche plus).
require __DIR__ . '/inc.php';

const DUREE_LIEN = 3600;

/** Compte lié à un email : ['id' => id famille ou 'a' pour Amandine, 'pass' => mot de passe chiffré]. */
function compte_par_email(string $email): ?array
{
    if ($email !== '' && $email === setting('admin_email')) return ['id' => 'a', 'pass' => setting('admin_pass')];
    $f = q('SELECT id, pass FROM familles WHERE email = ? AND actif = 1', [$email])->fetch();
    return $f ? ['id' => (string)$f['id'], 'pass' => (string)$f['pass']] : null;
}

function compte_par_id(string $id): ?array
{
    if ($id === 'a') return ['id' => 'a', 'pass' => setting('admin_pass')];
    $f = q('SELECT id, pass FROM familles WHERE id = ? AND actif = 1', [(int)$id])->fetch();
    return $f ? ['id' => (string)$f['id'], 'pass' => (string)$f['pass']] : null;
}

function signature(array $c, int $exp): string
{
    if (setting('secret_reset') === '') set_setting('secret_reset', bin2hex(random_bytes(32)));
    return hash_hmac('sha256', $c['id'] . '|' . $exp . '|' . $c['pass'], setting('secret_reset'));
}

/** Vérifie le lien reçu ; renvoie le compte s'il est valable. */
function compte_du_lien(string $t): ?array
{
    if (!preg_match('/^(a|\d+)\.(\d+)\.([a-f0-9]{64})$/', $t, $m) || (int)$m[2] < time()) return null;
    $c = compte_par_id($m[1]);
    return $c && hash_equals(signature($c, (int)$m[2]), $m[3]) ? $c : null;
}

$t = (string)($_GET['t'] ?? '');

// 2. Le parent arrive par le lien de l'email et choisit son nouveau mot de passe
if ($t !== '') {
    $c = compte_du_lien($t);
    if (!$c) {
        flash('Ce lien a expiré ou a déjà servi. Refaites une demande ci-dessous.', 'err');
        redirect('motdepasse.php');
    }
    if (post()) {
        $p1 = (string)($_POST['p1'] ?? '');
        if (strlen($p1) < 8) flash('Mot de passe trop court (8 caractères minimum).', 'err');
        elseif ($p1 !== (string)($_POST['p2'] ?? '')) flash('Les deux mots de passe ne sont pas identiques.', 'err');
        else {
            $hash = password_hash($p1, PASSWORD_DEFAULT);
            if ($c['id'] === 'a') set_setting('admin_pass', $hash);
            else q('UPDATE familles SET pass = ? WHERE id = ?', [$hash, (int)$c['id']]);
            flash('Mot de passe enregistré : vous pouvez vous connecter.');
            redirect('index.php');
        }
        redirect('motdepasse.php?t=' . rawurlencode($t));
    }
    page_debut('Nouveau mot de passe'); ?>
    <form method="post" class="card narrow"><?= csrf_field() ?>
      <label>Nouveau mot de passe (8 caractères minimum)<input type="password" name="p1" required minlength="8" autocomplete="new-password"></label>
      <label>Retapez-le<input type="password" name="p2" required minlength="8" autocomplete="new-password"></label>
      <button>Enregistrer</button>
    </form>
    <?php page_fin();
    exit;
}

// 1. Demande du lien : même réponse que l'email soit connu ou non (on ne révèle pas qui a un compte)
if (post()) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $c = compte_par_email($email);
    $cle = 'reset_envoi_' . ($c['id'] ?? '');
    if ($c && (int)setting($cle, '0') < time() - 300) { // au plus un email toutes les 5 minutes par compte
        $exp = time() + DUREE_LIEN;
        $lien = url_base() . 'motdepasse.php?t=' . $c['id'] . '.' . $exp . '.' . signature($c, $exp);
        envoyer_mail($email, 'Mot de passe oublié – ' . nom_affiche(),
            "Bonjour,\n\nVous avez demandé à changer votre mot de passe. Cliquez sur ce lien pour en choisir un nouveau (valable 1 heure, une seule fois) :\n\n$lien\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe actuel reste valable.\n\n" . nom_affiche() . "\n");
        set_setting($cle, (string)time());
    }
    sleep(1);
    flash("Si cette adresse correspond à un compte, un email vient d'être envoyé avec un lien pour choisir un nouveau mot de passe (pensez aux spams).");
    redirect('motdepasse.php');
}

page_debut('Mot de passe oublié'); ?>
<form method="post" class="card narrow"><?= csrf_field() ?>
  <p>Indiquez l'email avec lequel vous vous connectez : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
  <label>Email<input type="email" name="email" required autocomplete="username"></label>
  <button>Recevoir le lien</button>
  <p class="muted"><a href="index.php">← Retour à la connexion</a><?= setting('tel') ? ' · Besoin d\'aide : ' . h(setting('tel')) : '' ?></p>
</form>
<?php page_fin();
