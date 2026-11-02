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
