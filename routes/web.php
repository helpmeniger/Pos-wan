<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Web;
use App\Core\Router;

/*
 * Routes web. Middleware globaux (bootstrap/app.php) : en-têtes de sécurité, session,
 * CSRF sur toute requête non sûre, obligation de changer un mot de passe temporaire.
 */
return function (Router $r): void {
    // ---- Assistant de première installation (désactivé une fois l'application installée)
    $r->get('/installation', [Web\InstallController::class, 'show'], 'install');
    $r->post('/installation', [Web\InstallController::class, 'install']);

    // ---- Pages publiques
    $r->get('/', [Web\HomeController::class, 'index'], 'home');
    $r->get('/categories', [Web\HomeController::class, 'categories'], 'categories');
    $r->get('/categories/{slug:[a-z0-9-]+}', [Web\HomeController::class, 'category'], 'categories.show');
    $r->get('/a-propos', [Web\PageController::class, 'about'], 'about');
    $r->get('/aide', [Web\PageController::class, 'help'], 'help');
    $r->get('/conditions-utilisation', [Web\PageController::class, 'terms'], 'terms');
    $r->get('/confidentialite', [Web\PageController::class, 'privacy'], 'privacy');
    $r->get('/contact', [Web\PageController::class, 'contact'], 'contact');
    $r->get('/offres', [Web\JobController::class, 'index'], 'jobs');
    $r->get('/offres/{uuid:[0-9a-f-]{36}}', [Web\JobController::class, 'show'], 'jobs.show');
    $r->post('/contact', [Web\PageController::class, 'sendContact'])->middleware('throttle:contact');

    // ---- Invités uniquement
    $r->group('', ['guest'], function (Router $r) {
        $r->get('/connexion', [Web\AuthController::class, 'showLogin'], 'login');
        $r->post('/connexion', [Web\AuthController::class, 'login']);
        $r->get('/inscription', [Web\AuthController::class, 'showRegister'], 'register');
        $r->post('/inscription', [Web\AuthController::class, 'register'])->middleware('throttle:register_ip');
        $r->get('/mot-de-passe/oublie', [Web\PasswordController::class, 'showForgot'], 'password.forgot');
        $r->post('/mot-de-passe/oublie', [Web\PasswordController::class, 'sendForgot'])->middleware('throttle:password_reset');
        $r->get('/mot-de-passe/reinitialiser/{token:[A-Za-z0-9_-]+}', [Web\PasswordController::class, 'showReset'], 'password.reset');
        $r->post('/mot-de-passe/reinitialiser/{token:[A-Za-z0-9_-]+}', [Web\PasswordController::class, 'reset']);
    });

    // ---- Utilisateurs connectés
    $r->group('', ['auth'], function (Router $r) {
        $r->post('/deconnexion', [Web\AuthController::class, 'logout'], 'logout');
        $r->get('/tableau-de-bord', [Web\DashboardController::class, 'home'], 'dashboard');
        $r->get('/mot-de-passe/changer', [Web\PasswordController::class, 'showChange'], 'password.change');
        $r->post('/mot-de-passe/changer', [Web\PasswordController::class, 'change']);
        $r->get('/verifier-telephone', [Web\PhoneVerificationController::class, 'show'], 'phone.verify');
        $r->post('/verifier-telephone/envoyer', [Web\PhoneVerificationController::class, 'send']);
        $r->post('/verifier-telephone', [Web\PhoneVerificationController::class, 'verify']);
        $r->get('/notifications', [Web\NotificationController::class, 'index'], 'notifications');
        $r->post('/notifications/tout-lire', [Web\NotificationController::class, 'readAll']);
        $r->post('/notifications/{id:\d+}/lue', [Web\NotificationController::class, 'read']);

        $r->get('/espace/prestataire', [Web\DashboardController::class, 'worker'], 'worker.dashboard')->middleware('role:WORKER');
        $r->get('/espace/demandeur', [Web\DashboardController::class, 'client'], 'client.dashboard')->middleware('role:CLIENT');

        // Profil, vérification des documents, fichiers privés
        $r->get('/profil', [Web\ProfileController::class, 'edit'], 'profile');
        $r->post('/profil', [Web\ProfileController::class, 'update']);
        $r->get('/verification', [Web\VerificationController::class, 'index'], 'verification');
        $r->post('/verification', [Web\VerificationController::class, 'submit'])->middleware('throttle:upload');
        $r->get('/fichiers/{uuid:[0-9a-f-]{36}}', [Web\FileController::class, 'show']);

        // Candidat : postuler, suivre, répondre aux entretiens
        $r->post('/offres/{uuid:[0-9a-f-]{36}}/postuler', [Web\JobController::class, 'apply'])->middleware('role:WORKER');
        $r->get('/candidatures', [Web\CandidateController::class, 'index'], 'applications')->middleware('role:WORKER');
        $r->post('/candidatures/{uuid:[0-9a-f-]{36}}/retirer', [Web\CandidateController::class, 'withdraw'])->middleware('role:WORKER');
        $r->post('/entretiens/{uuid:[0-9a-f-]{36}}/reponse', [Web\CandidateController::class, 'respondInterview'])->middleware('role:WORKER');

        // Recruteur : offres, candidatures reçues, entretiens, embauche
        $r->group('/recruteur', ['role:CLIENT'], function (Router $r) {
            $r->get('/offres', [Web\RecruiterController::class, 'index'], 'recruiter.posts');
            $r->get('/offres/nouvelle', [Web\RecruiterController::class, 'create'], 'recruiter.posts.create');
            $r->post('/offres', [Web\RecruiterController::class, 'store']);
            $r->get('/offres/{uuid:[0-9a-f-]{36}}', [Web\RecruiterController::class, 'show'], 'recruiter.posts.show');
            $r->post('/offres/{uuid:[0-9a-f-]{36}}/statut', [Web\RecruiterController::class, 'toggle']);
            $r->post('/candidatures/{uuid:[0-9a-f-]{36}}/refuser', [Web\RecruiterController::class, 'refuse']);
            $r->get('/candidatures/{uuid:[0-9a-f-]{36}}/entretien', [Web\RecruiterController::class, 'interviewForm']);
            $r->post('/candidatures/{uuid:[0-9a-f-]{36}}/entretien', [Web\RecruiterController::class, 'scheduleInterview']);
            $r->post('/entretiens/{uuid:[0-9a-f-]{36}}/resultat', [Web\RecruiterController::class, 'concludeInterview']);
            $r->get('/candidatures/{uuid:[0-9a-f-]{36}}/embaucher', [Web\RecruiterController::class, 'hireForm']);
            $r->post('/candidatures/{uuid:[0-9a-f-]{36}}/embaucher', [Web\RecruiterController::class, 'hire']);
        });

        // Contrats, fiches de paie, archives (employeur et employé)
        $r->get('/emplois', [Web\EmploymentController::class, 'index'], 'employments');
        $r->get('/emplois/{uuid:[0-9a-f-]{36}}', [Web\EmploymentController::class, 'show'], 'employments.show');
        $r->get('/emplois/{uuid:[0-9a-f-]{36}}/imprimer', [Web\EmploymentController::class, 'print']);
        $r->post('/emplois/{uuid:[0-9a-f-]{36}}/accepter', [Web\EmploymentController::class, 'accept']);
        $r->post('/emplois/{uuid:[0-9a-f-]{36}}/refuser', [Web\EmploymentController::class, 'decline']);
        $r->post('/emplois/{uuid:[0-9a-f-]{36}}/terminer', [Web\EmploymentController::class, 'end']);
        $r->get('/fiches-de-paie/{uuid:[0-9a-f-]{36}}', [Web\PayslipController::class, 'show'], 'payslips.show');
        $r->get('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/imprimer', [Web\PayslipController::class, 'print']);
        $r->post('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/demander-paiement', [Web\PayslipController::class, 'requestPayment']);
        $r->post('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/paiement-effectue', [Web\PayslipController::class, 'declarePaid']);
        $r->post('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/confirmer-reception', [Web\PayslipController::class, 'confirm']);
        $r->post('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/contester', [Web\PayslipController::class, 'dispute']);
        $r->get('/archives', [Web\ArchiveController::class, 'index'], 'archives');
    });

    // ---- Administration (personnel uniquement, permission explicite)
    $r->group('/admin', ['auth', 'permission:admin.dashboard.view'], function (Router $r) {
        $r->get('/', [Admin\DashboardController::class, 'index'], 'admin.dashboard');

        $r->get('/utilisateurs', [Admin\UserController::class, 'index'])->middleware('permission:users.view');
        $r->get('/utilisateurs/{uuid:[0-9a-f-]{36}}', [Admin\UserController::class, 'show'])->middleware('permission:users.view');
        $r->post('/utilisateurs/{uuid:[0-9a-f-]{36}}/statut', [Admin\UserController::class, 'status'])->middleware('permission:users.manage');
        $r->post('/utilisateurs/{uuid:[0-9a-f-]{36}}/telephone', [Admin\UserController::class, 'verifyPhone'])->middleware('permission:users.verify');

        $r->get('/verifications', [Admin\VerificationController::class, 'index'])->middleware('permission:users.verify');
        $r->post('/verifications/{id:\d+}', [Admin\VerificationController::class, 'decide'])->middleware('permission:users.verify');

        $r->get('/offres', [Admin\RecruitmentController::class, 'posts'])->middleware('permission:jobs.moderate');
        $r->post('/offres/{uuid:[0-9a-f-]{36}}/moderation', [Admin\RecruitmentController::class, 'moderatePost'])->middleware('permission:jobs.moderate');
        $r->get('/candidatures', [Admin\RecruitmentController::class, 'applications'])->middleware('permission:missions.view_all');
        $r->get('/emplois', [Admin\RecruitmentController::class, 'employments'])->middleware('permission:missions.view_all');
        $r->get('/emplois/{uuid:[0-9a-f-]{36}}', [Admin\RecruitmentController::class, 'employment'])->middleware('permission:missions.view_all');

        $r->get('/finances', [Admin\FinanceController::class, 'index'])->middleware('permission:finance.dashboard.view');
        $r->get('/finances/export.csv', [Admin\FinanceController::class, 'export'])->middleware('permission:finance.export');
        $r->get('/fiches-de-paie/{uuid:[0-9a-f-]{36}}', [Admin\FinanceController::class, 'payslip'])->middleware('permission:finance.dashboard.view');
        $r->post('/fiches-de-paie/{uuid:[0-9a-f-]{36}}/annuler', [Admin\FinanceController::class, 'cancelPayslip'])->middleware('permission:finance.adjustments.create');
        $r->post('/commissions/{uuid:[0-9a-f-]{36}}/encaisser', [Admin\FinanceController::class, 'collectCommission'])->middleware('permission:finance.reconciliation');

        $r->get('/journaux', [Admin\SystemController::class, 'audit'])->middleware('permission:audit.view');
        $r->get('/parametres', [Admin\SystemController::class, 'settings'])->middleware('permission:settings.manage');
        $r->post('/parametres', [Admin\SystemController::class, 'saveSettings'])->middleware('permission:settings.manage');
        $r->post('/parametres/commission', [Admin\SystemController::class, 'saveCommission'])->middleware('permission:finance.commission_rules.manage');
        $r->get('/support', [Admin\SystemController::class, 'tickets'])->middleware('permission:support.manage');
        $r->post('/support/{id:\d+}', [Admin\SystemController::class, 'ticketStatus'])->middleware('permission:support.manage');
    });
};
