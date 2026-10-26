<?php
require __DIR__ . '/inc.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}

if (isset($_GET['retour_amandine']) && !empty($_SESSION['vue_amandine'])) { // fin de l'aperçu « comme les parents »
    $_SESSION['role'] = 'admin';
    $f = (int)($_SESSION['famille'] ?? 0);
    unset($_SESSION['vue_amandine'], $_SESSION['famille']);
    redirect($f ? "admin.php?p=famille&id=$f" : 'admin.php?p=familles');
}

$setup = setting('admin_pass') === '';

if (post()) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['pass'] ?? '');
    if ($setup) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Email invalide ou mot de passe trop court (8 caractères minimum).', 'err');
            redirect('index.php');
        }
        set_setting('admin_email', $email);
        set_setting('admin_pass', password_hash($pass, PASSWORD_DEFAULT));
        set_setting('nom', trim((string)($_POST['nom'] ?? '')) ?: 'Garde périscolaire');
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        flash('Bienvenue ! Commencez par vérifier vos tarifs et horaires.');
        redirect('admin.php?p=prestations');
    }
    if ($email === setting('admin_email') && password_verify($pass, setting('admin_pass'))) {
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        redirect('admin.php');
    }
    $f = q('SELECT id, pass FROM familles WHERE email = ? AND actif = 1', [$email])->fetch();
    if ($f && $f['pass'] && password_verify($pass, $f['pass'])) {
        session_regenerate_id(true);
        $_SESSION['role'] = 'parent';
        $_SESSION['famille'] = (int)$f['id'];
        redirect('parent.php');
    }
    sleep(1); // ralentit les essais de mots de passe
    flash('Email ou mot de passe incorrect.', 'err');
    redirect('index.php');
}

if (is_admin()) redirect('admin.php');
if (($_SESSION['role'] ?? '') === 'parent') redirect('parent.php');

page_debut($setup ? 'Première installation' : 'Connexion');
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <?php if ($setup): ?>
    <p>Créez le compte d'Amandine (administratrice). Les parents auront leurs propres accès, créés ensuite depuis le menu « Familles ».</p>
    <label>Nom affiché<input name="nom" value="Amandine Services" required></label>
  <?php endif ?>
  <label>Email<input type="email" name="email" required autocomplete="username"></label>
  <label>Mot de passe<input type="password" name="pass" required minlength="<?= $setup ? 8 : 1 ?>" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></label>
  <button><?= $setup ? 'Créer le compte' : 'Se connecter' ?></button>
  <?php if (!$setup): ?><p><a href="motdepasse.php">Mot de passe oublié ?</a></p><?php endif ?>
</form>
<?php if (!$setup): ?><p class="narrow">Vous cherchez une garde pour votre enfant ? <a href="presentation.php">Découvrir l'accueil et demander une place →</a></p><?php endif ?>
<?php page_fin();
