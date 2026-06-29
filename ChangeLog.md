# ChangeLog PowrSync

## 1.0.4 (29/06/2026)
- Corrige l'authentification depuis les travaux planifiés Dolibarr : décodage du mot de passe stocké avec `dol_encode()`, purge des cookies expirés et retry conservant le cookie de session intermédiaire après un HTTP 302.
- Corrige le chargement de la classe native `ProductFournisseur` via `fourn/class/fournisseur.product.class.php` pour éviter le fatal sur les crons.
- Corrige la requête de progression de `sync.php` en lisant la référence produit via `fk_product` et la table native `product`, sans dépendre de la colonne inexistante `l.ref_product`.
- Ajoute des logs cron explicites avec entité, login masqué et révision de code pour diagnostiquer les exécutions planifiées.

## 1.0.3 (26/06/2026)
- Applique la numérotation native des permissions avec `{ID module * 100} + $r` et migre les attributions existantes. / Apply native permission numbering with `{module ID * 100} + $r` and migrate existing assignments.

## 1.0.2 (02/04/2026)
- Affine la méthode de mise à jour des produits / Refine the product update method
- Passe la demande de confirmation sous forme de modal / Pass the confirmation request as a modal
- Ajoute une barre de progression pour suivre l'avancement de la mise à jour pour éviter les erreurs `500 Too long request` / Add a progress bar to track the update progress and prevent 500 errors (Request takes too long)

## 1.0.1 (01/04/2026)
- Corrige une erreur pouvant provoquer l'arrêt prématuré de la tâche planifiée / Corrects an error that could cause the planned task to stop prematurely.

## 1.0 (31/03/2026)
- Version initiale. / Initial release.
