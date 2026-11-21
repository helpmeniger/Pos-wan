-- Module Modèles de documents : modèles nommés (mise en page + options de présentation).
CREATE TABLE IF NOT EXISTS doc_modeles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(80) NOT NULL,
  type ENUM('ticket','a4') NOT NULL,
  base VARCHAR(20) NOT NULL,
  options TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_doc_modeles_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modèles prêts à l'emploi (aucun n'est affecté : les documents ne changent qu'après votre choix).
INSERT IGNORE INTO doc_modeles (nom, type, base, options) VALUES
('Ticket encadré à colonnes', 'ticket', 'encadre', '{}'),
('Ticket de vente à crédit (solde créance)', 'ticket', 'encadre', '{"solde_client":"1","signature":"1","conditions":"Je reconnais devoir la somme ci-dessus."}'),
('Ticket tableau à colonnes', 'ticket', 'colonnes', '{}'),
('Ticket économique', 'ticket', 'economique', '{}'),
('Ticket grands caractères', 'ticket', 'grand', '{}'),
('Facture encadrée', 'a4', 'encadre', '{"conditions":"Les marchandises vendues ne sont ni reprises ni échangées sans le présent document."}'),
('Facture à bandeau latéral', 'a4', 'lateral', '{}'),
('Facture demi-page A5', 'a4', 'a5', '{}');
