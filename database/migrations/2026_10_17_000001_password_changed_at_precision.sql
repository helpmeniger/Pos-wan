-- Précision à la milliseconde de password_changed_at : sert d'empreinte de session.
-- Deux changements de mot de passe dans la même seconde invalident bien les sessions ouvertes.
ALTER TABLE users MODIFY password_changed_at DATETIME(3) NULL;
