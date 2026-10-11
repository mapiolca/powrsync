# Validation de la préparation 1.0.6

Contrôles du 2026-10-11, PHP 8.5.7. Aucun déploiement ni appel POwR Connect effectué.

- Syntaxe PHP de tous les fichiers du module et `git diff --check`.
- `php test/schema_settings_test.php <dolibarr>/htdocs` : migration répétée, conservation des journaux inconnus à entity 0, séparation des entités, initialisation conservant zéro et chaîne vide. Adaptateur SQLite en mémoire, requêtes SHOW adaptées ; ce n’est pas un essai MySQL/MariaDB.
- `php test/log_scope_test.php <dolibarr>/htdocs` : exécution de `PowrSync::getLogs()` avec un produit partagé, refus d’un administrateur sans permission, refus d’un schéma ancien, refus du cron sans droit. Configuration et utilisateur simulés.
- Lecture des placements des formulaires et de la pagination ; contrôle DOM/navigateur non exécuté.
- PHPStan : non exécuté, aucun outil/configuration identifié dans le module.

La cible déclarée reste Dolibarr v20–v25 et PHP 8.0+. Aucun parcours complet n’a été validé sur une instance de ces versions. Les tests SQLite ne valident pas les contrats MySQL ni le cycle complet d’activation/désactivation. Les permissions du compte cron, les partages Multicompany, les menus persistés et la pagination restent à vérifier sur une instance de test servant cette branche.
