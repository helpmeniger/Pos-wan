-- Pressing 1.1 : retrait partiel des vêtements, date et auteur de chaque retrait
ALTER TABLE pressing_orders MODIFY status ENUM('received','processing','ready','partial','delivered','cancelled') NOT NULL DEFAULT 'received';
ALTER TABLE pressing_items ADD COLUMN picked_qty INT NOT NULL DEFAULT 0 AFTER quantity;
ALTER TABLE pressing_items ADD COLUMN picked_at DATETIME NULL AFTER picked_qty;
UPDATE pressing_items i JOIN pressing_orders o ON o.id = i.order_id SET i.picked_qty = i.quantity, i.picked_at = o.delivered_at WHERE o.status = 'delivered';
