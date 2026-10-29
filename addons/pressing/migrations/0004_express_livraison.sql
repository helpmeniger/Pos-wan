-- Pressing 1.4 : pourcentage express choisi au dépôt, livraison à domicile (adresse, frais)
ALTER TABLE pressing_orders ADD COLUMN express_pct DECIMAL(6,2) NULL AFTER express_fee;
ALTER TABLE pressing_orders ADD COLUMN delivery TINYINT(1) NOT NULL DEFAULT 0 AFTER express_pct;
ALTER TABLE pressing_orders ADD COLUMN delivery_address VARCHAR(255) NULL AFTER delivery;
ALTER TABLE pressing_orders ADD COLUMN delivery_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER delivery_address;
-- Dépôts express déjà enregistrés : pourcentage retrouvé à partir du supplément
UPDATE pressing_orders SET express_pct = ROUND(express_fee * 100 / subtotal, 2) WHERE express = 1 AND express_pct IS NULL AND subtotal > 0;
