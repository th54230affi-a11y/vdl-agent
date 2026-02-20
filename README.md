# VDL Agent - WordPress Plugin

Plugin WordPress pour la gestion a distance des sites VDL (Vente De Liens) via API REST.

## Fonctionnalites

- **Gestion des liens** : CRUD complet pour les liens sponsorises (ajout, modification, suppression, actions bulk)
- **Statistiques** : Clics, CTR, top liens, top articles, overview
- **Audit SEO** : Meta tags, score SEO, issues on-page, schema.org
- **Gestion du theme** : Lecture/ecriture des fichiers theme enfant, backup, restauration
- **Maintenance** : Gestion des plugins, purge cache, mises a jour
- **Dashboard admin** : Interface visuelle dans l'admin WordPress

## Installation

### Depuis GitHub (ZIP)
1. Telecharger le ZIP depuis **Releases** (ou Code > Download ZIP)
2. Dans WordPress : **Extensions > Ajouter > Televerser une extension**
3. Selectionner le fichier ZIP
4. Activer le plugin

### Depuis les fichiers
1. Copier le dossier `vdl-agent/` dans `/wp-content/plugins/`
2. Activer le plugin via **Extensions** dans WordPress

## Configuration

1. Aller dans **VDL Agent > API Keys** dans l'admin WordPress
2. Les cles sont generees automatiquement :
   - **API Key** : pour l'authentification de toutes les requetes
   - **Confirm Token** : requis pour les operations d'ecriture (POST, PUT, DELETE)

## API REST

Base URL : `https://votre-site.fr/wp-json/vdl/v1`

### Authentification
- Header `Authorization: Bearer <API_KEY>` pour toutes les requetes
- Header `X-VDL-Confirm: <CONFIRM_TOKEN>` pour les operations d'ecriture

### Endpoints

| Methode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/health` | Health check du plugin |
| GET | `/stats/overview` | Vue d'ensemble des statistiques |
| GET | `/links` | Lister les liens |
| POST | `/links` | Ajouter un lien |
| PUT | `/links/{id}` | Modifier un lien |
| DELETE | `/links/{id}` | Supprimer un lien |
| GET | `/seo/status` | Score SEO global |
| GET | `/seo/audit/{post_id}` | Audit SEO d'une page |
| GET | `/theme/files` | Lister les fichiers du theme |
| GET | `/theme/file?path=...` | Lire un fichier theme |
| POST | `/theme/file` | Ecrire un fichier theme |
| GET | `/posts` | Lister les posts |
| GET | `/posts/{id}` | Lire un post |
| PUT | `/posts/{id}` | Modifier un post |
| DELETE | `/posts/{id}` | Supprimer un post |
| GET | `/plugins` | Lister les plugins |
| POST | `/cache/purge` | Purger le cache |

### Rate Limiting
100 requetes/minute par defaut (configurable).

## Structure du plugin

```
vdl-agent/
├── vdl-agent.php              # Fichier principal du plugin
├── readme.txt                 # readme WordPress standard
├── admin/
│   ├── class-vdl-admin.php    # Interface admin WP
│   ├── css/admin.css          # Styles admin
│   └── views/
│       ├── dashboard.php      # Page dashboard
│       └── settings.php       # Page settings/API keys
├── assets/
│   └── js/admin.js            # Scripts admin
└── includes/
    ├── class-vdl-api.php      # Routes REST API
    ├── class-vdl-auth.php     # Authentification API key
    ├── class-vdl-content.php  # Gestion des posts
    ├── class-vdl-links.php    # Gestion des liens (CRUD)
    ├── class-vdl-maintenance.php  # Maintenance WP
    ├── class-vdl-seo.php      # Audit SEO
    ├── class-vdl-stats.php    # Statistiques
    └── class-vdl-theme.php    # Gestion du theme
```

## Compatibilite

- WordPress 5.8+
- PHP 7.4+
- Teste jusqu'a WordPress 6.4

## Securite

- Authentification par API Key + Confirm Token
- Rate limiting configurable
- Protection des fichiers sensibles (wp-config.php, .htaccess)
- Backup automatique avant modification de fichiers theme
- Validation et sanitization de toutes les entrees

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html
