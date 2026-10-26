# Envoie le dossier « amandine » sur l'hébergement LWS par FTP.
# Lancement : powershell -ExecutionPolicy Bypass -File envoyer-lws.ps1
# Le dossier « data » (base de données en ligne) n'est jamais écrasé.

$ErrorActionPreference = 'Stop'
$local = Join-Path $PSScriptRoot 'amandine'

$serveur = Read-Host 'Serveur FTP [ftp.acsdeco-sarl.com]'
if (-not $serveur) { $serveur = 'ftp.acsdeco-sarl.com' }
$user = Read-Host 'Identifiant FTP (voir panneau LWS > FTP)'
$pass = Read-Host 'Mot de passe FTP' -AsSecureString
$pass = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($pass))

# Identifiants passés à curl par l'entrée standard (pas visibles dans la ligne de commande)
$auth = "user = `"$($user):$($pass -replace '"','\"')`""

Write-Host "`nDossiers à la racine du FTP :" -ForegroundColor Cyan
$auth | curl.exe -s --ssl -K - --list-only "ftp://$serveur/"
if ($LASTEXITCODE -ne 0) { Write-Host "Connexion impossible (code $LASTEXITCODE). Vérifiez serveur / identifiant / mot de passe." -ForegroundColor Red; exit 1 }

$cible = Read-Host "`nDossier de destination [htdocs/amandine]"
if (-not $cible) { $cible = 'htdocs/amandine' }
$cible = $cible.Trim('/')

$fichiers = Get-ChildItem -Path $local -Recurse -File -Force | Where-Object { $_.FullName -notmatch '\\data\\' }
foreach ($f in $fichiers) {
    $rel = $f.FullName.Substring($local.Length + 1).Replace('\', '/')
    Write-Host "Envoi $rel"
    $auth | curl.exe -s --ssl -K - --ftp-create-dirs -T $f.FullName "ftp://$serveur/$cible/$rel"
    if ($LASTEXITCODE -ne 0) { Write-Host "Échec sur $rel (code $LASTEXITCODE)" -ForegroundColor Red; exit 1 }
}
Write-Host "`nTerminé : $($fichiers.Count) fichiers envoyés dans /$cible" -ForegroundColor Green
Write-Host 'Ouvrez https://www.acsdeco-sarl.com/amandine/ pour créer le compte d''Amandine.'
