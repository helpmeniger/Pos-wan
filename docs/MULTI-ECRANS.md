# Configuration multi-écrans

VERIFIA distingue :

- les **sessions logiques** (une URL surveillée, un navigateur, un profil, des paramètres) ;
- les **fenêtres** dans lesquelles on les regarde ;
- les **écrans physiques** du poste.

Plusieurs sessions peuvent être supervisées depuis une seule fenêtre (page *Sessions* en grille, liste ou onglets,
avec un aperçu rafraîchi toutes les 10 s), tandis que chaque navigateur piloté reste une vraie fenêtre Windows
que l'on peut placer où l'on veut. Les sites ne sont **jamais** intégrés dans des iframes (Facebook et la plupart
des réseaux l'interdisent et le défilement continu n'y fonctionnerait pas).

## Mode A — supervision centralisée (un seul écran)

1. Ouvrir *Sessions* en vue **Grille** (2, 3, 4 ou 6 colonnes, ou « Auto » selon la largeur).
2. Les navigateurs pilotés s'ouvrent par-dessus : les réduire ; la surveillance continue en arrière-plan.
3. Le *Centre d'alertes* et le compteur « non lues » de la barre de navigation signalent les nouvelles alertes.
4. Bouton *Afficher la fenêtre* d'une session : ramène son navigateur au premier plan quand on veut intervenir.

## Mode B — poste multi-écrans

Exemple avec trois écrans 1920×1080 côte à côte (principal au milieu) :

| Écran | Origine Windows (X, Y) | Contenu conseillé |
|---|---|---|
| Gauche | (−1920, 0) | navigateurs surveillés 1 et 2 |
| Centre | (0, 0) | tableau de bord + travail courant |
| Droite | (1920, 0) | centre d'alertes ; rapports détaillés |

### Placer les navigateurs surveillés

*Sessions* › *Paramètres* d'une session › **Position de la fenêtre** :

| Session | X | Y | Largeur | Hauteur |
|---|---|---|---|---|
| Facebook | −1920 | 0 | 960 | 1040 |
| Site d'actualité | −960 | 0 | 960 | 1040 |

Les coordonnées sont celles du bureau Windows étendu (Paramètres › Système › Affichage montre la disposition).
La position est appliquée à l'ouverture du navigateur : cliquer sur *Redémarrer* après l'avoir modifiée.
Si un écran est débranché, Windows ramène la fenêtre sur un écran visible.

### Placer les vues de VERIFIA

Page **Multi-écrans** :

1. *Détecter mes écrans* : dans Chrome ou Edge récents, le navigateur demande l'autorisation « gestion des fenêtres »
   et liste les écrans réels (API Window Management).
2. Choisir l'écran de chaque vue (tableau de bord, centre d'alertes, grille des sessions). Le choix est mémorisé.
3. *Ouvrir toutes les vues* (autoriser les fenêtres surgissantes pour `localhost`).

Sans cette API (Firefox, ancien navigateur), les vues s'ouvrent sur l'écran courant : il suffit de les déplacer une fois.

Les fiches détaillées s'ouvrent dans une fenêtre séparée (bouton *Fiche détaillée* du centre d'alertes), réutilisée par alerte.

## Garder les alertes visibles sans voler le focus

- Le centre d'alertes ne prend jamais le focus : nouvelles alertes en surbrillance, notification discrète
  dans la page, compteur dans le titre de l'onglet.
- *Son* (facultatif) : bip court, plus grave et plus long pour une alerte critique.
- *Notifications Windows* (facultatif) : notification système quand la fenêtre est en arrière-plan ;
  cliquer dessus ouvre la fiche.
- « Toujours au premier plan » : une page web ne peut pas l'imposer. Sous Windows 10/11, **Microsoft PowerToys ›
  Toujours visible** (Win + Ctrl + T) épingle la fenêtre du centre d'alertes ; c'est réversible à tout moment.

## Ressources

Chaque navigateur consomme de la mémoire et du processeur. La limite effective est le minimum entre
`max_concurrent_sessions` et ce que permet la mémoire libre (`memory_reserve_mb`, `session_memory_mb`).
Le tableau de bord affiche la mémoire libre, le nombre de navigateurs ouverts et la limite calculée.
Ordre de grandeur indicatif, à vérifier sur votre poste avec le tableau de bord : 2–3 sessions sur 8 Go, 4–6 sur 16 Go ; Facebook est nettement plus gourmand qu'un site d'actualité classique.
