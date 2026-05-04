# GPolar 🚴

Interface sombre et rapide pour suivre les voyages à vélo de tes amis.

## Fonctionnalités

- Connexion via email/mot de passe (ou token direct)
- Dashboard : voyages en cours des amis, tes voyages, voyages de tes followees
- Page voyage : timeline des étapes, carte interactive, lightbox photos
- Commentaires avec réponses et réactions, liste des likeurs
- Participants au voyage (co-voyageurs)
- Tri chronologique inversable
- Dark mode

## Stack

- PHP 8.1+ (vanilla, pas de framework)
- [Composer](https://getcomposer.org/)
- [Tailwind CSS](https://tailwindcss.com/) via Play CDN
- [Leaflet.js](https://leafletjs.com/) + tuiles CartoDB Dark

## Installation

```bash
git clone <repo>
cd gpolar
composer install
```

## Lancement

```bash
php -S localhost:8000 -t public/
```

Puis ouvre [http://localhost:8000](http://localhost:8000).

## Connexion

### Email / mot de passe
Entre directement tes identifiants sur la page de login.

### Token manuel
Si la connexion automatique ne fonctionne pas :

1. Connecte-toi sur le site de suivi de voyages
2. Ouvre les DevTools (`F12` / `⌘⌥I`)
3. Onglet **Stockage** (Firefox) ou **Application** (Chrome) → Cookies
4. Copie la valeur du cookie `remember_token`
5. Colle-la dans l'onglet **Token (avancé)** de la page de login

## Structure

```
gpolar/
├── public/
│   ├── index.php        # Dashboard (voyages + feed)
│   ├── trip.php         # Détail d'un voyage
│   ├── login.php        # Authentification
│   ├── logout.php       # Déconnexion
│   └── api/
│       └── comments.php # Proxy API commentaires
├── src/
│   ├── PolarstepsClient.php  # Client HTTP
│   └── helpers.php           # Fonctions partagées, composants HTML
└── composer.json
```

## Déploiement

N'importe quel hébergement PHP 8.1+ avec `mod_rewrite` ou nginx.

Pointe le document root sur `public/`.

Exemple de config nginx :

```nginx
server {
    root /var/www/gpolar/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```
