<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_name('amandine_sess');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS familles (
  id INTEGER PRIMARY KEY, nom TEXT NOT NULL, email TEXT NOT NULL UNIQUE, tel TEXT, adresse TEXT,
  pass TEXT, actif INTEGER NOT NULL DEFAULT 1, cree TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS enfants (
  id INTEGER PRIMARY KEY, famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE,
  prenom TEXT NOT NULL, nom TEXT, naissance TEXT, ecole TEXT, classe TEXT, allergies TEXT,
  autorises TEXT, urgence TEXT, notes TEXT, actif INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS prestations (
  id INTEGER PRIMARY KEY, libelle TEXT NOT NULL, horaires TEXT, jours TEXT NOT NULL,
  prix REAL NOT NULL DEFAULT 0, places INTEGER NOT NULL DEFAULT 4, ordre INTEGER NOT NULL DEFAULT 0,
  actif INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS reservations (
  id INTEGER PRIMARY KEY, enfant_id INTEGER NOT NULL REFERENCES enfants(id) ON DELETE CASCADE,
  prestation_id INTEGER NOT NULL REFERENCES prestations(id), jour TEXT NOT NULL, prix REAL NOT NULL DEFAULT 0,
  arrivee TEXT, depart TEXT, present INTEGER, cree TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (enfant_id, prestation_id, jour));
CREATE INDEX IF NOT EXISTS idx_resa_jour ON reservations(jour);
CREATE TABLE IF NOT EXISTS fermetures (jour TEXT PRIMARY KEY, motif TEXT);
CREATE TABLE IF NOT EXISTS vacances (id INTEGER PRIMARY KEY, nom TEXT, debut TEXT NOT NULL, fin TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS blocages (id INTEGER PRIMARY KEY, jour TEXT NOT NULL, prestation_id INTEGER, debut TEXT, fin TEXT, motif TEXT);
CREATE TABLE IF NOT EXISTS paiements (
  famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE, mois TEXT NOT NULL,
  montant REAL, date TEXT, mode TEXT, PRIMARY KEY (famille_id, mois));
CREATE TABLE IF NOT EXISTS factures (
  famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE, mois TEXT NOT NULL,
  numero INTEGER NOT NULL UNIQUE, date TEXT NOT NULL, PRIMARY KEY (famille_id, mois));
CREATE TABLE IF NOT EXISTS contrats (
  id INTEGER PRIMARY KEY, numero INTEGER NOT NULL, type TEXT NOT NULL DEFAULT 'contrat',
  famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE, titre TEXT, lignes TEXT, extras TEXT,
  conditions TEXT, date TEXT, statut TEXT NOT NULL DEFAULT 'brouillon', envoye TEXT,
  accepte_le TEXT, accepte_par TEXT, accepte_ip TEXT);
CREATE TABLE IF NOT EXISTS avoirs (
  id INTEGER PRIMARY KEY, numero INTEGER NOT NULL UNIQUE, famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE,
  mois TEXT NOT NULL, facture_numero INTEGER NOT NULL, type TEXT NOT NULL DEFAULT 'partiel', montant REAL NOT NULL,
  motif TEXT, date TEXT NOT NULL, ancienne TEXT);
CREATE TABLE IF NOT EXISTS urgences (
  id INTEGER PRIMARY KEY, famille_id INTEGER NOT NULL REFERENCES familles(id) ON DELETE CASCADE,
  enfant_id INTEGER NOT NULL REFERENCES enfants(id) ON DELETE CASCADE, jour TEXT NOT NULL,
  prestation_id INTEGER NOT NULL REFERENCES prestations(id), debut TEXT, fin TEXT, message TEXT,
  statut TEXT NOT NULL DEFAULT 'attente', reponse TEXT, cree TEXT DEFAULT (datetime('now', 'localtime')), traitee_le TEXT);
CREATE TABLE IF NOT EXISTS medicaments (
  id INTEGER PRIMARY KEY, enfant_id INTEGER NOT NULL REFERENCES enfants(id) ON DELETE CASCADE,
  jour TEXT NOT NULL, heure TEXT, medicament TEXT NOT NULL, dose TEXT, motif TEXT,
  ordonnance INTEGER NOT NULL DEFAULT 0, autorise_par TEXT, notes TEXT, cree TEXT DEFAULT (datetime('now', 'localtime')));
CREATE TABLE IF NOT EXISTS demandes (
  id INTEGER PRIMARY KEY, nom TEXT NOT NULL, email TEXT NOT NULL, tel TEXT, enfants TEXT, besoins TEXT,
  debut TEXT, message TEXT, ip TEXT, traitee INTEGER NOT NULL DEFAULT 0, cree TEXT DEFAULT (datetime('now', 'localtime')));
SQL;

const BESOINS = ['matin' => 'Matin + trajet école', 'midi' => 'Midi', 'soir' => "Soir (sortie d'école)",
                 'mercredi' => 'Mercredi', 'vacances' => 'Vacances scolaires', 'samedi' => 'Samedi matin'];

const PRESENTATION_DEFAUT = "Assistante d'accueil de jeunes enfants à Bonneville, j'accueille vos enfants à mon domicile avant et après l'école, le midi, le mercredi et pendant les vacances scolaires.\n\nJe les accompagne à l'école et je vais les chercher à la sortie. Repas, goûter et petit-déjeuner peuvent être fournis.\n\nUn cadre familial, calme et bienveillant, avec des horaires adaptés aux parents qui travaillent.";

// Colonnes ajoutées après la première version (facturation à l'heure)
const COLONNES_AJOUTEES = [
    'familles' => ['derniere_visite' => 'TEXT'], // dernière visite d'un parent dans son espace (colonne Familles)
    'prestations' => ['debut' => 'TEXT', 'fin' => 'TEXT', 'repas' => 'INTEGER NOT NULL DEFAULT 0',
                      'gouter' => 'INTEGER NOT NULL DEFAULT 0', 'petitdej' => 'INTEGER NOT NULL DEFAULT 0',
                      'periode' => "TEXT NOT NULL DEFAULT 'toujours'", 'libre' => 'INTEGER NOT NULL DEFAULT 0'],
    'reservations' => ['repas' => 'INTEGER NOT NULL DEFAULT 0', 'gouter' => 'INTEGER NOT NULL DEFAULT 0',
                       'petitdej' => 'INTEGER NOT NULL DEFAULT 0', 'prevu_debut' => 'TEXT', 'prevu_fin' => 'TEXT',
                       'nuit' => 'INTEGER NOT NULL DEFAULT 0'], // nuit = l'enfant dort sur place, départ le lendemain
    'enfants' => ['tarif_horaire' => 'REAL', 'tarif_special' => 'INTEGER NOT NULL DEFAULT 0',
                  'sans_repas' => 'INTEGER NOT NULL DEFAULT 0', 'cantine' => 'INTEGER NOT NULL DEFAULT 0'],
    'factures' => ['envoye' => 'TEXT', 'relance' => 'TEXT', 'detail' => 'TEXT', 'total' => 'REAL'],
];

// [libellé, début, fin, jours, places, ordre, repas, goûter, petit-déj, période (ecole / vacances / toujours), heures choisies par les parents]
const PRESTATIONS_DEFAUT = [
    ['Matin + trajet école', '07:30', '08:30', '1,2,4,5', 4, 1, 0, 0, 1, 'ecole', 0],
    ['Midi (repas)', '11:45', '13:30', '1,2,4,5', 4, 2, 1, 0, 0, 'ecole', 0],
    ["Soir (sortie d'école)", '16:30', '18:15', '1,2,4,5', 4, 3, 0, 1, 0, 'ecole', 0],
    ['Mercredi journée', '07:30', '18:00', '3', 4, 4, 1, 1, 1, 'ecole', 0],
    ['Mercredi matin', '07:30', '12:00', '3', 4, 5, 0, 0, 1, 'ecole', 0],
    ['Mercredi après-midi', '13:30', '18:00', '3', 4, 6, 0, 1, 0, 'ecole', 0],
    ['Journée (vacances)', '07:00', '19:00', '1,2,3,4,5', 4, 7, 1, 1, 0, 'vacances', 1],
];
const PERIODES = ['toujours' => 'Toute l\'année', 'ecole' => 'Période scolaire', 'vacances' => 'Vacances scolaires'];

// Vacances scolaires zone A (académie de Grenoble) : du premier au dernier jour de vacances
const VACANCES_DEFAUT = [
    ['Été 2026', '2026-07-04', '2026-08-31'],
    ['Toussaint 2026', '2026-10-17', '2026-11-01'],
    ['Noël 2026', '2026-12-19', '2027-01-03'],
    ['Hiver 2027', '2027-02-13', '2027-02-28'],
    ['Printemps 2027', '2027-04-10', '2027-04-25'],
    ['Été 2027', '2027-07-03', '2027-08-31'],
];

const TARIFS_DEFAUT = [
    'tarif_semaine' => 4, 'tarif_weekend' => 6, 'tarif_ferie' => 6,
    'repas' => 4, 'repas_special' => 5, 'gouter' => 1.5, 'gouter_special' => 2, 'petitdej' => 2,
    'tarif_nuit' => 4, // heures entre nuit_debut et nuit_fin (réglages, 20h00 → 7h00 par défaut)
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    if (!file_exists("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(SCHEMA);
    $migrePrestations = false;
    foreach (COLONNES_AJOUTEES as $table => $cols) {
        $existantes = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($cols as $col => $type) {
            if (!in_array($col, $existantes, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
                if ($table === 'prestations') $migrePrestations = true;
            }
        }
    }
    $aucuneResa = !(int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
    if ($migrePrestations && $aucuneResa) $pdo->exec('DELETE FROM prestations'); // anciennes prestations « au forfait »
    if (!(int)$pdo->query('SELECT COUNT(*) FROM prestations')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO prestations (libelle, debut, fin, jours, places, ordre, repas, gouter, petitdej, periode, libre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (PRESTATIONS_DEFAUT as $p) $ins->execute($p);
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM vacances')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO vacances (nom, debut, fin) VALUES (?, ?, ?)');
        foreach (VACANCES_DEFAUT as $v) $ins->execute($v);
    }
    sauvegarde_auto($pdo, "$dir/sauvegardes");
    return $pdo;
}

/**
 * Une copie de la base par jour (à la première visite du jour), dans data/sauvegardes.
 * On garde les 30 derniers jours + la copie du 1er de chaque mois pour toujours (~100 Ko chacune).
 */
function sauvegarde_auto(PDO $pdo, string $dir): void
{
    $fic = "$dir/amandine-" . date('Y-m-d') . '.sqlite';
    if (file_exists($fic)) return;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($fic)); // copie cohérente, même si quelqu'un écrit en même temps
    } catch (Throwable) {
        if (!@copy(DB_FILE, $fic)) return;
    }
    $copies = glob("$dir/amandine-*.sqlite") ?: [];
    rsort($copies);
    foreach (array_slice($copies, 30) as $vieux) {
        if (!str_ends_with($vieux, '-01.sqlite')) @unlink($vieux);
    }
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function setting(string $k, string $def = ''): string
{
    $v = q('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn();
    return $v === false || $v === null ? $def : (string)$v;
}

/** Nom montré aux parents (appli, emails) ; le nom légal reste sur les factures, contrats et attestations. */
function nom_affiche(): string
{
    return setting('nom_affiche') ?: setting('nom', 'Garde périscolaire');
}

function set_setting(string $k, string $v): void
{
    q('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

/** Vrai si la requête est un POST valide (jeton CSRF vérifié). */
function post(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée : rechargez la page et recommencez.');
    }
    return true;
}

function redirect(string $url): never
{
    header("Location: $url");
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) redirect('index.php');
}

/** Accès en lecture par la tâche planifiée du PC (sauvegardes, copie des factures en PDF) : lien secret ?k=… */
function acces_robot(): bool
{
    $t = setting('sauvegarde_token');
    return $t !== '' && hash_equals($t, (string)($_GET['k'] ?? ''));
}

function require_parent(): int
{
    if (($_SESSION['role'] ?? '') !== 'parent') redirect('index.php');
    return (int)$_SESSION['famille'];
}

/** Amandine regarde l'espace d'une famille « comme les parents » (lecture seule, voir admin.php?p=voir_comme). */
function vue_amandine(): bool
{
    return !empty($_SESSION['vue_amandine']) && ($_SESSION['role'] ?? '') === 'parent';
}

function euro($n): string
{
    return number_format((float)$n, 2, ',', ' ') . ' €';
}

const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const JOURS_COURTS = [1 => 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function date_fr(string $d): string
{
    $t = strtotime($d);
    return JOURS[(int)date('N', $t)] . ' ' . date('j', $t) . ' ' . MOIS[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function mois_fr(string $ym): string
{
    [$y, $m] = explode('-', $ym);
    return ucfirst(MOIS[(int)$m]) . ' ' . $y;
}

function mois_valide($ym): string
{
    return is_string($ym) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) ? $ym : date('Y-m');
}

function date_valide($d): string
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : date('Y-m-d');
}

function mois_decale(string $ym, int $n): string
{
    return date('Y-m', strtotime(sprintf('%s-01 %+d month', $ym, $n)));
}

function jours_du_mois(string $ym): array
{
    $nb = (int)date('t', strtotime("$ym-01"));
    $out = [];
    for ($i = 1; $i <= $nb; $i++) $out[] = sprintf('%s-%02d', $ym, $i);
    return $out;
}

function prestations(): array
{
    static $p = null;
    return $p ??= q('SELECT * FROM prestations WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
}

/** Nom de la période de vacances scolaires qui contient ce jour, ou null. */
function vacances_du_jour(string $d): ?string
{
    static $periodes = null;
    $periodes ??= q('SELECT nom, debut, fin FROM vacances')->fetchAll();
    foreach ($periodes as $v) if ($d >= $v['debut'] && $d <= $v['fin']) return $v['nom'] ?: 'Vacances';
    return null;
}

/** Créneaux proposés ce jour-là (jour de la semaine + période scolaire ou vacances). */
function prest_du_jour(string $d): array
{
    $n = date('N', strtotime($d));
    $vac = vacances_du_jour($d) !== null;
    return array_values(array_filter(prestations(), fn($p) => in_array($n, explode(',', $p['jours']), true)
        && (($p['periode'] ?? 'toujours') === 'toujours' || ($p['periode'] === 'vacances') === $vac)));
}

/** Créneau proposé à cet enfant ? Un enfant qui mange à la cantine n'a pas le créneau du midi des jours d'école. */
function propose(array $p, array $e): bool
{
    return !(!empty($e['cantine']) && $p['repas'] && !array_intersect(explode(',', $p['jours']), ['3', '6', '7']));
}

function fermetures(string $ym): array
{
    return q('SELECT jour, motif FROM fermetures WHERE jour LIKE ?', ["$ym-%"])->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ---------- Fermetures partielles (un après-midi, un créneau…) ----------
// Une ligne de « blocages » ferme soit un créneau précis (prestation_id), soit une plage d'heures (debut / fin) ce jour-là.

function blocages_du_jour(string $d): array
{
    static $cache = [];
    return $cache[$d] ??= q('SELECT * FROM blocages WHERE jour = ? ORDER BY debut, id', [$d])->fetchAll();
}

function chevauche(?string $a1, ?string $a2, ?string $b1, ?string $b2): bool
{
    [$a1, $a2, $b1, $b2] = [minutes($a1), minutes($a2), minutes($b1), minutes($b2)];
    if ($a1 === null || $a2 === null || $b1 === null || $b2 === null) return false;
    return $a1 < $b2 && $b1 < $a2;
}

/** « Soir (sortie d'école) fermé » / « fermé de 13h00 à 18h00 », suivi du motif. */
function texte_blocage(array $b): string
{
    $t = $b['prestation_id']
        ? (q('SELECT libelle FROM prestations WHERE id = ?', [$b['prestation_id']])->fetchColumn() ?: 'Créneau') . ' fermé'
        : 'fermé de ' . hfr(minutes($b['debut'])) . ' à ' . hfr(minutes($b['fin']));
    return $t . ($b['motif'] ? ' (' . $b['motif'] . ')' : '');
}

/** Le blocage qui ferme ce créneau ce jour-là (créneau coché, ou plage d'heures qui le recouvre), sinon null.
 *  Un créneau à heures libres (vacances) n'est pas fermé en entier par une plage : seules ces heures-là le sont. */
function creneau_bloque(string $d, array $p): ?array
{
    foreach (blocages_du_jour($d) as $b) {
        if ($b['prestation_id'] ? (int)$b['prestation_id'] === (int)$p['id']
                                : (empty($p['libre']) && chevauche($p['debut'], $p['fin'], $b['debut'], $b['fin']))) return $b;
    }
    return null;
}

/** Pour un créneau à heures libres : la plage fermée qui recouvre les heures choisies, sinon null. */
function heures_bloquees(string $d, ?string $hd, ?string $hf): ?array
{
    foreach (blocages_du_jour($d) as $b) {
        if (!$b['prestation_id'] && chevauche($hd, $hf, $b['debut'], $b['fin'])) return $b;
    }
    return null;
}

/** Réservations d'un jour touchées par un blocage (pour prévenir les familles ou les annuler). */
function reservations_bloquees(array $b): array
{
    $l = [];
    foreach (q('SELECT r.id, r.prevu_debut, r.prevu_fin, e.prenom, f.nom AS famille, p.id AS pid, p.libelle, p.debut, p.fin, p.libre
                FROM reservations r JOIN enfants e ON e.id = r.enfant_id JOIN familles f ON f.id = e.famille_id
                JOIN prestations p ON p.id = r.prestation_id WHERE r.jour = ? ORDER BY e.prenom', [$b['jour']]) as $r) {
        $touche = $b['prestation_id'] ? (int)$b['prestation_id'] === (int)$r['pid']
            : chevauche($r['libre'] ? ($r['prevu_debut'] ?: $r['debut']) : $r['debut'], $r['libre'] ? ($r['prevu_fin'] ?: $r['fin']) : $r['fin'], $b['debut'], $b['fin']);
        if ($touche) $l[] = $r;
    }
    return $l;
}

/** Un parent ne peut plus modifier ce jour (délai de prévenance dépassé). */
function verrouille(string $d): bool
{
    $delai = max(0, (int)setting('delai_jours', '1'));
    return $d < date('Y-m-d', strtotime("+$delai day"));
}

// ---------- Facturation à l'heure ----------

function tarif(string $k): float
{
    return (float)str_replace(',', '.', setting($k, (string)TARIFS_DEFAUT[$k]));
}

function paques(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $n = $h + $l - 7 * $m + 114;
    return sprintf('%04d-%02d-%02d', $y, intdiv($n, 31), $n % 31 + 1);
}

/** Jours fériés français de l'année : [Y-m-d => nom]. */
function jours_feries(int $y): array
{
    static $cache = [];
    if (isset($cache[$y])) return $cache[$y];
    $p = strtotime(paques($y));
    $f = [
        "$y-01-01" => "Jour de l'An", date('Y-m-d', strtotime('+1 day', $p)) => 'Lundi de Pâques',
        "$y-05-01" => 'Fête du Travail', "$y-05-08" => 'Victoire 1945', date('Y-m-d', strtotime('+39 day', $p)) => 'Ascension',
        date('Y-m-d', strtotime('+50 day', $p)) => 'Lundi de Pentecôte', "$y-07-14" => 'Fête nationale',
        "$y-08-15" => 'Assomption', "$y-11-01" => 'Toussaint', "$y-11-11" => 'Armistice', "$y-12-25" => 'Noël',
    ];
    ksort($f);
    return $cache[$y] = $f;
}

/** Tarif horaire applicable à cet enfant ce jour-là. */
function tarif_horaire(array $enfant, string $d): float
{
    if (isset(jours_feries((int)substr($d, 0, 4))[$d])) return tarif('tarif_ferie');
    if ((int)date('N', strtotime($d)) >= 6) return tarif('tarif_weekend');
    return ($enfant['tarif_horaire'] ?? null) !== null && $enfant['tarif_horaire'] !== '' ? (float)$enfant['tarif_horaire'] : tarif('tarif_semaine');
}

function minutes(?string $hm): ?int
{
    return $hm && preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m) ? (int)$m[1] * 60 + (int)$m[2] : null;
}

function hfr(int $min): string
{
    $min %= 1440;
    return sprintf('%02dH%02d', intdiv($min, 60), $min % 60);
}

function duree_fr(float $h): string
{
    $m = (int)round($h * 60);
    return sprintf('%02dH%02d', intdiv($m, 60), $m % 60);
}

function horaires_prest(array $p): string
{
    return $p['debut'] && $p['fin'] ? hfr(minutes($p['debut'])) . ' – ' . hfr(minutes($p['fin'])) : (string)($p['horaires'] ?? '');
}

/**
 * Calcul de la facture d'une famille pour un mois (même logique que le modèle Excel) :
 * heures réelles (pointage) ou prévues × tarif horaire, + repas / goûter / petit-déjeuner.
 * Les réservations marquées « Absent » ne sont pas facturées.
 */
const ARRONDI_MIN = 15; // facturation au quart d'heure entamé

/** Minutes passées la nuit (de nuit_debut, 20h00, à nuit_fin le lendemain, 7h00) dans des plages [début, fin]
 *  comptées depuis minuit du jour de garde (une fin > 1440 = le lendemain). */
function minutes_de_nuit(array $plages): int
{
    $nd = minutes(setting('nuit_debut', '20:00')) ?? 1200;
    $nf = minutes(setting('nuit_fin', '07:00')) ?? 420;
    if ($nd <= $nf) return 0;
    $n = 0;
    foreach ($plages as [$a, $b]) {
        foreach ([[$nd - 1440, $nf], [$nd, 1440 + $nf], [1440 + $nd, 2880 + $nf]] as [$x, $y]) $n += max(0, min($b, $y) - max($a, $x));
    }
    return $n;
}

function calcul_mois(int $fid, string $ym): array
{
    $rows = q("SELECT r.*, e.prenom, e.tarif_horaire, e.tarif_special, e.sans_repas, p.debut AS p_debut, p.fin AS p_fin
               FROM reservations r JOIN enfants e ON e.id = r.enfant_id JOIN prestations p ON p.id = r.prestation_id
               WHERE e.famille_id = ? AND r.jour LIKE ? AND (r.present IS NULL OR r.present = 1)
               ORDER BY e.prenom, r.jour", [$fid, "$ym-%"])->fetchAll();
    $jours = $extras = [];
    foreach ($rows as $r) {
        $k = $r['enfant_id'] . '|' . $r['jour'];
        $jours[$k] ??= ['prenom' => $r['prenom'], 'jour' => $r['jour'], 'plages' => [], 'tarif' => tarif_horaire($r, $r['jour'])];
        $a = minutes($r['arrivee'] ?: ($r['prevu_debut'] ?: $r['p_debut']));
        $b = minutes($r['depart'] ?: ($r['prevu_fin'] ?: $r['p_fin']));
        if ($a !== null && $b !== null) {
            if ($b <= $a || !empty($r['nuit'])) $b += 1440; // départ le lendemain (nuit sur place, ou départ « avant » l'arrivée)
            // arrivée et départ arrondis au quart d'heure supérieur (10h16 → 10h30)
            $a = (int)ceil($a / ARRONDI_MIN) * ARRONDI_MIN;
            $b = (int)ceil($b / ARRONDI_MIN) * ARRONDI_MIN;
            $jours[$k]['plages'][] = [$a, $b];
        }
        if (!$r['sans_repas']) {
            foreach (['petitdej', 'repas', 'gouter'] as $x) {
                if ($r[$x]) {
                    $jours[$k]['ex'][$x] = true;
                    $extras[$x][$r['enfant_id']]['prenom'] = $r['prenom'];
                    $extras[$x][$r['enfant_id']]['special'] = (int)$r['tarif_special'];
                    $extras[$x][$r['enfant_id']]['jours'][(int)substr($r['jour'], 8, 2)] = true;
                }
            }
        }
    }
    $lignes = [];
    $totalGarde = $heures = 0.0;
    foreach ($jours as $j) {
        usort($j['plages'], fn($x, $y) => $x[0] <=> $y[0]);
        $fusion = [];
        foreach ($j['plages'] as [$a, $b]) { // fusionne les plages qui se chevauchent
            if ($fusion && $a <= $fusion[count($fusion) - 1][1]) $fusion[count($fusion) - 1][1] = max($b, $fusion[count($fusion) - 1][1]);
            else $fusion[] = [$a, $b];
        }
        $h = array_sum(array_map(fn($p) => ($p[1] - $p[0]) / 60, $fusion));
        $hn = minutes_de_nuit($fusion) / 60; // heures de nuit, au tarif de nuit
        $t = round(($h - $hn) * $j['tarif'] + $hn * tarif('tarif_nuit'), 2);
        $lignes[] = $j + ['horaires' => implode(' / ', array_map(fn($p) => hfr($p[0]) . '-' . hfr($p[1]) . ($p[1] > 1440 ? ' (lendemain)' : ''), $fusion)),
                          'heures' => $h, 'heures_nuit' => $hn, 'tarif_nuit' => $hn ? tarif('tarif_nuit') : null, 'total' => $t];
        $totalGarde += $t;
        $heures += $h;
    }
    $noms = ['petitdej' => 'Petit-déjeuner', 'repas' => 'Repas', 'gouter' => 'Goûter'];
    $sup = [];
    $totalExtras = 0.0;
    foreach ($noms as $x => $nom) {
        foreach ($extras[$x] ?? [] as $e) {
            ksort($e['jours']);
            $pu = $x === 'petitdej' ? tarif('petitdej') : tarif($e['special'] ? "{$x}_special" : $x);
            $n = count($e['jours']);
            $sup[] = ['type' => $nom, 'prenom' => $e['prenom'], 'dates' => implode('.', array_keys($e['jours'])), 'qte' => $n, 'pu' => $pu, 'total' => round($n * $pu, 2)];
            $totalExtras += round($n * $pu, 2);
        }
    }
    return ['jours' => $lignes, 'heures' => $heures, 'total_garde' => round($totalGarde, 2),
            'extras' => $sup, 'total_extras' => round($totalExtras, 2), 'total' => round($totalGarde + $totalExtras, 2)];
}

/**
 * Facture d'un mois : le détail figé à l'émission si elle est émise (sinon le calcul en cours),
 * les avoirs partiels sur cette facture et le net à payer.
 */
function calcul_facture(int $fid, string $ym): array
{
    $fac = q('SELECT * FROM factures WHERE famille_id = ? AND mois = ?', [$fid, $ym])->fetch() ?: null;
    $c = $fac && $fac['detail'] ? json_decode($fac['detail'], true) : calcul_mois($fid, $ym);
    $avoirs = $fac ? q("SELECT * FROM avoirs WHERE famille_id = ? AND mois = ? AND facture_numero = ? AND type = 'partiel' ORDER BY numero",
        [$fid, $ym, $fac['numero']])->fetchAll() : [];
    $totalAvoirs = round(array_sum(array_column($avoirs, 'montant')), 2);
    return $c + ['facture' => $fac, 'avoirs' => $avoirs, 'total_avoirs' => $totalAvoirs, 'net' => round($c['total'] - $totalAvoirs, 2)];
}

/** Émet la facture d'un mois : numéro suivant + détail et montant figés. */
function emettre_facture(int $fid, string $ym): int
{
    $num = max((int)setting('prochain_numero', '1'), (int)q('SELECT COALESCE(MAX(numero), 0) + 1 FROM factures')->fetchColumn());
    $c = calcul_mois($fid, $ym);
    q('INSERT OR REPLACE INTO factures (famille_id, mois, numero, date, detail, total) VALUES (?, ?, ?, ?, ?, ?)',
        [$fid, $ym, $num, date('Y-m-d'), json_encode($c, JSON_UNESCAPED_UNICODE), $c['total']]);
    set_setting('prochain_numero', (string)($num + 1));
    return $num;
}

/** $ancienne : ligne de la facture annulée (pour la garder consultable après refacturation). */
function creer_avoir(int $fid, string $ym, int $factureNum, string $type, float $montant, string $motif, ?array $ancienne = null): int
{
    $num = max((int)setting('prochain_avoir', '1'), (int)q('SELECT COALESCE(MAX(numero), 0) + 1 FROM avoirs')->fetchColumn());
    q('INSERT INTO avoirs (numero, famille_id, mois, facture_numero, type, montant, motif, date, ancienne) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$num, $fid, $ym, $factureNum, $type, round($montant, 2), $motif, date('Y-m-d'), $ancienne ? json_encode($ancienne, JSON_UNESCAPED_UNICODE) : null]);
    set_setting('prochain_avoir', (string)($num + 1));
    return $num;
}

/** Totaux d'un mois par enfant : [prenom => [heures, garde, repas, gouter, petitdej, nourriture, total]]. */
function totaux_par_enfant(array $c): array
{
    $t = [];
    $vide = ['heures' => 0.0, 'garde' => 0.0, 'repas' => 0, 'gouter' => 0, 'petitdej' => 0, 'nourriture' => 0.0, 'total' => 0.0];
    foreach ($c['jours'] as $j) {
        $t[$j['prenom']] ??= $vide;
        $t[$j['prenom']]['heures'] += $j['heures'];
        $t[$j['prenom']]['garde'] += $j['total'];
        $t[$j['prenom']]['total'] += $j['total'];
    }
    $cles = ['Repas' => 'repas', 'Goûter' => 'gouter', 'Petit-déjeuner' => 'petitdej'];
    foreach ($c['extras'] as $x) {
        $t[$x['prenom']] ??= $vide;
        $t[$x['prenom']][$cles[$x['type']]] += $x['qte'];
        $t[$x['prenom']]['nourriture'] += $x['total'];
        $t[$x['prenom']]['total'] += $x['total'];
    }
    return $t;
}

function url_base(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
}

/** Version HTML d'un email : même texte, mais les liens vers l'appli deviennent un bouton (l'adresse n'apparaît pas). */
function mail_html(string $texte): string
{
    $html = '';
    foreach (explode("\n", $texte) as $ligne) {
        if (preg_match('#^https?://\S+$#', trim($ligne))) {
            $html .= '<p style="margin:18px 0"><a href="' . h(trim($ligne)) . '" style="background:#2f7d6d;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block">'
                . (str_contains($ligne, 'presentation.php') ? 'Découvrir l\'accueil' : 'Ouvrir l\'espace d\'Amandine') . '</a></p>';
        } else {
            $html .= h($ligne) . "<br>\n";
        }
    }
    return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:#f6f4ef;font-family:Arial,sans-serif;color:#2b2a28">'
        . '<div style="max-width:560px;margin:0 auto;padding:16px"><div style="background:#2f7d6d;color:#fff;padding:14px 18px;border-radius:10px 10px 0 0;font-weight:bold">' . h(nom_affiche()) . '</div>'
        . '<div style="background:#fff;padding:18px;border-radius:0 0 10px 10px;line-height:1.5">' . $html . '</div></div></body></html>';
}

/** Envoie un email texte (UTF-8). Retourne false si le serveur refuse l'envoi. */
function envoyer_mail(string $to, string $sujet, string $texte, string $replyTo = ''): bool
{
    $hote = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $nom = nom_affiche();
    $html = mail_html($texte);
    $b = 'b' . bin2hex(random_bytes(8));
    $texte = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texte\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$b--";
    $headers = [
        'From' => mb_encode_mimeheader($nom, 'UTF-8') . " <noreply@$hote>",
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$b\"",
    ];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers['Reply-To'] = $replyTo;
    elseif (filter_var(setting('email_contact'), FILTER_VALIDATE_EMAIL)) $headers['Reply-To'] = setting('email_contact');
    return @mail($to, mb_encode_mimeheader($sujet, 'UTF-8'), $texte, $headers);
}

// ---------- Alertes WhatsApp (CallMeBot, même service que le gîte) ----------
// Chaque téléphone a sa propre clé CallMeBot ; liste gardée dans le réglage « whatsapp » (JSON).
function destinataires_whatsapp(): array
{
    $l = json_decode(setting('whatsapp', '[]'), true);
    return is_array($l) ? array_values(array_filter($l, fn($d) => !empty($d['tel']) && !empty($d['cle']))) : [];
}

/** 06 12 34 56 78 → 33612345678 (format attendu par CallMeBot). */
function tel_international(string $tel): string
{
    $t = preg_replace('/\D/', '', $tel);
    if (str_starts_with($t, '00')) $t = substr($t, 2);
    elseif (strlen($t) === 10 && $t[0] === '0') $t = '33' . substr($t, 1);
    return $t;
}

/** Envoie le texte à chaque téléphone ; renvoie la réponse de CallMeBot pour chacun (affichée par le bouton de test). */
function alerte_whatsapp(string $texte, ?array $seulement = null): array
{
    $rapport = [];
    foreach ($seulement ? [$seulement] : destinataires_whatsapp() as $d) {
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . urlencode($d['tel']) . '&apikey=' . urlencode($d['cle']) . '&text=' . urlencode($texte);
        $rep = '';
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
            $rep = (string)curl_exec($c);
            curl_close($c);
        } else {
            $rep = (string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
        }
        $txt = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($rep))));
        $rapport[] = ($d['nom'] ?: '+' . $d['tel']) . ' : ' . ($txt === '' ? 'aucune réponse de CallMeBot' : mb_substr($txt, 0, 160));
    }
    return $rapport;
}

// ---------- Notifications ntfy (appli gratuite, sans compte) ----------
// Le téléphone s'abonne au « sujet » secret (réglage ntfy_topic) dans l'appli ntfy.
function alerte_ntfy(string $titre, string $texte, string $lien = ''): bool
{
    $topic = setting('ntfy_topic');
    if ($topic === '' || !function_exists('curl_init')) return false;
    $msg = ['topic' => $topic, 'title' => $titre, 'message' => $texte, 'tags' => ['child']];
    if ($lien !== '') $msg['click'] = $lien;
    $c = curl_init('https://ntfy.sh/');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
    curl_exec($c);
    $ok = curl_getinfo($c, CURLINFO_HTTP_CODE) === 200;
    curl_close($c);
    return $ok;
}

/** Prévient Amandine : ntfy et/ou WhatsApp selon ce qui est configuré, sinon email. */
function prevenir_amandine(string $sujet, string $texte, string $replyTo = '', string $lien = ''): void
{
    $fait = setting('ntfy_topic') !== '' && alerte_ntfy($sujet, $texte, $lien);
    if (destinataires_whatsapp()) { alerte_whatsapp("*$sujet*\n$texte" . ($lien ? "\n$lien" : '')); $fait = true; }
    if ($fait) return;
    if ($lien) $texte .= "\n$lien";
    $dest = setting('email_contact') ?: setting('admin_email');
    if ($dest) envoyer_mail($dest, $sujet, $texte, $replyTo);
}

/** Invitation d'une famille : nouveau mot de passe + lien + mode d'emploi. */
function inviter_famille(int $fid): bool
{
    $fam = q('SELECT * FROM familles WHERE id = ? AND actif = 1', [$fid])->fetch();
    if (!$fam) return false;
    $mdp = mot_de_passe_aleatoire();
    if (!envoyer_mail($fam['email'], 'Votre espace parents – ' . nom_affiche(), texte_invitation($fam, $mdp))) return false;
    q('UPDATE familles SET pass = ? WHERE id = ?', [password_hash($mdp, PASSWORD_DEFAULT), $fid]);
    set_setting("invit_$fid", date('Y-m-d'));
    return true;
}

/** « connectée aujourd'hui à 20h15 » / « hier » / « le 12/10 » / « jamais connectée » (badge pour la liste Familles). */
function derniere_visite_fr(?string $dt): string
{
    if (!$dt) return '<span class="badge warn">jamais connectée</span>';
    $t = strtotime($dt);
    $jour = date('Y-m-d', $t);
    $quand = $jour === date('Y-m-d') ? "aujourd'hui à " . date('G\hi', $t)
        : ($jour === date('Y-m-d', strtotime('-1 day')) ? 'hier à ' . date('G\hi', $t) : 'le ' . date('d/m', $t));
    return '<span class="badge">connectée ' . $quand . '</span>';
}

/**
 * Lien WhatsApp (wa.me) vers le premier numéro de la famille, avec un message de relance déjà écrit :
 * Amandine n'a plus qu'à appuyer sur « Envoyer ». Pas de mot de passe dans le message (il est dans l'email,
 * sinon « Mot de passe oublié »). Renvoie '' si la famille n'a pas de numéro de portable.
 */
function lien_whatsapp_relance(array $fam): string
{
    $tel = tel_international(trim(explode('/', (string)$fam['tel'])[0]));
    if (strlen($tel) < 10) return '';
    $enfants = implode(', ', q('SELECT prenom FROM enfants WHERE famille_id = ? AND actif = 1 ORDER BY prenom', [$fam['id']])->fetchAll(PDO::FETCH_COLUMN));
    $texte = "Bonjour, c'est " . nom_affiche() . " 😊\n"
        . "Nouveauté : vous pouvez maintenant réserver vous-même les créneaux de garde" . ($enfants ? " de $enfants" : '') . " en ligne, depuis votre téléphone.\n\n"
        . "👉 " . url_base() . "index.php\n"
        . "Connectez-vous avec votre email : {$fam['email']}\n"
        . "Le mot de passe est dans l'email « Votre espace parents » que je vous ai envoyé (pensez aux spams). "
        . "Sinon, cliquez sur « Mot de passe oublié ? » pour en choisir un nouveau.\n\n"
        . "📝 Pensez aussi à compléter la fiche de votre enfant dans « Mes enfants » (date de naissance, allergies, personnes autorisées à venir le chercher, contact d'urgence) et à y ajouter une photo.\n\n"
        . "Merci ! " . nom_affiche();
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($texte);
}

function texte_invitation(array $fam, string $mdp): string
{
    $enfants = implode(', ', q('SELECT prenom FROM enfants WHERE famille_id = ? AND actif = 1 ORDER BY prenom', [$fam['id']])->fetchAll(PDO::FETCH_COLUMN));
    return "Bonjour,\n\n"
        . "✨ Nouveauté pour la garde de vos petits : désormais, c'est vous qui choisissez vos créneaux de garde, en ligne, "
        . "quand vous le souhaitez, depuis votre téléphone ou votre ordinateur. Plus besoin de messages ou de papiers !\n\n"
        . "Votre espace parents" . ($enfants ? " pour $enfants" : '') . " est prêt. Vous pouvez y :\n"
        . "- réserver les créneaux du mois (matin, midi, soir, mercredi, vacances) ;\n"
        . "- demander une garde de dernière minute.\n\n"
        . "Vos identifiants :\nEmail : {$fam['email']}\nMot de passe : $mdp\n"
        . "Après votre première connexion, vous pourrez choisir votre propre mot de passe dans « Mon compte ».\n\n"
        . url_base() . "index.php\n\n"
        . "Astuce : sur votre téléphone, ajoutez l'appli « Amandine » à votre écran d'accueil (bouton « Installer l'appli » en haut de la page, ou sur iPhone : Partager puis « Sur l'écran d'accueil »).\n\n"
        . "Cordialement,\n" . nom_affiche() . (setting('tel') ? "\n" . setting('tel') : '') . "\n";
}

/** Email de facture (ou de relance) pour une famille et un mois déjà émis. */
function mail_facture(int $fid, string $ym, bool $relance = false): bool
{
    $fam = q('SELECT * FROM familles WHERE id = ?', [$fid])->fetch();
    $fac = q('SELECT * FROM factures WHERE famille_id = ? AND mois = ?', [$fid, $ym])->fetch();
    if (!$fam || !$fac) return false;
    $c = calcul_facture($fid, $ym);
    $n = (int)$fac['numero'];
    $sujet = ($relance ? 'Rappel : facture' : 'Facture') . " N° $n – Garderie " . mois_fr($ym);
    $texte = "Bonjour,\n\n"
        . ($relance
            ? "Sauf erreur de notre part, la facture N° $n de " . mois_fr($ym) . " d'un montant de " . euro($c['net']) . " n'a pas encore été réglée.\n"
            : "Votre facture N° $n pour la garderie de " . mois_fr($ym) . " est disponible.\n")
        . "\nMontant : " . euro($c['net']) . ' (' . duree_fr($c['heures']) . " de garde"
        . ($c['total_extras'] > 0 ? ' + ' . euro($c['total_extras']) . ' de repas/goûters' : '')
        . ($c['total_avoirs'] > 0 ? ' – avoir ' . euro($c['total_avoirs']) : '') . ")\n"
        . "\nPour la consulter ou l'imprimer, connectez-vous avec votre email et votre mot de passe :\n"
        . url_base() . 'facture.php?m=' . $ym . "\n"
        . (setting('iban') ? "\nRèglement par virement : IBAN " . setting('iban') . " – référence facture $n\n" : '')
        . "\nMode de règlement : comptant dès réception de la facture.\n"
        . "\nCordialement,\n" . nom_affiche() . (setting('tel') ? "\n" . setting('tel') : '') . "\n";
    if (!envoyer_mail($fam['email'], $sujet, $texte)) return false;
    q('UPDATE factures SET ' . ($relance ? 'relance' : 'envoye') . " = datetime('now', 'localtime') WHERE famille_id = ? AND mois = ?", [$fid, $ym]);
    return true;
}

// ---------- Devis et contrats ----------

const CONDITIONS_DEFAUT = "Le contrat une fois validé fera l'objet d'une facturation mensuelle, avec un règlement comptant à réception de la facture.\nLes horaires de ce contrat pourront être modifiés en cas de besoin.";
const STATUTS_CONTRAT = ['brouillon' => 'Brouillon', 'envoye' => 'En attente d\'accord', 'accepte' => 'Accepté'];

/** Calcul d'un devis / contrat : lignes de garde (heures × tarif) + repas / goûter / petit-déj. */
function calcul_contrat(array $c): array
{
    $lignes = [];
    $heures = $garde = 0.0;
    foreach (json_decode((string)$c['lignes'], true) ?: [] as $l) {
        $a = minutes($l['debut'] ?? '');
        $b = minutes($l['fin'] ?? '');
        $h = 0.0;
        if ($a !== null && $b !== null) $h = (($b <= $a ? $b + 1440 : $b) - $a) / 60;
        $t = round($h * (float)($l['tarif'] ?? 0), 2);
        $lignes[] = $l + ['heures' => $h, 'total' => $t, 'horaires' => $a !== null && $b !== null ? hfr($a) . '-' . hfr($b) : ''];
        $heures += $h;
        $garde += $t;
    }
    $extras = [];
    $totalExtras = 0.0;
    $noms = ['petitdej' => 'Petit-déjeuner', 'repas' => 'Repas', 'gouter' => 'Goûter'];
    foreach (json_decode((string)$c['extras'], true) ?: [] as $k => $x) {
        if (empty($x['qte'])) continue;
        $t = round((int)$x['qte'] * (float)$x['pu'], 2);
        $extras[] = ['type' => $noms[$k] ?? $k, 'dates' => $x['dates'] ?? '', 'qte' => (int)$x['qte'], 'pu' => (float)$x['pu'], 'total' => $t];
        $totalExtras += $t;
    }
    return ['lignes' => $lignes, 'heures' => $heures, 'total_garde' => round($garde, 2), 'extras' => $extras,
            'total_extras' => round($totalExtras, 2), 'total' => round($garde + $totalExtras, 2)];
}

function mail_contrat(array $c): bool
{
    $fam = q('SELECT * FROM familles WHERE id = ?', [$c['famille_id']])->fetch();
    if (!$fam) return false;
    $doc = $c['type'] === 'devis' ? 'devis' : 'contrat';
    $total = calcul_contrat($c)['total'];
    $texte = "Bonjour,\n\nVoici le $doc N° {$c['numero']} : " . ($c['titre'] ?: 'garde de vos enfants') . ".\n"
        . 'Montant : ' . euro($total) . "\n\nPour le consulter et donner votre accord en ligne (« Bon pour accord »), connectez-vous avec votre email et votre mot de passe :\n"
        . url_base() . 'contrat.php?id=' . $c['id'] . "\n\nCordialement,\n" . nom_affiche() . (setting('tel') ? "\n" . setting('tel') : '') . "\n";
    return envoyer_mail($fam['email'], ucfirst($doc) . " N° {$c['numero']} – " . nom_affiche(), $texte);
}

/** Factures émises non (ou pas entièrement) payées, toutes périodes. */
function impayes(): array
{
    $rows = q('SELECT fa.*, f.nom, f.email, f.tel, pa.montant AS paye, pa.date AS paye_le FROM factures fa
               JOIN familles f ON f.id = fa.famille_id
               LEFT JOIN paiements pa ON pa.famille_id = fa.famille_id AND pa.mois = fa.mois
               ORDER BY fa.mois, f.nom')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $total = calcul_facture((int)$r['famille_id'], $r['mois'])['net'];
        $reste = round($total - (float)($r['paye_le'] ? $r['paye'] : 0), 2);
        if ($reste > 0.009) $out[] = array_merge($r, ['total' => $total, 'reste' => $reste,
            'jours' => (int)((time() - strtotime($r['date'])) / 86400)]);
    }
    return $out;
}

/** Ligne « N° de déclaration SAP / agrément » pour les documents (vide si non renseigné). */
function ligne_sap(): string
{
    return setting('num_sap') ? '<br>' . h(setting('num_sap')) : '';
}

/** Familles ayant des paiements dans l'année (donc une attestation fiscale à remettre). */
function familles_attestation(int $an): array
{
    return q("SELECT DISTINCT f.id, f.nom, f.email FROM familles f JOIN paiements p ON p.famille_id = f.id
              JOIN factures fa ON fa.famille_id = p.famille_id AND fa.mois = p.mois
              WHERE substr(p.date, 1, 4) = ? ORDER BY f.nom", [(string)$an])->fetchAll();
}

function mail_attestation(array $f, int $an): bool
{
    $texte = "Bonjour,\n\nVotre attestation fiscale $an pour les frais de garde est disponible.\n"
        . "Elle est à joindre à votre déclaration de revenus (frais de garde, hors repas).\n\n"
        . "Pour la consulter et l'imprimer, connectez-vous avec votre email et votre mot de passe :\n"
        . url_base() . "attestation.php?a=$an\n\nCordialement,\n" . nom_affiche() . (setting('tel') ? "\n" . setting('tel') : '') . "\n";
    if (!envoyer_mail($f['email'], "Attestation fiscale $an – " . nom_affiche(), $texte)) return false;
    set_setting("attest_{$an}_{$f['id']}", date('Y-m-d'));
    return true;
}

function age(?string $naissance, ?string $le = null): ?int
{
    return $naissance ? (new DateTime($naissance))->diff(new DateTime($le ?? 'today'))->y : null;
}

/** Anniversaires des enfants actifs dans les $jours prochains jours : [[enfant, date, age], …]. */
function anniversaires(int $jours = 14): array
{
    $out = [];
    $auj = new DateTime('today');
    foreach (q("SELECT e.prenom, e.nom, e.naissance, f.nom AS famille FROM enfants e JOIN familles f ON f.id = e.famille_id
                WHERE e.actif = 1 AND f.actif = 1 AND e.naissance IS NOT NULL AND e.naissance <> ''") as $e) {
        $n = new DateTime($e['naissance']);
        $anniv = new DateTime($auj->format('Y') . $n->format('-m-d'));
        if ($anniv < $auj) $anniv->modify('+1 year');
        $dans = (int)$auj->diff($anniv)->days;
        if ($dans <= $jours) $out[] = $e + ['date' => $anniv->format('Y-m-d'), 'dans' => $dans, 'age' => (int)$anniv->format('Y') - (int)$n->format('Y')];
    }
    usort($out, fn($a, $b) => $a['dans'] <=> $b['dans']);
    return $out;
}

// ---------- Lecture d'une photo de feuille d'heures (API Claude) ----------

/** Réduit la photo (max 1600 px, JPEG) pour l'envoi ; renvoie [données binaires, type MIME]. */
// ---------- Photo de l'enfant (data/photos, protégé : servie par photo.php aux seuls admin / parents de l'enfant) ----------
function photo_enfant_fichier(int $id, bool $grand = false): string
{
    return dirname(DB_FILE) . "/photos/enfant-$id" . ($grand ? '-grand' : '') . '.jpg';
}

function supprimer_photo_enfant(int $id): void
{
    @unlink(photo_enfant_fichier($id));
    @unlink(photo_enfant_fichier($id, true));
}

/** Supprime tout : photo de profil et photos de fond (enfant supprimé). */
function supprimer_photos_enfant(int $id): void
{
    supprimer_photo_enfant($id);
    foreach (photos_fond($id) as $nom) supprimer_photo_fond($id, $nom);
}

/** Enregistre la photo envoyée ($_FILES['photo']) : recadrée en carré 400×400, JPEG. Renvoie un message d'erreur ou ''. */
function enregistrer_photo_enfant(int $id, array $f): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return "La photo n'a pas pu être envoyée (trop lourde ?).";
    if (!function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring((string)file_get_contents($f['tmp_name'])))) return "Ce fichier n'est pas une photo lisible (JPEG ou PNG).";
    if (function_exists('exif_read_data') && ($exif = @exif_read_data($f['tmp_name'])) && !empty($exif['Orientation'])) {
        $img = match ((int)$exif['Orientation']) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img };
    }
    $w = imagesx($img); $h = imagesy($img); $c = min($w, $h);
    $carre = imagecreatetruecolor(400, 400);
    imagecopyresampled($carre, $img, 0, 0, (int)(($w - $c) / 2), (int)(($h - $c) / 3), 400, 400, $c, $c); // un peu vers le haut : le visage
    $dir = dirname(photo_enfant_fichier($id));
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    imagejpeg($carre, photo_enfant_fichier($id), 85);
    // version entière (1600 px max) pour le fond de l'espace parents
    $r = min(1, 1600 / max($w, $h));
    $grand = imagecreatetruecolor((int)($w * $r), (int)($h * $r));
    imagecopyresampled($grand, $img, 0, 0, 0, 0, (int)($w * $r), (int)($h * $r), $w, $h);
    imagejpeg($grand, photo_enfant_fichier($id, true), 80);
    return '';
}

/** Vignette ronde de l'enfant (ou son initiale si pas de photo). */
function avatar(array $e, int $px = 40): string
{
    $st = "width:{$px}px;height:{$px}px;border-radius:50%;flex:none;vertical-align:middle;";
    $f = photo_enfant_fichier((int)$e['id']);
    if (is_file($f)) return '<img src="photo.php?e=' . (int)$e['id'] . '&v=' . filemtime($f) . '" alt="" style="' . $st . 'object-fit:cover">';
    return '<span style="' . $st . 'display:inline-flex;align-items:center;justify-content:center;background:var(--line,#ddd);color:#555;font-weight:600;font-size:' . (int)($px * .45) . 'px">'
        . h(mb_strtoupper(mb_substr((string)$e['prenom'], 0, 1))) . '</span>';
}

// ---------- Photos de fond de l'espace parents (plusieurs par enfant, une au hasard à chaque page) ----------
const PHOTOS_FOND_MAX = 20;

/** Noms des photos de fond d'un enfant (fond-ID-xxxx.jpg dans data/photos). */
function photos_fond(int $eid): array
{
    $l = array_map('basename', glob(dirname(DB_FILE) . "/photos/fond-$eid-*.jpg") ?: []);
    sort($l);
    return $l;
}

/** Ajoute les photos envoyées ($_FILES['fonds'], champ multiple) ; renvoie le nombre ajouté et les erreurs. */
function ajouter_photos_fond(int $eid, array $f): array
{
    $n = 0; $err = [];
    foreach ((array)($f['tmp_name'] ?? []) as $i => $tmp) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (count(photos_fond($eid)) >= PHOTOS_FOND_MAX) { $err[] = 'Maximum ' . PHOTOS_FOND_MAX . ' photos par enfant.'; break; }
        if ($f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || !function_exists('imagecreatefromstring')
            || !($img = @imagecreatefromstring((string)file_get_contents($tmp)))) { $err[] = ($f['name'][$i] ?? 'photo') . ' : illisible ou trop lourde.'; continue; }
        if (function_exists('exif_read_data') && ($exif = @exif_read_data($tmp)) && !empty($exif['Orientation'])) {
            $img = match ((int)$exif['Orientation']) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img };
        }
        $w = imagesx($img); $h = imagesy($img); $r = min(1, 1600 / max($w, $h));
        $grand = imagecreatetruecolor((int)($w * $r), (int)($h * $r));
        imagecopyresampled($grand, $img, 0, 0, 0, 0, (int)($w * $r), (int)($h * $r), $w, $h);
        $dir = dirname(photo_enfant_fichier($eid));
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        imagejpeg($grand, "$dir/fond-$eid-" . date('YmdHis') . bin2hex(random_bytes(2)) . '.jpg', 80);
        $n++;
    }
    return [$n, $err];
}

function supprimer_photo_fond(int $eid, string $nom): void
{
    if (preg_match("/^fond-$eid-[0-9a-f]+\.jpg$/", $nom)) @unlink(dirname(DB_FILE) . "/photos/$nom");
}

/** Vignettes des photos de fond avec, si $form, une case « retirer » pour chacune (name="suppr_fond[]"). */
function vignettes_fond(int $eid, bool $form = true): string
{
    $html = '';
    foreach (photos_fond($eid) as $nom) {
        $img = '<img src="photo.php?e=' . $eid . '&fond=' . h($nom) . '" alt="" style="width:84px;height:64px;object-fit:cover;border-radius:6px;display:block">';
        $html .= $form ? '<label style="margin:0;text-align:center;font-size:.8rem;color:var(--muted)">' . $img
            . '<input type="checkbox" name="suppr_fond[]" value="' . h($nom) . '" style="display:inline;width:auto"> retirer</label>' : $img;
    }
    return $html ? '<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin:.4rem 0">' . $html . '</div>' : '';
}

// ---------- Feuilles d'heures photographiées : photos gardées 3 mois, lignes lues en attente de vérification ----------
function dossier_feuilles(): string
{
    return dirname(DB_FILE) . '/feuilles';
}

/** Lignes lues sur les photos, pas encore importées (gardées sur le serveur, pas seulement dans le navigateur). */
function feuilles_en_attente(?array $lignes = null): array
{
    if ($lignes !== null) set_setting('photo_lignes', $lignes ? json_encode($lignes, JSON_UNESCAPED_UNICODE) : '');
    $l = json_decode(setting('photo_lignes', '[]') ?: '[]', true);
    return is_array($l) ? $l : [];
}

/** Garde une copie de la photo envoyée (déjà réduite) et efface celles de plus de 3 mois. Renvoie le nom du fichier. */
function garder_feuille(string $jpeg): string
{
    $dir = dossier_feuilles();
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    foreach (glob("$dir/*.jpg") ?: [] as $vieux) if (filemtime($vieux) < time() - 90 * 86400) @unlink($vieux);
    $nom = date('Y-m-d_His') . '-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents("$dir/$nom", $jpeg);
    return $nom;
}

function preparer_photo(string $chemin, string $mime): array
{
    $brut = file_get_contents($chemin);
    if (!function_exists('imagecreatefromstring') || !($img = @imagecreatefromstring($brut))) return [$brut, $mime];
    $w = imagesx($img); $h = imagesy($img);
    $r = min(1, 1600 / max($w, $h));
    if ($r < 1) {
        $petit = imagecreatetruecolor((int)($w * $r), (int)($h * $r));
        imagecopyresampled($petit, $img, 0, 0, 0, 0, (int)($w * $r), (int)($h * $r), $w, $h);
        $img = $petit;
    }
    ob_start();
    imagejpeg($img, null, 85);
    return [ob_get_clean(), 'image/jpeg'];
}

/**
 * Envoie la photo à Claude et renvoie les lignes lues :
 * [['enfant', 'date', 'arrivee', 'depart', 'repas', 'gouter', 'petitdej', 'incertain', 'note'], …]
 */
function lire_photo_heures(string $donnees, string $mime, string $moisIndice): array
{
    $cle = setting('anthropic_key');
    if ($cle === '') throw new RuntimeException("Clé API Anthropic manquante : ajoutez-la dans Réglages.");
    $enfants = q('SELECT e.prenom, e.nom FROM enfants e JOIN familles f ON f.id = e.famille_id WHERE e.actif = 1 AND f.actif = 1 ORDER BY e.prenom')->fetchAll();
    $liste = implode(', ', array_map(fn($e) => trim($e['prenom'] . ' ' . $e['nom']), $enfants));
    $consigne = "Cette photo montre une feuille d'heures de garde d'enfants, écrite à la main par une assistante d'accueil.\n"
        . "Enfants accueillis (utilise exactement ces prénoms) : $liste.\n"
        . "Si un prénom écrit ne correspond à aucun enfant de cette liste, recopie-le tel qu'il est écrit et mets incertain à true : "
        . "ne le remplace jamais par un autre prénom de la liste.\n"
        . "Mois concerné si l'année ou le mois manque sur la feuille : " . mois_fr($moisIndice) . " ($moisIndice).\n\n"
        . "Relève chaque période de présence : une entrée par enfant et par plage horaire continue "
        . "(si un enfant est venu de 7h30 à 8h30 puis de 16h30 à 18h, fais deux entrées). "
        . "Dates au format AAAA-MM-JJ, heures au format HH:MM sur 24 h (minuit = 00:00). "
        . "Si un enfant est noté absent (« pas là », « absent », « malade », barré…), fais une seule entrée pour ce jour avec absent à true et arrivee / depart vides. "
        . "Indique repas, goûter ou petit-déjeuner seulement s'ils sont notés. "
        . "Mets incertain à true quand un prénom, une date ou une heure est difficile à lire, et explique en une phrase dans note. "
        . "N'invente rien : ignore ce qui n'est pas une présence d'enfant."
        . (($ign = noms_ignores($enfants)) ? "\nIgnore complètement tout ce qui concerne " . implode(', ', $ign)
            . " : ce sont des rendez-vous personnels de la famille d'Amandine, pas des enfants gardés." : '');
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['lignes'], 'properties' => ['lignes' => [
        'type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['enfant', 'date', 'absent', 'arrivee', 'depart', 'repas', 'gouter', 'petitdej', 'incertain', 'note'],
            'properties' => [
                'enfant' => ['type' => 'string'], 'date' => ['type' => 'string'], 'absent' => ['type' => 'boolean'],
                'arrivee' => ['type' => 'string'], 'depart' => ['type' => 'string'],
                'repas' => ['type' => 'boolean'], 'gouter' => ['type' => 'boolean'], 'petitdej' => ['type' => 'boolean'],
                'incertain' => ['type' => 'boolean'], 'note' => ['type' => 'string'],
            ]]]]];
    $corps = [
        'model' => 'claude-opus-5-5',
        'max_tokens' => 16000,
        'fallbacks' => 'default',
        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($donnees)]],
            ['type' => 'text', 'text' => $consigne],
        ]]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $cle, 'anthropic-version: 2026-10-21',
                               'anthropic-beta: server-side-fallback-2026-07-01'],
        CURLOPT_POSTFIELDS => json_encode($corps),
    ]);
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($rep === false) throw new RuntimeException("Connexion à l'API impossible : $err");
    $json = json_decode($rep, true);
    if ($code !== 200) {
        $msg = $json['error']['message'] ?? substr((string)$rep, 0, 200);
        throw new RuntimeException(match (true) {
            $code === 401 => 'Clé API refusée : vérifiez la clé dans Réglages.',
            $code === 429 => "Trop de demandes ou limite de dépense atteinte sur la clé API. Réessayez plus tard.",
            $code >= 500 => "Le service Claude est momentanément indisponible ($code). Réessayez dans quelques minutes.",
            default => "Erreur de l'API ($code) : $msg",
        });
    }
    if (($json['stop_reason'] ?? '') === 'refusal') throw new RuntimeException("La photo n'a pas pu être analysée. Essayez une autre photo.");
    $texte = '';
    foreach ($json['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $texte .= $b['text'];
    $data = json_decode($texte, true);
    if (!is_array($data['lignes'] ?? null)) throw new RuntimeException("Réponse illisible. Reprenez la photo plus nette et bien à plat.");
    // sécurité : on retire aussi côté serveur les lignes au nom d'une personne à ignorer
    $ign = array_map('nom_simple', noms_ignores($enfants));
    return array_values(array_filter($data['lignes'], fn($l) => !in_array(nom_simple(strtok(trim((string)($l['enfant'] ?? '')), ' ') ?: ''), $ign, true)));
}

/** « Élise » → « elise » : comparaison de prénoms sans accents ni majuscules. */
function nom_simple(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtolower(preg_replace('/[^a-z]/i', '', $t !== false ? $t : $s));
}

/** Prénoms notés sur les feuilles d'heures mais à ne jamais importer (rendez-vous perso), sauf s'ils sont aussi un enfant gardé. */
function noms_ignores(?array $enfants = null): array
{
    $enfants ??= q('SELECT prenom FROM enfants WHERE actif = 1')->fetchAll();
    $gardes = array_map(fn($e) => nom_simple($e['prenom']), $enfants);
    $noms = array_filter(array_map('trim', explode(',', setting('noms_ignores', 'Enzo, Amandine, Christophe'))));
    return array_values(array_filter($noms, fn($n) => !in_array(nom_simple($n), $gardes, true)));
}

/** Créneau du jour qui recouvre le mieux une plage horaire (sinon le plus large du jour). */
function creneau_pour(string $jour, string $arrivee, string $depart): ?array
{
    $a = minutes($arrivee); $b = minutes($depart);
    if ($a === null || $b === null) return null;
    if ($b <= $a) $b += 1440;
    $meilleur = null; $score = -1;
    foreach (prest_du_jour($jour) as $p) {
        $pa = minutes($p['debut']); $pb = minutes($p['fin']);
        if ($pa === null || $pb === null) continue;
        $commun = max(0, min($b, $pb) - max($a, $pa));
        // recouvrement d'abord, puis le créneau propre à ce jour (« Mercredi journée » plutôt que « Journée vacances »), puis le plus court
        $s = $commun * 1000000 - count(explode(',', $p['jours'])) * 10000 - ($pb - $pa);
        if ($commun > 0 && $s > $score) { $score = $s; $meilleur = $p; }
    }
    if ($meilleur) return $meilleur;
    $jourPrest = prest_du_jour($jour);
    usort($jourPrest, fn($x, $y) => (minutes($y['fin']) - minutes($y['debut'])) <=> (minutes($x['fin']) - minutes($x['debut'])));
    return $jourPrest[0] ?? null;
}

/** Insère une réservation en reprenant les repas / goûter / petit-déj prévus par la prestation. */
function reserver(int $enfant, array $p, string $d, ?string $debut = null, ?string $fin = null): void
{
    q('INSERT OR IGNORE INTO reservations (enfant_id, prestation_id, jour, repas, gouter, petitdej, prevu_debut, prevu_fin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$enfant, $p['id'], $d, (int)$p['repas'], (int)$p['gouter'], (int)$p['petitdej'], $debut, $fin]);
}

/** Heure HH:MM valide, sinon null. */
function heure_valide($h): ?string
{
    return is_string($h) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : null;
}

function mot_de_passe_aleatoire(): string
{
    $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s;
}

/** Balises qui rendent le site installable comme une appli (PC et téléphone). */
function entete_appli(): string
{
    return '<link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#2f7d6d">'
        . '<link rel="icon" href="icones/favicon.ico"><link rel="apple-touch-icon" href="icones/apple-touch-icon.png">'
        . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Amandine"><meta name="application-name" content="Amandine">';
}

/**
 * Espace parents : une photo de leurs enfants en fond d'écran, voilée pour garder le texte lisible,
 * tirée au hasard à chaque page parmi les photos de fond (sinon les photos de profil).
 * parent.php indique la famille dans $GLOBALS['fond_famille'].
 */
function fond_famille(): string
{
    $fid = (int)($GLOBALS['fond_famille'] ?? 0);
    if (!$fid) return '';
    $fonds = $profils = [];
    foreach (q('SELECT id FROM enfants WHERE famille_id = ? AND actif = 1', [$fid])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (photos_fond((int)$id) as $nom) $fonds[] = "photo.php?e=$id&fond=" . rawurlencode($nom);
        $f = photo_enfant_fichier((int)$id, true);
        if (is_file($f) || is_file(photo_enfant_fichier((int)$id))) $profils[] = "photo.php?e=$id&g=1&v=" . filemtime(is_file($f) ? $f : photo_enfant_fichier((int)$id));
    }
    $choix = $fonds ?: $profils;
    if (!$choix) return '';
    $url = $choix[random_int(0, count($choix) - 1)];
    // photo entière (sans zoom ni coupe) au centre ; le reste de l'écran est rempli par la même photo floutée.
    // Voile léger seulement derrière le contenu pour garder le texte lisible.
    return "<style>html::before,body::before{content:'';position:fixed;inset:0;pointer-events:none}"
        . "html::before{background:url('$url') center/cover;filter:blur(24px) brightness(.85);transform:scale(1.1);z-index:-2}"
        . "body::before{background:url('$url') center/contain no-repeat;z-index:-1}body{background:#3a3833}"
        . "main.wrap{background:rgba(246,244,239,.35);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);border-radius:12px;margin-top:1rem;margin-bottom:1rem;padding-top:.1rem;padding-bottom:1rem}"
        // tableau des réservations et encadrés : blanc transparent pour laisser voir la photo
        . "main table,main .card{background:rgba(255,255,255,.5)}main tr.today td{background:rgba(255,251,230,.7)}main tr.closed td{background:rgba(238,234,226,.6)}"
        . "main th,main td{border-bottom-color:rgba(0,0,0,.08)}main h1,main h2,main .muted{text-shadow:0 0 6px rgba(255,255,255,.9)}"
        // textes hors du tableau (estimation, explications…) : petit fond clair et gris plus foncé
        . "main p,main details:not(.card){background:rgba(255,255,255,.8);border-radius:8px;padding:.35rem .6rem}main .muted{color:#4a463f}</style>";
}

function page_debut(string $titre): void
{
    $nom = nom_affiche();
    $nav = [];
    if (is_admin()) {
        $nav = [
            'admin.php?p=jour' => "Aujourd'hui" . (($u = (int)q("SELECT COUNT(*) FROM urgences WHERE statut = 'attente'")->fetchColumn()) ? " ($u)" : ''),'admin.php?p=planning' => 'Planning', 'admin.php?p=familles' => 'Familles',
            'admin.php?p=factures' => 'Factures', 'admin.php?p=recap' => 'Récap', 'admin.php?p=contrats' => 'Devis / contrats', 'admin.php?p=sante' => 'Santé',
            'admin.php?p=demandes' => 'Demandes' . (($n = (int)q('SELECT COUNT(*) FROM demandes WHERE traitee = 0')->fetchColumn()) ? " ($n)" : ''),
            'admin.php?p=prestations' => 'Créneaux',
            'admin.php?p=fermetures' => 'Fermetures', 'admin.php?p=reglages' => 'Réglages', 'aide.php' => 'Aide',
        ];
    } elseif (($_SESSION['role'] ?? '') === 'parent') {
        $nav = ['parent.php' => 'Réservations', 'parent.php?v=factures' => 'Factures', 'parent.php?v=contrats' => 'Contrats', 'parent.php?v=enfants' => 'Mes enfants', 'parent.php?v=compte' => 'Mon compte'];
    }
    if ($nav) $nav[vue_amandine() ? 'index.php?retour_amandine=1' : 'index.php?logout=1'] = vue_amandine() ? '← Mon espace' : 'Déconnexion';
    $actuel = basename($_SERVER['PHP_SELF']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titre) ?> – <?= h($nom) ?></title><link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>"><?= entete_appli() ?><?= fond_famille() ?></head>
<body><header class="top"><div class="wrap"><a class="brand" href="index.php"><?= h($nom) ?></a>
<?php if ($nav): ?><nav><?php foreach ($nav as $url => $lib): ?>
<a href="<?= h($url) ?>"<?= $actuel === $url || str_starts_with($actuel, "$url&") ? ' class="on"' : '' ?>><?= h($lib) ?></a>
<?php endforeach ?></nav><?php endif ?>
<button type="button" id="btn-installer" class="small light" style="display:none;margin-left:auto">📲 Installer l'appli</button></div></header>
<?php if (vue_amandine()): ?><div style="background:#fff3c4;border-bottom:2px solid #e0b000;padding:.5rem 1rem;text-align:center;font-weight:600">
  👀 Aperçu : vous voyez exactement ce que voient les parents (rien n'est enregistré). <a href="index.php?retour_amandine=1">Revenir à mon espace</a></div><?php endif ?>
<main class="wrap"><h1><?= h($titre) ?></h1>
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?><div class="flash <?= h($type) ?>"><?= h($msg) ?></div><?php endforeach;
    unset($_SESSION['flash']);
}

function page_fin(): void
{
    echo "</main><script src=\"app.js?v=" . @filemtime(__DIR__ . '/app.js') . "\"></script></body></html>";
}
