# Validation de la préparation 1.0.5

Contrôles locaux du 2026-10-11, PHP 8.5.7. Ces simulations sont distinctes de la recette distante décrite ci-dessous.

- Syntaxe PHP de tous les fichiers du module et `git diff --check`.
- `php test/schema_settings_test.php <dolibarr>/htdocs` : migration répétée, conservation des journaux inconnus à entity 0, séparation des entités, initialisation conservant zéro et chaîne vide. Adaptateur SQLite en mémoire, requêtes SHOW adaptées ; ce n’est pas un essai MySQL/MariaDB.
- `php test/log_scope_test.php <dolibarr>/htdocs` : exécution de `PowrSync::getLogs()` avec un produit partagé, refus d’un administrateur sans permission, refus d’un schéma ancien, refus du cron sans droit. Configuration et utilisateur simulés.
- Lecture locale des placements des formulaires et de la pagination ; les contrôles navigateur réels sont consignés ci-dessous.
- PHPStan : non exécuté, aucun outil/configuration identifié dans le module.

## Recette réelle sur develop — 2026-10-11

Instance `develop.lesmetiersdubatiment.fr`, Dolibarr `25.0.0-alpha`, PHP `8.3.35`, Multicompany `24.0.2`, compte administrateur connecté. SHA du core exécuté non relevé ; aucune assimilation à une future v25 finale ni aux révisions des simulations.

Déploiement via le Git Control cPanel du checkout directement servi : branche `fix/agents-compliance`, commit fonctionnel testé `e9178a1ecde67f2deb6e6293348f924cb929e78a`, version `1.0.5`. Le checkout précédent utilisait `1.0.4-fix-cron-task`, commit `6daca30824fef2f3189fbe5d199c4cad8969cfbb`, version 1.0.4. Aucun core modifié, aucune release ni fusion de PR.

| Scénario exécuté | Résultat observé |
|---|---|
| Découverte et vignette v25 | Image `/custom/powrsync/img/powrconnect.png` chargée, largeur naturelle non nulle |
| Activation, désactivation puis réactivation en TEST 1 (1) | Succès visible sans erreur fatale ni SQL dans l'interface ; les 7 champs de réglages relevés sont identiques avant/après, aucun secret reproduit dans les preuves |
| Réglages, Compatibilité et À propos | Pages accessibles, PHP/Dolibarr détectés, version 1.0.5 affichée |
| Pagination native | `#limit` dans un formulaire, aucun champ caché `limit` concurrent ; passage 20→50 puis retour 20 avec les trois tarifs initiaux conservés |
| Historique vide | Défaut reproduit : commentaire d'absence de données manquant ; après correction, ligne native « Aucun enregistrement trouvé », colspan 9 |
| Donnée dédiée | Produit id 65, référence `RECETTE-POWRSYNC-20261011`, hors vente, en achat, TEST 1 ; tarif fournisseur Powr Connect, référence `RECETTE-AR0753-20261011`, prix initial 1,23 € HT, URL publique du socle SMA |
| Synchronisation individuelle | Action `syncone` exécutée uniquement sur ce tarif ; connexion fournisseur refusée HTTP 401, prix resté à 1,23 € ; les trois tarifs préexistants n'ont pas été synchronisés |
| Journal d'échec | Visible en TEST 1 avec référence du produit de recette et message HTTP 401 |
| Isolation entre entités | En TEST 2 (2), accès refusé lorsque module inactif ; après activation temporaire, historique vide (0), aucune trace de la recette TEST 1 |

État restitué : TEST 1 comme entité courante ; PowrSync désactivé en TEST 1 et TEST 2 comme à l'arrivée ; EklorSync non activé et non modifié ; réglages contrôlés conservés. Les droits administrateur automatiquement attribués par l'activation native sont conservés, leur état initial exhaustif n'ayant pas été relevé. Le produit et son tarif de recette restent conservés à 1,23 € HT. Aucune synchronisation globale ni exécution de cron sur les produits préexistants.

Blocages et limites : le refus HTTP 401 empêche de valider la récupération et l'écriture d'un prix réel. La collision préexistante d'identifiant 450013 entre PowrSync et EklorSync reste présente : l'activation ciblée de PowrSync fonctionne, mais ceci ne prouve pas l'absence de collision des permissions et ne satisfait pas le critère d'unicité AGENTS.md. EklorSync est exclu des modifications.

Restent non exécutés : succès de synchronisation fournisseur, cron global (limité par l'autorisation aux seules données de recette), comptes standard/externes avec droits partiels, restauration exhaustive des extrafields personnalisés, migration MySQL/MariaDB avec échecs injectés, PHP 8.0 et instances réelles v20 à v24. La cible déclarée demeure v20–v25 ; cette recette ciblée ne certifie pas tous les parcours. PHPStan reste non exécuté faute d'outillage.
