=== VDL Agent ===
Contributors: vdl
Tags: api, rest, vdl, links, management
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin WordPress pour la gestion à distance des sites VDL (Vente De Liens) via API REST.

== Description ==

VDL Agent permet de gérer vos sites WordPress VDL à distance via une API REST sécurisée.

**Fonctionnalités principales :**

* Gestion du thème enfant (lecture, écriture, backup, restauration)
* Statistiques VDL (clics, CTR, top liens, top articles)
* Gestion des liens sponsorisés (CRUD, actions bulk)
* Audit SEO (meta tags, score, issues)
* Maintenance (plugins, cache, mises à jour)
* Dashboard admin avec statistiques visuelles

**Sécurité :**

* Authentification par API Key + Confirm Token
* Rate limiting (100 req/min par défaut)
* Protection des fichiers sensibles
* Backup automatique avant modification

== Installation ==

1. Télécharger le plugin et le dézipper dans `/wp-content/plugins/`
2. Activer le plugin via le menu 'Plugins' dans WordPress
3. Aller dans VDL Agent > API Keys pour récupérer vos clés
4. Configurer le MCP Server avec vos clés

== Frequently Asked Questions ==

= Comment récupérer mes clés API ? =

Allez dans VDL Agent > API Keys dans l'admin WordPress. Les clés sont générées automatiquement.

= Quelles opérations nécessitent le Confirm Token ? =

Toutes les opérations d'écriture (POST, PUT, DELETE) nécessitent le Confirm Token en plus de l'API Key.

= Comment utiliser avec Claude Code ? =

Installez le MCP Server `mcp-vdl-agent` et configurez-le avec vos clés API.

== Changelog ==

= 1.0.0 =
* Version initiale
* API REST complète (thème, stats, liens, SEO, maintenance)
* Dashboard admin
* Authentification sécurisée

== Upgrade Notice ==

= 1.0.0 =
Version initiale du plugin VDL Agent.
