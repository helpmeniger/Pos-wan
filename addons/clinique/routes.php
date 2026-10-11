<?php
/** Routes du module Clinique (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Clinique\ClinicController as C;
use Addons\Clinique\HospitalController as H;
use Addons\Clinique\PharmacyController as P;

// Accueil, patients, rendez-vous
$router->get('/clinique', [C::class, 'dashboard'], 'clinique.view');
$router->get('/clinique/patients', [C::class, 'patients'], 'clinique.view');
$router->get('/clinique/patients/create', [C::class, 'patientForm'], 'clinique.reception');
$router->post('/clinique/patients', [C::class, 'patientStore'], 'clinique.reception');
$router->get('/clinique/patients/{id}', [C::class, 'patientShow'], 'clinique.view');
$router->get('/clinique/patients/{id}/edit', [C::class, 'patientEdit'], 'clinique.reception');
$router->post('/clinique/patients/{id}', [C::class, 'patientUpdate'], 'clinique.reception');
$router->post('/clinique/patients/{id}/queue', [C::class, 'admitQueue'], 'clinique.reception');
$router->get('/clinique/rdv', [C::class, 'agenda'], 'clinique.view');
$router->post('/clinique/rdv', [C::class, 'appointmentStore'], 'clinique.reception');
$router->post('/clinique/rdv/{id}/status', [C::class, 'appointmentStatus'], 'clinique.reception');

// Consultations (dossier médical)
$router->get('/clinique/consultations/{id}', [C::class, 'visit'], 'clinique.medical');
$router->post('/clinique/consultations/{id}/start', [C::class, 'visitStart'], 'clinique.doctor');
$router->post('/clinique/consultations/{id}', [C::class, 'visitSave'], 'clinique.doctor');
$router->post('/clinique/consultations/{id}/vitals', [C::class, 'visitVitals'], 'clinique.nurse');
$router->post('/clinique/consultations/{id}/prescription', [C::class, 'visitPrescription'], 'clinique.doctor');
$router->post('/clinique/consultations/{id}/bill', [C::class, 'visitBill'], 'clinique.view');
$router->get('/clinique/ordonnances/{id}/print', [C::class, 'prescriptionPrint'], 'clinique.view');

// Factures
$router->get('/clinique/factures', [C::class, 'bills'], 'clinique.reception');
$router->get('/clinique/factures/{id}', [C::class, 'bill'], 'clinique.reception');
$router->post('/clinique/factures/{id}/pay', [C::class, 'billPay'], 'clinique.reception');
$router->post('/clinique/factures/{id}/cancel', [C::class, 'billCancel'], 'clinique.manage');
$router->get('/clinique/factures/{id}/print', [C::class, 'billPrint'], 'clinique.reception');

// Paramètres et rapport
$router->get('/clinique/parametres', [C::class, 'settings'], 'clinique.manage');
$router->post('/clinique/parametres/actes', [C::class, 'actsSave'], 'clinique.manage');
$router->post('/clinique/parametres/lits', [C::class, 'bedsSave'], 'clinique.manage');
$router->post('/clinique/parametres/seuils', [C::class, 'thresholdsSave'], 'clinique.manage');

// Hospitalisation / mise en observation
$router->get('/clinique/hospitalisation', [H::class, 'index'], 'clinique.medical');
$router->post('/clinique/hospitalisation', [H::class, 'admit'], 'clinique.doctor');
$router->get('/clinique/sejours/{id}', [H::class, 'show'], 'clinique.medical');
$router->post('/clinique/sejours/{id}/vitals', [H::class, 'vitals'], 'clinique.nurse');
$router->post('/clinique/sejours/{id}/soins', [H::class, 'careAdd'], 'clinique.doctor');
$router->post('/clinique/sejours/{id}/note', [H::class, 'note'], 'clinique.nurse');
$router->post('/clinique/sejours/{id}/transfer', [H::class, 'transfer'], 'clinique.nurse');
$router->post('/clinique/sejours/{id}/prescription', [H::class, 'prescription'], 'clinique.doctor');
$router->post('/clinique/sejours/{id}/discharge', [H::class, 'discharge'], 'clinique.doctor');
$router->post('/clinique/soins/{id}/log', [H::class, 'careLog'], 'clinique.nurse');
$router->post('/clinique/soins/{id}/stop', [H::class, 'careStop'], 'clinique.doctor');

// Écran de supervision des malades (plein écran, actualisé automatiquement)
$router->get('/clinique/supervision', [H::class, 'supervision'], 'clinique.nurse');
$router->get('/clinique/supervision/feed', [H::class, 'feed'], 'clinique.nurse');

// Lien avec la pharmacie
$router->get('/clinique/pharmacie', [P::class, 'index'], 'clinique.pharmacy');
$router->get('/clinique/pharmacie/{id}', [P::class, 'show'], 'clinique.pharmacy');
$router->post('/clinique/pharmacie/{id}', [P::class, 'dispense'], 'clinique.pharmacy');
$router->post('/clinique/pharmacie/{id}/cancel', [P::class, 'cancel'], 'clinique.view');
