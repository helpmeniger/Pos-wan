<?php
declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Str;

/**
 * JEU DE DONNÉES DE DÉMONSTRATION — clairement identifié (users.is_demo = 1, domaine marwa.demo,
 * numéros +227 90 00 00 xx fictifs). Aucune donnée personnelle réelle.
 * Ne pas exécuter en production.
 */
final class DemoSeeder
{
    public const PASSWORD = 'Demo2026!';

    public static function run(): array
    {
        $log = [];
        $city = (int) Database::value("SELECT id FROM cities WHERE name = 'Niamey'");
        $role = static fn (string $c) => (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => $c]);
        $hash = Auth::hashPassword(self::PASSWORD);
        $mk = static function (string $roleCode, string $name, ?string $phone, ?string $email) use ($role, $hash): int {
            return Database::insert('users', ['uuid' => Str::uuid(), 'role_id' => $role($roleCode), 'full_name' => $name, 'phone' => $phone, 'email' => $email,
                'password_hash' => $hash, 'status' => 'active', 'phone_verified_at' => $phone ? date('Y-m-d H:i:s') : null, 'is_demo' => 1]);
        };

        $admin = $mk('admin', 'Admin Démo', '+22790000099', 'admin@marwa.demo');
        $mk('support', 'Agent Assistance Démo', null, 'support@marwa.demo');
        $mk('finance', 'Comptable Démo', null, 'finance@marwa.demo');

        $client = $mk('client', 'Awa Client Démo', '+22790000001', 'client@marwa.demo');
        Database::insert('customer_profiles', ['user_id' => $client, 'city_id' => $city]);
        $client2 = $mk('client', 'Ibrahim Client Démo', '+22790000011', 'client2@marwa.demo');
        Database::insert('customer_profiles', ['user_id' => $client2, 'city_id' => $city]);

        $drivers = [
            ['Moussa Chauffeur Démo', '+22790000002', 'chauffeur@marwa.demo', 'taxi', 'Toyota', 'Corolla', 'Jaune', 'NG-DEMO-01', 4],
            ['Hadiza Chauffeure Démo', '+22790000012', 'chauffeur2@marwa.demo', 'sedan', 'Hyundai', 'Elantra', 'Gris', 'NG-DEMO-02', 4],
        ];
        foreach ($drivers as $d) {
            $id = $mk('driver', $d[0], $d[1], $d[2]);
            Database::insert('provider_profiles', ['user_id' => $id, 'provider_type' => 'driver', 'verification_status' => 'verified', 'city_id' => $city,
                'id_number' => 'DEMO-ID-' . $id, 'license_number' => 'DEMO-PC-' . $id, 'license_expires_at' => date('Y-m-d', strtotime('+2 years'))]);
            Database::insert('vehicles', ['provider_id' => $id, 'category' => $d[3], 'make' => $d[4], 'model' => $d[5], 'color' => $d[6], 'plate' => $d[7], 'capacity' => $d[8]]);
        }
        $courier = $mk('courier', 'Issa Livreur Démo', '+22790000003', 'livreur@marwa.demo');
        Database::insert('provider_profiles', ['user_id' => $courier, 'provider_type' => 'courier', 'verification_status' => 'verified', 'city_id' => $city, 'license_expires_at' => date('Y-m-d', strtotime('+2 years'))]);
        Database::insert('vehicles', ['provider_id' => $courier, 'category' => 'moto', 'make' => 'Haojue', 'model' => 'DK125', 'color' => 'Rouge', 'plate' => 'NG-DEMO-03', 'capacity' => 1]);
        $courier2 = $mk('courier', 'Mariama Livreuse Démo', '+22790000013', 'livreur2@marwa.demo');
        Database::insert('provider_profiles', ['user_id' => $courier2, 'provider_type' => 'courier', 'verification_status' => 'pending', 'city_id' => $city]);

        // Restaurant de démonstration (nom fictif, coordonnées approximatives du centre de Niamey)
        $owner = $mk('merchant', 'Fatouma Restauratrice Démo', '+22790000004', 'restaurant@marwa.demo');
        $m = Database::insert('merchants', ['owner_user_id' => $owner, 'kind' => 'restaurant', 'name' => 'Le Jardin du Fleuve (démo)', 'cuisine' => 'Cuisine nigérienne',
            'description' => 'Restaurant fictif de démonstration.', 'phone' => '+22790000004', 'address_text' => 'Niamey (adresse de démonstration)',
            'lat' => 13.5160, 'lng' => 2.1170, 'city_id' => $city, 'status' => 'verified', 'is_open' => 1, 'prep_time_min' => 20, 'delivery_mode' => 'marwa', 'delivery_radius_km' => 10]);
        $cats = [];
        foreach (['Plats', 'Grillades', 'Boissons'] as $i => $c) {
            $cats[$c] = Database::insert('product_categories', ['merchant_id' => $m, 'name' => $c, 'sort_order' => $i]);
        }
        $products = [
            ['Plats', 'Riz sauce arachide', 'Riz blanc, sauce arachide et viande de bœuf.', 2000, null],
            ['Plats', 'Djerma fou-fou sauce gombo', 'Pâte et sauce gombo.', 1800, null],
            ['Plats', 'Couscous de mil', 'Couscous de mil, sauce légumes.', 1500, 1300],
            ['Grillades', 'Brochettes de mouton (x5)', 'Brochettes grillées, oignons et piment.', 2500, null],
            ['Grillades', 'Poulet braisé', 'Demi-poulet braisé, frites ou alloco.', 3500, null],
            ['Boissons', 'Jus de bissap', 'Bouteille 50 cl.', 500, null],
            ['Boissons', 'Jus de gingembre', 'Bouteille 50 cl.', 500, null],
            ['Boissons', 'Eau minérale', 'Bouteille 1,5 L.', 600, null],
        ];
        $pid = [];
        foreach ($products as $p) {
            $pid[$p[1]] = Database::insert('products', ['merchant_id' => $m, 'category_id' => $cats[$p[0]], 'name' => $p[1], 'description' => $p[2], 'price' => $p[3], 'promo_price' => $p[4]]);
        }
        Database::insert('product_variants', ['product_id' => $pid['Poulet braisé'], 'kind' => 'variant', 'group_name' => 'Accompagnement', 'name' => 'Frites', 'price_delta' => 0]);
        Database::insert('product_variants', ['product_id' => $pid['Poulet braisé'], 'kind' => 'variant', 'group_name' => 'Accompagnement', 'name' => 'Alloco', 'price_delta' => 200]);
        Database::insert('product_variants', ['product_id' => $pid['Riz sauce arachide'], 'kind' => 'extra', 'group_name' => 'Suppléments', 'name' => 'Viande supplémentaire', 'price_delta' => 500]);
        Database::insert('product_variants', ['product_id' => $pid['Brochettes de mouton (x5)'], 'kind' => 'extra', 'group_name' => 'Suppléments', 'name' => 'Piment à part', 'price_delta' => 0]);

        // Entreprise de démonstration avec paiement différé
        $bizOwner = $mk('business', 'Gérant Entreprise Démo', '+22790000005', 'entreprise@marwa.demo');
        Database::insert('customer_profiles', ['user_id' => $bizOwner, 'city_id' => $city]);
        $biz = Database::insert('businesses', ['owner_user_id' => $bizOwner, 'name' => 'Sahel Services SARL (démo)', 'billing_email' => 'entreprise@marwa.demo',
            'status' => 'verified', 'postpaid_enabled' => 1, 'credit_limit' => 500000, 'monthly_budget' => 1000000]);
        Database::insert('business_members', ['business_id' => $biz, 'user_id' => $bizOwner, 'member_role' => 'owner']);
        Database::insert('business_members', ['business_id' => $biz, 'user_id' => $client2, 'member_role' => 'employee', 'monthly_limit' => 50000]);

        // Zone de service de démonstration (rectangle autour de Niamey, à redessiner par l'administration)
        $ring = [[2.03, 13.44], [2.21, 13.44], [2.21, 13.60], [2.03, 13.60], [2.03, 13.44]];
        Database::insert('service_zones', ['city_id' => $city, 'name' => 'Niamey — zone de démonstration (à ajuster)', 'polygon' => json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]),
            'min_lat' => 13.44, 'max_lat' => 13.60, 'min_lng' => 2.03, 'max_lng' => 2.21]);

        // Quartiers de Niamey (noms publics). Coordonnées NON renseignées : à compléter et vérifier par l'administration.
        foreach (['Plateau', 'Yantala', 'Lazaret', 'Koira Kano', 'Gamkallé', 'Terminus', 'Recasement', 'Dar Es Salam', 'Bobiel', 'Talladjé', 'Harobanda', 'Kalley', 'Banifandou', 'Goudel', 'Liberté', 'Boukoki'] as $n) {
            Database::execute('INSERT IGNORE INTO neighborhoods (city_id, name) VALUES (:c, :n)', ['c' => $city, 'n' => $n]);
        }

        Database::insert('promotions', ['code' => 'BIENVENUE', 'description' => '10 % de réduction sur votre première course (démonstration)', 'discount_type' => 'percent',
            'value' => 1000, 'max_discount' => 500, 'service_type' => 'taxi', 'funded_by' => 'marwa', 'starts_at' => date('Y-m-d 00:00:00'), 'ends_at' => date('Y-m-d 23:59:59', strtotime('+6 months')), 'per_user_limit' => 1]);
        Database::insert('promotions', ['code' => 'JARDIN500', 'description' => '500 FCFA offerts par Le Jardin du Fleuve (démo) dès 3 000 FCFA', 'discount_type' => 'fixed',
            'value' => 500, 'min_order' => 3000, 'service_type' => 'food', 'merchant_id' => $m, 'funded_by' => 'merchant', 'starts_at' => date('Y-m-d 00:00:00'), 'ends_at' => date('Y-m-d 23:59:59', strtotime('+6 months')), 'per_user_limit' => 3]);

        Database::insert('addresses', ['user_id' => $client, 'label' => 'Maison (démo)', 'address_text' => 'Niamey — adresse de démonstration', 'lat' => 13.5230, 'lng' => 2.1050]);
        Database::insert('addresses', ['user_id' => $client, 'label' => 'Bureau (démo)', 'address_text' => 'Niamey — bureau de démonstration', 'lat' => 13.5050, 'lng' => 2.1300]);

        $log[] = 'Comptes de démonstration créés (mot de passe commun : ' . self::PASSWORD . ') :';
        foreach (Database::select('SELECT u.email, u.phone, r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_demo = 1 ORDER BY u.id') as $u) {
            $log[] = sprintf('  - %-28s %-14s %s', $u['email'] ?? '-', $u['phone'] ?? '-', $u['name']);
        }
        $log[] = 'Restaurant « Le Jardin du Fleuve (démo) », entreprise « Sahel Services SARL (démo) », zone de démonstration, promotions BIENVENUE et JARDIN500.';
        return $log;
    }
}
