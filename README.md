# Service de modération des commentaires

API Symfony 8 / PHP 8.5 structurée en DDD-CQRS, documentée par OpenAPI sur `/doc` en environnement `dev`.
`POST /comments` accuse réception en `202`; un auteur banni est rejeté immédiatement, sans modération.
`GET /comments/{id}` expose le détail et `GET /comments` filtre par éditeur/statut avec pagination.
Installation : `composer install && php bin/console doctrine:migrations:migrate --no-interaction`; contrôle : `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phparkitect check`.
Traitement : `php bin/console messenger:consume async -vv` (transport Doctrine, retries et file d’échec configurés).

## Choix et limites

La modération est asynchrone pour isoler latence et indisponibilité du LLM, permettre les retries et garder l’accusé rapide.
Le port `ModerationService` permet un fournisseur externe; faute de clé, le livrable utilise un stub déterministe, explicite et testable.
Les règles couvrent menace, haine/discrimination, harcèlement, diffamation et contenu illicite, d’après la [LCEN, article 6](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044067469/2024-01-26) et [Service-Public](https://www.service-public.fr/particuliers/vosdroits/F32239); elles ne remplacent pas une qualification juridique.
Les extensions optionnelles (workflow manuel, API de bannissement, notifications tierces, webhook Facebook) sont volontairement laissées hors périmètre plutôt qu’incomplètes.
**Usage de l’IA —** Codex a servi à analyser le squelette, proposer l’architecture et générer code/tests; j’ai conservé les patterns conformes au brief, corrigé les types et versions, et rejeté les options hors périmètre après vérification automatisée.
