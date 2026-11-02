# MARWA IA — assistant intelligent de POS Marwa

MARWA IA répond en français, dans une conversation, aux questions de gestion et d'utilisation du
logiciel. Il fonctionne **sur le serveur de la boutique, sans Internet** : aucune question ni
donnée ne sort.

## Installation
Système & maintenance → Modules complémentaires → **MARWA IA → Installer**. Le menu
**Assistant → MARWA IA** apparaît, ainsi qu'une bulle « MARWA IA » en bas à gauche de chaque page
(pas sur l'écran de caisse). Droits : « Utiliser l'assistant » (gérant, responsable, caissier,
stock, achats, comptable, pharmacien, auditeur) et « Régler MARWA IA » (gérant, responsable).

## Ce qu'il sait faire
**Rapports à la demande** — aperçu dans la conversation, puis **Voir le PDF**, **Télécharger le
PDF** ou **Excel (CSV)** :
- relevé d'un **client** ou d'un **fournisseur** (« rapport du client Moussa en PDF ») ;
- **ventes / transactions** d'une période, d'un vendeur, d'un client, d'un mode de paiement
  (« toutes les transactions du mois en PDF », « ventes payées en mobile money cette semaine ») ;
- un **article** : ventes, marge, stock, jours de stock restants (« stock du riz ») ;
- meilleures ventes, articles qui ne se vendent pas, état du stock, articles sous le seuil,
  péremptions ;
- créances clients et dettes fournisseurs, meilleurs clients ;
- bénéfice (compte de résultat simplifié), dépenses, écarts de caisse ;
- ventes par vendeur, par heure, par mode de paiement, par catégorie ;
- détail d'une vente par son numéro (« vente V2026-000150 »).

**Analyses et conseils** :
- **bilan** (« comment va ma boutique ? ») : chiffres, comparaison avec la période précédente,
  article vedette, heure de pointe, alertes (ruptures, retards de paiement, écarts de caisse,
  lots périmés) et conseils ;
- **réapprovisionnement** (« que dois-je commander ? ») : rythme de vente des 30 derniers jours,
  jours de stock restants, quantité conseillée pour 14 jours, budget ;
- **prévision du mois** : projection de fin de mois et effort quotidien nécessaire.

**Aide sur tout le logiciel** : questions fréquentes rédigées et recherche dans la documentation
de POS Marwa et de tous ses modules (« comment vendre au carton ? », « que faire en cas de
coupure d'Internet ? »), avec les liens vers les bonnes pages.

**Conseils et idées de gestion** (1.1) : « comment augmenter mes ventes ? », « idées pour
fidéliser mes clients », « comment réduire les pertes et les vols ? », « conseils pour mon stock »,
« comment fixer mes prix ? », « conseils pour ma trésorerie », « comment motiver mon personnel ? »,
et selon les modules : restaurant, pharmacie, hôtel, établissement, pressing. Chaque réponse donne
d'abord **ce que MARWA IA voit dans vos chiffres** (30 derniers jours, locomotives, heure calme,
articles dormants, ruptures, créances, manques de caisse…, selon les droits du rôle), puis des
idées concrètes et les écrans pour passer à l'action.

**Formation** (1.1) : « ma formation » affiche le programme adapté au rôle et aux modules actifs
(prise en main, caisse, articles et cartons, achats, stock et inventaire, clients et crédit,
clôture, rapports, dépenses, pressing, hôtel, établissement). Chaque leçon explique pas à pas avec
les liens vers les écrans ; le **quiz** de 3 questions (réponse A, B, C ou avec ses mots) est
corrigé et expliqué. La progression est enregistrée par utilisateur.

**Contrôle de configuration** (1.1) : « vérifie ma configuration » / « qu'ai-je oublié ? » —
identité de l'entreprise (nom, téléphone, adresse, logo, NIF), moyens de paiement et opérateurs
mobile money, imprimantes, dernière sauvegarde, licence, compte admin, caissiers sans caisse,
articles sans prix d'achat ou vendus à perte, codes-barres, stocks minimum, stocks négatifs,
inventaires non validés, lots périmés, caisses non clôturées, clients au-delà du plafond,
catégories de dépenses. Score, priorités (à faire tout de suite / important / conseillé) et lien
« Corriger » pour chaque point. Seuls les points que le rôle peut corriger sont affichés.

**État des modules** (1.1) : « état du pressing », « taux d'occupation de l'hôtel », « comment va
la clinique ? », « impayés de l'école », « solde des banques ».

**Calculs** (1.1) : prix de vente selon la marge (sur le coût et sur le prix de vente), marge /
taux de marge / taux de marque / coefficient, HT ↔ TTC (taux des paramètres ou « à 5 % »),
remise, seuil de rentabilité (taux de marge de la boutique si non précisé).

**Lexique** (1.1) : « c'est quoi le CMP ? », « que veut dire FEFO ? » — 27 notions de gestion.

**Sagesse africaine** (1.1) : un proverbe ou une citation (Amadou Hampâté Bâ, Nelson Mandela,
proverbes haoussa, éthiopien et africains) accompagne le point du jour, les conseils et la fin des
quiz ; « un proverbe », « motive-moi ». Quand l'origine précise d'un proverbe varie selon les
sources, il est présenté comme « proverbe africain ».

**Point du jour** : « bonjour » donne la citation du jour, les ventes du jour, les ruptures,
l'état des modules et le nombre de points de configuration à revoir. Le bouton **Écouter** lit
la réponse avec la voix française de l'ordinateur (selon les voix installées).

**Coach marketing** (1.2) : MARWA IA adapte ses réponses à l'activité déclarée dans les
Paramètres (mode de fonctionnement et type d'activité) : alimentation, boutique, quincaillerie,
boissons, cosmétique, informatique, papeterie, pièces détachées, restaurant, pharmacie, clinique,
pressing, hôtel, établissement scolaire.
- Sujets : « comment marche le marketing ? », « quelles méthodes de nos jours ? », « comment être
  visible sur les réseaux sociaux ? », WhatsApp Business, Facebook, TikTok, Instagram, Google Maps,
  « comment lancer une campagne publicitaire ? », « que publier ? », promotions (Tabaski, rentrée…),
  fidélité et bouche-à-oreille, flyers / enseigne / radio, influenceurs, « comment mesurer si ma pub
  marche ? ». Chaque réponse ajoute « Dans votre activité » et ce que vos chiffres montrent
  (articles vedettes, heure calme, articles qui dorment, clients avec téléphone).
- « **Mon plan marketing en PDF** » : guide personnalisé d'environ 9 pages (adresse `/ia/marketing`)
  — situation, méthodes, réseaux, publicité et budget indicatif, plan sur 30 jours, calendrier,
  textes prêts à publier, slogans, tableau de suivi, lexique.
- « **Écris un post** pour [article] », « écris un statut WhatsApp », « script TikTok », « propose-moi
  un slogan » : textes avec le nom de la boutique, le vrai prix (et la promotion en cours), le
  téléphone et l'adresse des Paramètres ; bouton **Copier** ; « un autre texte ».
- Activités réglementées : pharmacie et clinique (pas de promotion de médicaments, pas de
  promesse de guérison, secret médical), établissement scolaire (photos d'élèves avec accord
  écrit des parents), boissons (publicité de l'alcool encadrée). Ces rappels ne remplacent pas un
  avis juridique.

**Rester dans son domaine** (1.2) : les questions sans rapport avec la gestion de l'activité
(politique, religion, sport, jeux d'argent…) reçoivent un recadrage poli ; MARWA IA ne donne
jamais d'avis médical personnel et renvoie vers un médecin ou un pharmacien.

## Comment il comprend
- Périodes : aujourd'hui, hier, cette semaine, la semaine dernière, ce mois, le mois dernier,
  en mars (2025), cette année, les 30 derniers jours, du 1er au 15 octobre, du 10/10 au 20/10,
  depuis le 1er octobre, depuis le début.
- Noms : clients, fournisseurs, articles et vendeurs de la base, même avec une faute de frappe
  (« boutik amina »), ou par numéro de téléphone.
- Suite de la conversation : « et en PDF ? », « et le mois dernier ? », « et en Excel ? ».
- Un client introuvable : MARWA IA le dit et propose les noms proches.

## Sécurité et limites
- Chaque rapport vérifie les droits de l'utilisateur (un caissier n'obtient ni le bénéfice ni
  les écarts de caisse ; sans le droit « toutes les ventes », il ne voit que les siennes). Les
  rapports téléchargés sont journalisés.
- Les questions sont gardées 90 jours (« Vos dernières questions », bouton Effacer).
- MARWA IA n'est pas un modèle de langage géant : il comprend les demandes de gestion et la
  documentation de POS Marwa. Une question hors de ce domaine reçoit une réponse honnête
  (« je n'ai pas trouvé ») avec des exemples. Vérifiez les chiffres importants dans les écrans
  d'origine avant une décision.

## Modèle de langage local (facultatif)
Assistant → Réglages de MARWA IA : adresse d'un serveur **Ollama** installé sur le PC ou le
réseau local (ex. `http://127.0.0.1:11434`) et nom du modèle (ex. `llama3.2`, `qwen2.5`). Il
rédige alors les réponses d'aide à partir des extraits de la documentation. Les adresses
Internet sont refusées. Les chiffres et rapports restent toujours calculés par POS Marwa. Un PC
modeste peut être lent (plusieurs secondes par réponse) : sans ce réglage, le moteur intégré
répond instantanément.
