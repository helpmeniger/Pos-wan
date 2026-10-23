<?php
use App\Core\Env;

/*
 * Licence d'utilisation de POS Marwa — © Mahamadou Hamidou (Mohamed).
 * Clé PUBLIQUE de vérification des licences (Ed25519). La clé privée correspondante reste
 * exclusivement chez l'auteur : elle n'est jamais livrée avec le logiciel.
 */
return [
    'public_key' => (Env::get('APP_ENV') === 'testing' && Env::get('LICENCE_TEST_PUBLIC_KEY'))
        ? (string) Env::get('LICENCE_TEST_PUBLIC_KEY')
        : 'dngSoqpL/lpXPbhyegoWVWjOP7H7g7rOaBizE5e/MTo=',
    'trial_days' => 30,
    'author' => [
        'name' => 'Mahamadou Hamidou (Mohamed)',
        'title' => 'Ingénieur informaticien nigérien',
        'country' => 'Niamey, République du Niger',
        'phones' => ['+227 92 08 12 82', '+227 87 22 52 02'],
    ],
    /*
     * Serveur d'assistance à distance de High Tech Niger (module MARWA REMOTE ASSIST) : adresse IP
     * publique fixe et clé publique affichées par « php bin/assist.php cle ». Renseignées ici (ou dans
     * .env : ASSIST_HOST, ASSIST_KEY) avant de distribuer POS Marwa, chaque boutique est réglée d'office.
     */
    'assist' => [
        'host' => (string) Env::get('ASSIST_HOST', ''),
        'key' => (string) Env::get('ASSIST_KEY', ''),
    ],
];
