-- =============================================================================
--  Test de fumée du schéma — Phase 1
--  Rejoue le scénario de référence (prestation 10 000 FCFA, commission 10 %
--  à la charge du prestataire, mode intégré via la passerelle SANDBOX) et
--  vérifie les protections d'intégrité.
--  Exécution : tests/Database/run_schema_smoke.sh (base jetable job_niger_test)
-- =============================================================================
SET NAMES utf8mb4;
SET time_zone = '+01:00';

DROP TABLE IF EXISTS _results;
CREATE TEMPORARY TABLE _results (test VARCHAR(120), ok TINYINT(1), detail VARCHAR(255));

DROP PROCEDURE IF EXISTS expect_error;
DELIMITER $$
-- Exécute une requête et enregistre OK si elle échoue (contrainte, trigger…)
CREATE PROCEDURE expect_error(IN label VARCHAR(120), IN stmt TEXT)
BEGIN
  DECLARE failed TINYINT DEFAULT 0;
  DECLARE msg VARCHAR(255) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 msg = MESSAGE_TEXT;
    SET failed = 1;
  END;
  SET @s = stmt;
  PREPARE q FROM @s;
  EXECUTE q;
  DEALLOCATE PREPARE q;
  INSERT INTO _results VALUES (label, failed, LEFT(msg, 255));
END$$
DELIMITER ;

-- ---------------------------------------------------------------- Acteurs
INSERT INTO users (uuid, primary_type, first_name, last_name, phone, password_hash, city_id, status, is_demo)
VALUES (UUID(), 'CLIENT', 'Aïcha', 'Demandeur', '+22790000001', '$argon2id$test', 1, 'ACTIVE', 1),
       (UUID(), 'WORKER', 'Moussa', 'Prestataire', '+22790000002', '$argon2id$test', 1, 'ACTIVE', 1),
       (UUID(), 'STAFF',  'Fatima', 'Finance', '+22790000003', '$argon2id$test', 1, 'ACTIVE', 1),
       (UUID(), 'STAFF',  'Ibrahim', 'Finance2', '+22790000004', '$argon2id$test', 1, 'ACTIVE', 1);
SET @client = (SELECT id FROM users WHERE phone = '+22790000001');
SET @worker = (SELECT id FROM users WHERE phone = '+22790000002');
SET @fin1   = (SELECT id FROM users WHERE phone = '+22790000003');
SET @fin2   = (SELECT id FROM users WHERE phone = '+22790000004');

SET @sub = (SELECT id FROM job_subcategories WHERE slug = 'distribution-de-prospectus');
SET @cat = (SELECT category_id FROM job_subcategories WHERE id = @sub);
SET @gw  = (SELECT id FROM payment_gateways WHERE code = 'SANDBOX');
SET @pm  = (SELECT id FROM payment_methods WHERE code = 'SANDBOX');
SET @rule = (SELECT id FROM commission_rules WHERE scope = 'GLOBAL' LIMIT 1);

-- ---------------------------------------------------------------- Annonce (1 poste)
INSERT INTO job_posts (uuid, reference, client_user_id, category_id, subcategory_id, mission_type_id, title, description,
  city_id, start_date, end_date, application_deadline, positions_total, pay_unit, pay_rate, estimated_units,
  estimated_amount, cancellation_terms, status, is_demo)
VALUES (UUID(), 'JN-A-2026-000001', @client, @cat, @sub, 2, 'Distribution de prospectus au Plateau',
  'Distribution de prospectus pour l''ouverture d''une boutique, matériel fourni.', 1,
  '2026-11-02', '2026-11-02', '2026-11-01 18:00:00', 1, 'JOUR', 10000, 1, 10000,
  'Annulation gratuite jusqu''à 24 h avant le début.', 'CANDIDATURES_OUVERTES', 1);
SET @post = LAST_INSERT_ID();

INSERT INTO job_applications (uuid, job_post_id, worker_user_id, message) VALUES (UUID(), @post, @worker, 'Disponible.');
SET @app = LAST_INSERT_ID();

CALL expect_error('Candidature en double refusée',
  CONCAT('INSERT INTO job_applications (uuid, job_post_id, worker_user_id) VALUES (UUID(), ', @post, ', ', @worker, ')'));

UPDATE job_posts SET positions_filled = 1, status = 'ATTRIBUEE' WHERE id = @post;
CALL expect_error('Dépassement du nombre de postes refusé',
  CONCAT('UPDATE job_posts SET positions_filled = 2 WHERE id = ', @post));

-- ---------------------------------------------------------------- Mission + contrat
INSERT INTO missions (uuid, reference, job_post_id, application_id, client_user_id, worker_user_id, status,
  service_amount, payment_mode, scheduled_start_at, scheduled_end_at, is_demo)
VALUES (UUID(), 'JN-M-2026-000001', @post, @app, @client, @worker, 'ATTRIBUEE', 10000, 'INTEGRE',
  '2026-11-02 08:00:00', '2026-11-02 17:00:00', 1);
SET @mission = LAST_INSERT_ID();

CALL expect_error('Contrat incohérent (net ≠ prix − commission) refusé',
  CONCAT('INSERT INTO contracts (uuid, reference, mission_id, title, description, start_at, end_at, pay_unit, rate, units,
    service_amount, client_rate, worker_rate, client_commission, worker_commission, total_commission, client_total,
    worker_net, commission_included_in_price, payment_mode, cancellation_terms, terms_version, snapshot_json, content_hash)
    VALUES (UUID(), ''X'', ', @mission, ', ''t'', ''d'', ''2026-11-02 08:00'', ''2026-11-02 17:00'', ''JOUR'', 10000, 1,
    10000, 0, 10, 0, 1000, 1000, 10000, 9500, 1, ''INTEGRE'', ''c'', ''0.1'', ''{}'', REPEAT(''a'',64))'));

INSERT INTO contracts (uuid, reference, mission_id, title, description, start_at, end_at, pay_unit, rate, units,
  service_amount, commission_rule_id, client_rate, worker_rate, client_commission, worker_commission, total_commission,
  client_total, worker_net, commission_included_in_price, payment_mode, cancellation_terms, terms_version,
  snapshot_json, content_hash, status)
VALUES (UUID(), 'JN-C-2026-000001', @mission, 'Distribution de prospectus', 'Voir annonce JN-A-2026-000001',
  '2026-11-02 08:00:00', '2026-11-02 17:00:00', 'JOUR', 10000, 1,
  10000, @rule, 0, 10, 0, 1000, 1000, 10000, 9000, 1, 'INTEGRE', 'Annulation gratuite jusqu''à 24 h avant.',
  '0.1-brouillon', '{"service_amount":10000}', SHA2('{"service_amount":10000}', 256), 'ACCEPTE');
SET @contract = LAST_INSERT_ID();
SET @hash = (SELECT content_hash FROM contracts WHERE id = @contract);
INSERT INTO contract_acceptances (contract_id, user_id, party, content_hash) VALUES
  (@contract, @client, 'CLIENT', @hash), (@contract, @worker, 'WORKER', @hash);

CALL expect_error('Double acceptation par la même partie refusée',
  CONCAT('INSERT INTO contract_acceptances (contract_id, user_id, party, content_hash) VALUES (', @contract, ', ', @client, ', ''CLIENT'', ''', @hash, ''')'));
CALL expect_error('Modification du prix d''un contrat signé refusée',
  CONCAT('UPDATE contracts SET service_amount = 5000, client_total = 5000, worker_net = 4500, worker_commission = 500, total_commission = 500 WHERE id = ', @contract));

-- ---------------------------------------------------------------- Paiement confirmé par webhook
INSERT INTO payments (uuid, reference, purpose, mission_id, contract_id, payer_user_id, payee_user_id, payment_method_id,
  gateway_id, mode, verification_type, amount, service_amount, client_commission, status, idempotency_key)
VALUES (UUID(), 'JN-P-2026-000001', 'MISSION', @mission, @contract, @client, @worker, @pm, @gw, 'INTEGRE', 'AUTOMATIQUE',
  10000, 10000, 0, 'PENDING', CONCAT('contract:', @contract, ':v1'));
SET @payment = LAST_INSERT_ID();

INSERT INTO payment_webhook_events (gateway_id, event_id, event_type, gateway_reference, payload, signature_valid)
VALUES (@gw, 'evt_0001', 'payment.succeeded', 'SBX-778899', '{"amount":10000,"currency":"XOF"}', 1);

CALL expect_error('Rejeu du même webhook refusé (idempotence)',
  CONCAT('INSERT INTO payment_webhook_events (gateway_id, event_id, payload, signature_valid) VALUES (', @gw, ', ''evt_0001'', ''{}'', 1)'));
CALL expect_error('Second paiement pour la même clé d''idempotence refusé',
  CONCAT('INSERT INTO payments (uuid, reference, mission_id, payer_user_id, payment_method_id, gateway_id, mode, verification_type, amount, idempotency_key)
          VALUES (UUID(), ''JN-P-2026-000002'', ', @mission, ', ', @client, ', ', @pm, ', ', @gw, ', ''INTEGRE'', ''AUTOMATIQUE'', 10000, ''contract:', @contract, ':v1'')'));

UPDATE payments SET status = 'CONFIRMED', confirmed_amount = 10000, gateway_reference = 'SBX-778899',
  confirmed_at = NOW() WHERE id = @payment;

CALL expect_error('Falsification du montant d''un paiement refusée',
  CONCAT('UPDATE payments SET amount = 1 WHERE id = ', @payment));
CALL expect_error('Réécriture du montant confirmé refusée',
  CONCAT('UPDATE payments SET confirmed_amount = 20000 WHERE id = ', @payment));
CALL expect_error('Même référence opérateur sur un 2e paiement refusée',
  CONCAT('INSERT INTO payments (uuid, reference, mission_id, payer_user_id, payment_method_id, gateway_id, mode, verification_type, amount, idempotency_key, gateway_reference)
          VALUES (UUID(), ''JN-P-2026-000003'', ', @mission, ', ', @client, ', ', @pm, ', ', @gw, ', ''INTEGRE'', ''AUTOMATIQUE'', 10000, ''k3'', ''SBX-778899'')'));

INSERT INTO commission_records (mission_id, payment_id, contract_id, rule_id, base_amount, client_rate, worker_rate,
  client_commission, worker_commission, total_commission, status)
VALUES (@mission, @payment, @contract, @rule, 10000, 0, 10, 0, 1000, 1000, 'DIFFEREE');
SET @crec = LAST_INSERT_ID();

CALL expect_error('Seconde commission pour le même paiement refusée',
  CONCAT('INSERT INTO commission_records (mission_id, payment_id, contract_id, base_amount, client_rate, worker_rate, client_commission, worker_commission, total_commission, status)
          VALUES (', @mission, ', ', @payment, ', ', @contract, ', 10000, 0, 10, 0, 1000, 1000, ''DIFFEREE'')'));

-- Écritures : Dr 1010 10 000 / Cr 2010 9 000 (prestataire) / Cr 2030 1 000
SET @a1010 = (SELECT id FROM financial_ledger_accounts WHERE code = '1010');
SET @a2010 = (SELECT id FROM financial_ledger_accounts WHERE code = '2010');
SET @a2011 = (SELECT id FROM financial_ledger_accounts WHERE code = '2011');
SET @a2030 = (SELECT id FROM financial_ledger_accounts WHERE code = '2030');
SET @a4010 = (SELECT id FROM financial_ledger_accounts WHERE code = '4010');

INSERT INTO financial_ledger_journals (uuid, reference, event_type, source_type, source_id, idempotency_key)
VALUES (UUID(), 'JN-J-2026-000001', 'PAYMENT_CONFIRMED', 'payment', @payment, CONCAT('payment:', @payment, ':confirmed'));
SET @j1 = LAST_INSERT_ID();
INSERT INTO financial_ledger_entries (journal_id, account_id, user_id, mission_id, gateway_id, debit, credit) VALUES
  (@j1, @a1010, NULL,    @mission, @gw, 10000, 0),
  (@j1, @a2010, @worker, @mission, NULL, 0, 9000),
  (@j1, @a2030, NULL,    @mission, NULL, 0, 1000);

CALL expect_error('Double comptabilisation du même événement refusée',
  CONCAT('INSERT INTO financial_ledger_journals (uuid, reference, event_type, source_type, source_id, idempotency_key)
          VALUES (UUID(), ''JN-J-2026-000099'', ''PAYMENT_CONFIRMED'', ''payment'', ', @payment, ', ''payment:', @payment, ':confirmed'')'));
CALL expect_error('Ligne débit ET crédit refusée',
  CONCAT('INSERT INTO financial_ledger_entries (journal_id, account_id, debit, credit) VALUES (', @j1, ', ', @a1010, ', 5, 5)'));

INSERT INTO transactions (uuid, reference, type, direction, user_id, mission_id, payment_id, journal_id, amount,
  commission_amount, net_amount, payment_method_id, status, gateway_reference, idempotency_key)
VALUES (UUID(), 'JN-T-2026-000001', 'PAIEMENT_MISSION', 'ENTRANT', @client, @mission, @payment, @j1, 10000, 1000, 9000,
  @pm, 'CONFIRMEE', 'SBX-778899', CONCAT('tx:payment:', @payment));
SET @tx = LAST_INSERT_ID();

-- ---------------------------------------------------------------- Mission terminée → commission acquise
UPDATE missions SET status = 'TERMINEE', completed_at = NOW(), client_confirmed_at = NOW() WHERE id = @mission;
UPDATE commission_records SET status = 'ACQUISE', earned_at = NOW() WHERE id = @crec;
INSERT INTO financial_ledger_journals (uuid, reference, event_type, source_type, source_id, idempotency_key)
VALUES (UUID(), 'JN-J-2026-000002', 'MISSION_COMPLETED', 'mission', @mission, CONCAT('mission:', @mission, ':completed'));
SET @j2 = LAST_INSERT_ID();
INSERT INTO financial_ledger_entries (journal_id, account_id, user_id, mission_id, debit, credit) VALUES
  (@j2, @a2030, NULL,    @mission, 1000, 0),
  (@j2, @a4010, NULL,    @mission, 0, 1000),
  (@j2, @a2010, @worker, @mission, 9000, 0),
  (@j2, @a2011, @worker, @mission, 0, 9000);

-- ---------------------------------------------------------------- Versement (4 yeux)
CALL expect_error('Versement approuvé par son préparateur refusé',
  CONCAT('INSERT INTO payouts (uuid, reference, worker_user_id, amount, payment_method_id, gateway_id, idempotency_key, requested_by) VALUES
          (UUID(), ''JN-V-2026-000009'', ', @worker, ', 9000, ', @pm, ', ', @gw, ', ''po:test'', ', @fin1, ')'));
-- (l'INSERT ci-dessus réussit ; c'est la mise à jour suivante qui doit échouer)
DELETE FROM _results WHERE test = 'Versement approuvé par son préparateur refusé';
SET @po_bad = (SELECT id FROM payouts WHERE reference = 'JN-V-2026-000009');
CALL expect_error('Versement approuvé par son préparateur refusé',
  CONCAT('UPDATE payouts SET approved_by = ', @fin1, ' WHERE id = ', @po_bad));
UPDATE payouts SET status = 'ANNULE' WHERE id = @po_bad;

INSERT INTO payouts (uuid, reference, worker_user_id, amount, payment_method_id, gateway_id, status, requested_by,
  approved_by, approved_at, paid_at, gateway_reference, idempotency_key)
VALUES (UUID(), 'JN-V-2026-000001', @worker, 9000, @pm, @gw, 'PAYE', @fin1, @fin2, NOW(), NOW(), 'SBX-PO-1', 'po:worker:1');
SET @po = LAST_INSERT_ID();
INSERT INTO payout_items (payout_id, mission_id, payment_id, amount) VALUES (@po, @mission, @payment, 9000);
INSERT INTO financial_ledger_journals (uuid, reference, event_type, source_type, source_id, idempotency_key)
VALUES (UUID(), 'JN-J-2026-000003', 'PAYOUT_PAID', 'payout', @po, CONCAT('payout:', @po, ':paid'));
SET @j3 = LAST_INSERT_ID();
INSERT INTO financial_ledger_entries (journal_id, account_id, user_id, gateway_id, debit, credit) VALUES
  (@j3, @a2011, @worker, NULL, 9000, 0),
  (@j3, @a1010, NULL,    @gw,  0, 9000);

-- ---------------------------------------------------------------- Protections d'immuabilité
CALL expect_error('Suppression d''une écriture comptable refusée',
  CONCAT('DELETE FROM financial_ledger_entries WHERE journal_id = ', @j1));
CALL expect_error('Modification d''une écriture comptable refusée',
  CONCAT('UPDATE financial_ledger_entries SET credit = 1 WHERE journal_id = ', @j1, ' AND credit > 0'));
CALL expect_error('Suppression d''une transaction refusée',
  CONCAT('DELETE FROM transactions WHERE id = ', @tx));
CALL expect_error('Modification du montant d''une transaction refusée',
  CONCAT('UPDATE transactions SET amount = 1 WHERE id = ', @tx));
CALL expect_error('Suppression d''un paiement refusée',
  CONCAT('DELETE FROM payments WHERE id = ', @payment));
CALL expect_error('Modification d''une commission refusée',
  CONCAT('UPDATE commission_records SET worker_commission = 0, total_commission = 0 WHERE id = ', @crec));
INSERT INTO admin_audit_logs (actor_user_id, action, entity_type, entity_id, reason) VALUES (@fin1, 'test', 'payment', @payment, 'test');
CALL expect_error('Suppression du journal d''audit refusée', 'DELETE FROM admin_audit_logs');

-- ---------------------------------------------------------------- Vérification des soldes
INSERT INTO _results
SELECT 'Revenu acquis plateforme (4010) = 1 000', balance = 1000, CONCAT('solde=', balance) FROM v_ledger_account_balances WHERE code = '4010';
INSERT INTO _results
SELECT 'Commission différée soldée (2030) = 0', balance = 0, CONCAT('solde=', balance) FROM v_ledger_account_balances WHERE code = '2030';
INSERT INTO _results
SELECT 'Dû prestataire disponible soldé après versement (2011) = 0', balance = 0, CONCAT('solde=', balance) FROM v_ledger_account_balances WHERE code = '2011';
INSERT INTO _results
SELECT 'Fonds restant chez le PSP (1010) = commission 1 000', balance = 1000, CONCAT('solde=', balance) FROM v_ledger_account_balances WHERE code = '1010';
INSERT INTO _results
SELECT 'Aucun journal déséquilibré', COUNT(*) = 0, CONCAT('n=', COUNT(*)) FROM v_ledger_unbalanced_journals;
INSERT INTO _results
SELECT 'Grand livre global équilibré', SUM(debit) = SUM(credit), CONCAT(SUM(debit), ' / ', SUM(credit)) FROM financial_ledger_entries;
INSERT INTO _results
SELECT 'Catalogue : 21 domaines', COUNT(*) = 21, CONCAT('n=', COUNT(*)) FROM job_categories;
INSERT INTO _results
SELECT 'Catalogue : > 200 métiers', COUNT(*) > 200, CONCAT('n=', COUNT(*)) FROM job_subcategories;
INSERT INTO _results
SELECT 'Aucune passerelle intégrée active en production', COUNT(*) = 0, CONCAT('n=', COUNT(*))
FROM payment_gateways WHERE holds_funds = 1 AND is_active = 1 AND environment = 'PRODUCTION';

SELECT IF(ok = 1, 'OK  ', 'ECHEC') AS r, test, detail FROM _results;
SELECT CONCAT(SUM(ok), '/', COUNT(*), ' tests réussis') AS bilan, IF(SUM(ok) = COUNT(*), 'SUCCES', 'ECHEC') AS statut FROM _results;
