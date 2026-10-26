<?php
// Aide : mois type, lexique et « comment faire » pour Amandine
require __DIR__ . '/inc.php';
require_admin();

// [mot, définition, où le trouver]
const LEXIQUE = [
    ['Absent', "Enfant réservé qui n'est pas venu. Une réservation « Absent » n'est pas facturée (ni heures, ni repas).", "Aujourd'hui → colonne Présence"],
    ['Agenda du téléphone', "Lien secret qui affiche dans l'agenda du téléphone (Google ou iPhone) les créneaux réservés, les fermetures et les anniversaires.", 'Réglages → Agenda du téléphone'],
    ['Anniversaires', "Rappel des anniversaires des enfants (7 jours à l'avance sur Aujourd'hui, 60 jours dans Santé). Nécessite la date de naissance dans la fiche enfant.", "Aujourd'hui, Santé"],
    ['Annuler et refacturer', "Quand le pointage d'un mois déjà facturé a changé : crée un avoir qui annule l'ancienne facture et émet une nouvelle facture corrigée avec un nouveau numéro.", 'Factures → message rouge « Pointage modifié après émission »'],
    ['Appli (installer)', "Le site s'installe comme une appli « Amandine » sur PC et téléphone (icône, fenêtre sans barre d'adresse). Bouton « 📲 Installer l'appli » en haut ; sur iPhone : Partager → Sur l'écran d'accueil.", 'En haut de chaque page'],
    ['Aperçu (invitation)', "Montre l'email d'invitation exactement comme la famille le recevra, sans rien envoyer.", 'Familles → lien « aperçu »'],
    ['Attestation fiscale', "Document annuel remis aux parents pour leur déclaration d'impôts : sommes payées dans l'année pour la garde (les repas sont exclus). À envoyer avant le 31 mars.", 'Récap → Attestations fiscales'],
    ['Avoir', "Facture « en moins » : annule tout ou partie d'une facture déjà émise (geste commercial, absence remboursée, erreur). Numéroté AV-1, AV-2…", 'Factures → « avoir… » sur la ligne'],
    ['Brouillon', "Facture, devis ou contrat pas encore émis/envoyé : modifiable, invisible pour les parents.", 'Factures, Devis / contrats'],
    ['Cantine (mange à la cantine)', "Option de la fiche enfant : le créneau du midi n'est plus proposé les jours d'école (le mercredi reste possible).", 'Familles → fiche enfant'],
    ['Clé API', "Code personnel qui permet à l'appli de lire les photos de feuilles d'heures (service Claude, ~1 centime par photo).", 'Réglages → Lecture des photos'],
    ['Contrat / Devis', "Document qui récapitule les jours, horaires et prix prévus. Envoyé à la famille, qui donne son accord en ligne (« Bon pour accord »).", 'Devis / contrats'],
    ['Créneau', "Plage de garde réservable : Matin + trajet école, Midi, Soir, Mercredi (journée, matin, après-midi), Journée (vacances), Samedi matin. Chaque créneau a ses horaires prévus, ses jours, sa période (scolaire ou vacances) et son nombre de places.", 'Créneaux'],
    ['Relevé des heures', "Factures → « Relevé » d'une famille : toutes ses gardes du mois sur une page, avec les heures saisies par les parents déjà reprises. On corrige arrivée / départ / absence / repas, on ajoute une garde oubliée, puis on émet la facture. Plus besoin de photo si les parents saisissent leurs heures.", 'Factures'],
    ['Vacances scolaires', "Pendant les vacances (zone A, liste dans Fermetures), les parents ne voient que « Journée (vacances) » : ils tapent l'heure d'arrivée et de départ, sans case à cocher. Heures vides = pas de garde. La facture compte ces heures, sauf si Amandine pointe les heures réelles.", 'Fermetures'],
    ['Délai de réservation', "Jusqu'à quand les parents peuvent réserver ou annuler eux-mêmes (1 = jusqu'à la veille). Après, ils passent par la garde de dernière minute.", 'Réglages'],
    ['Demandes', "Messages envoyés par de nouvelles familles depuis la page de présentation. Bouton « Créer la famille » pour les ajouter.", 'Demandes'],
    ['Dernière minute (garde de)', "Demande urgente d'une famille après le délai. Elle apparaît en rouge en haut d'Aujourd'hui : Accepter (la réservation est créée) ou Refuser ; la famille est prévenue par email.", "Aujourd'hui (le menu affiche le nombre en attente)"],
    ['Doublon', "Même ligne lue deux fois sur des photos (même enfant, jour et heures) : décochée automatiquement à l'import.", "Importer une feuille d'heures"],
    ['Émettre (les factures)', "Donne un numéro aux factures du mois et fige leur montant. Elles deviennent visibles par les parents. À faire en fin de mois, après vérification du pointage.", 'Factures → « Émettre les factures »'],
    ['Encaissé / Marquer payé', "Enregistre le paiement d'une facture (montant, date, mode : virement, chèque, espèces, CESU).", 'Factures, Impayés'],
    ['Famille', "Les parents (nom, email de connexion, téléphone, adresse de facturation) et leurs enfants.", 'Familles'],
    ['Fermeture', "Jour où Amandine ne garde pas (vacances, férié, congé) : les parents ne peuvent plus réserver ce jour-là.", 'Fermetures'],
    ['Fiche d\'urgence', "Une page avec tous les enfants : allergies, parents, contacts d'urgence, personnes autorisées, numéros utiles. À imprimer et garder près du téléphone.", 'Santé → Fiche d\'urgence'],
    ['Figée (facture)', "Une fois émise, une facture ne change plus même si le pointage est modifié ; on passe alors par « Annuler et refacturer » ou un avoir.", 'Factures'],
    ['Impayés', "Toutes les factures émises non (ou pas entièrement) payées, tous mois confondus, avec relance et encaissement en un clic.", 'Factures → bouton « ⚠ Impayés »'],
    ['Import photo (feuille d\'heures)', "Prendre en photo les pages de l'agenda : l'appli lit prénoms, dates, heures et « pas là », puis on vérifie avant d'importer dans le pointage.", "Aujourd'hui → 📷 Importer une feuille d'heures"],
    ['Invitation', "Email envoyé à une famille avec le bouton vers l'espace parents, ses identifiants (nouveau mot de passe) et le mode d'emploi.", 'Familles → « inviter » / « Inviter toutes ces familles »'],
    ['Jour férié', "Reconnu automatiquement : tarif horaire férié (6 €/h) et sauté par le générateur de jours des contrats.", 'Réglages → Tarifs'],
    ['Mon compte (parents)', "Page où les parents choisissent leur propre mot de passe.", 'Espace parents'],
    ['Nom affiché / Nom légal', "« Nounou Amandine » est montré aux parents ; « Sadala Amandine » (nom légal) reste sur les factures, contrats et attestations.", 'Réglages'],
    ['Numéro de facture', "Numéros qui se suivent sans trou (334, 335…), attribués à l'émission. Le prochain numéro se règle dans Réglages.", 'Réglages'],
    ['Pas là', "Mention de l'agenda lue sur la photo : l'enfant est marqué Absent s'il était réservé.", "Importer une feuille d'heures"],
    ['Petit-déj / Repas / Goûter', "Cochés à chaque pointage (ou prévus par le créneau) ; facturés à l'unité et récapitulés sur la facture.", "Aujourd'hui → colonne Repas"],
    ['Places', "Nombre maximum d'enfants sur un créneau : au-delà, les parents voient « complet » (Amandine peut quand même ajouter).", 'Créneaux'],
    ['Pointage', "Heures réelles d'arrivée et de départ, présence, repas/goûter du jour. Vide = horaires prévus du créneau. C'est le pointage qui est facturé.", "Aujourd'hui"],
    ['Présentation (page)', "Page publique pour faire connaître Amandine (texte, horaires, places disponibles, formulaire de contact).", 'Réglages → Page de présentation'],
    ['Récap', "Totaux de l'année par enfant et par mois (heures, repas, montants), et envoi des attestations fiscales.", 'Récap'],
    ['Registre des médicaments', "Trace de chaque médicament donné (enfant, heure, dose, motif, accord des parents). Les parents le voient dans « Mes enfants ».", 'Santé'],
    ['Relance', "Email de rappel pour une facture non payée.", 'Factures, Impayés → « relancer »'],
    ['Réservation', "Créneau coché par les parents (ou par Amandine) pour un enfant et un jour.", 'Planning, Aujourd\'hui, espace parents'],
    ['Sans repas ni goûter', "Option de la fiche enfant (ex. April, repas fournis par les parents) : rien n'est facturé pour les repas et la facture le précise.", 'Familles → fiche enfant'],
    ['Tarif horaire personnalisé', "Tarif semaine propre à un enfant (ex. April 4,50 €, Mathilde 6 €). Le week-end et les fériés gardent leur tarif.", 'Familles → fiche enfant'],
    ['Tarif spécial repas / goûter', "Repas 5 € et goûter 2 € au lieu de 4 € et 1,50 € (ex. Aaron, Chloé, Louis, Zélie, Élio).", 'Familles → fiche enfant'],
];

const COMMENT = [
    ["Ajouter une nouvelle famille ?", "Familles → « Nouvelle famille » (nom, email, téléphone, adresse) → ajouter ses enfants dans la fiche → « inviter » pour lui envoyer ses accès."],
    ["Corriger les heures d'un enfant ?", "Aujourd'hui → choisir la date → modifier Arrivée / Départ → « Enregistrer le pointage ». Si la facture du mois est déjà émise, faire ensuite « Annuler et refacturer » dans Factures."],
    ["Faire les factures du mois ?", "Début du mois suivant : Factures → vérifier les montants → « Émettre les factures » → « Envoyer les factures par email »."],
    ["Un parent a payé ?", "Factures (ou Impayés) → saisir montant, date, mode → « Marquer payé »."],
    ["Un parent a oublié son mot de passe ?", "Il clique sur « Mot de passe oublié ? » sous la connexion et reçoit un lien par email (valable 1 heure). Sinon : Familles → « réinviter » (nouveau mot de passe par email)."],
    ["Fermer pendant les vacances ?", "Fermetures → du … au … + motif. Les réservations déjà faites ces jours-là peuvent être annulées automatiquement."],
    ["Préparer un contrat de vacances ?", "Devis / contrats → Nouveau → « Ajouter plusieurs jours d'un coup » (du … au …, jours, horaires, repas) → Enregistrer → « Envoyer à la famille pour accord »."],
    ["Changer un tarif ?", "Réglages → Tarifs (pour tout le monde) ou fiche de l'enfant (tarif personnalisé, tarif spécial, sans repas)."],
    ["Donner un médicament ?", "Santé → Registre des médicaments → remplir et Enregistrer (noter qui a autorisé)."],
    ["Retrouver les attestations fiscales ?", "Récap → choisir l'année → « Envoyer toutes les attestations non envoyées » (à faire avant le 31 mars)."],
    ["Sauvegarder les données ?", "Réglages → Sauvegarde → « Télécharger la sauvegarde » (à faire régulièrement, à garder sur le NAS)."],
];

page_debut('Aide');
?>
<style>
  .lex dt { font-weight: 700; margin-top: .9rem; }
  .lex dd { margin: .2rem 0 0; }
  .lex .ou { color: var(--muted); font-size: .85rem; }
  .etapes li { margin: .4rem 0; }
  @media print { #recherche { display: none; } }
</style>
<input id="recherche" type="search" placeholder="🔎 Chercher un mot (ex. avoir, pointage, férié…)" style="width:100%;margin-bottom:1rem">

<div class="card bloc">
  <h2 style="margin-top:0">Le mois type</h2>
  <ol class="etapes">
    <li><b>Avant le mois</b> : les parents cochent leurs créneaux dans leur espace (ou Amandine les coche dans Familles → Réservations).</li>
    <li><b>Chaque jour</b> : page <b>Aujourd'hui</b> → qui vient, allergies, pointage des heures et des repas. Ou bien, en fin de semaine, <b>📷 photo de l'agenda</b> → vérifier → importer.</li>
    <li><b>Imprévus</b> : demandes de dernière minute à accepter/refuser (en rouge sur Aujourd'hui), absents à marquer « Absent ».</li>
    <li><b>Début du mois suivant</b> : <b>Factures</b> → vérifier → <b>Émettre</b> → <b>Envoyer par email</b>.</li>
    <li><b>Paiements</b> : « Marquer payé » à chaque règlement ; <b>Impayés</b> pour relancer.</li>
    <li><b>Chaque année (janvier – mars)</b> : <b>Récap</b> → envoyer les <b>attestations fiscales</b>.</li>
  </ol>
</div>

<h2>Lexique</h2>
<dl class="lex">
  <?php foreach (LEXIQUE as [$mot, $def, $ou]): ?>
    <div class="bloc"><dt><?= h($mot) ?></dt><dd><?= h($def) ?><br><span class="ou">📍 <?= h($ou) ?></span></dd></div>
  <?php endforeach ?>
</dl>

<h2>Comment faire ?</h2>
<?php foreach (COMMENT as [$q, $r]): ?>
  <details class="card bloc"><summary><b><?= h($q) ?></b></summary><p style="margin-bottom:0"><?= h($r) ?></p></details>
<?php endforeach ?>

<script>
document.getElementById('recherche').addEventListener('input', (e) => {
  const t = e.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  document.querySelectorAll('.bloc').forEach(b => {
    const ok = !t || b.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').includes(t);
    b.style.display = ok ? '' : 'none';
    if (ok && t && b.tagName === 'DETAILS') b.open = true;
  });
});
</script>
<?php page_fin();
