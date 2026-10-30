<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Admin\AdminService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Jobs\JobPostService;
use App\Services\Notifications\NotificationService;

/** Supervision du recrutement : offres (modération), candidatures, entretiens, contrats. */
final class RecruitmentController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AdminService $admin,
        private readonly AuthService $auth,
        private readonly JobPostService $posts,
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($view, $session);
    }

    public function posts(Request $request): Response
    {
        $f = ['q' => mb_substr($request->str('q'), 0, 100), 'status' => $request->str('status')];
        return $this->render('admin/recruitment/posts', ['title' => 'Offres publiées', 'result' => $this->admin->posts($f, (int) $request->query('page', 1)), 'f' => $f]);
    }

    public function moderatePost(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['action' => 'required|in:approve,reject,suspend', 'reason' => 'nullable|string|max:500'], ['reason' => 'motif']);
        $post = $this->db->fetch('SELECT id, status, title, client_user_id, uuid FROM job_posts WHERE uuid = ?', [(string) $request->param('uuid')]) ?? throw new HttpException(404);
        $adminId = (int) $this->auth->id();
        if ($data['action'] !== 'approve' && mb_strlen((string) $data['reason']) < 5) {
            throw new \App\Core\ValidationException(['reason' => ['Motif obligatoire : il est envoyé au recruteur.']]);
        }
        [$status, $title, $body] = match ($data['action']) {
            'approve' => ['CANDIDATURES_OUVERTES', 'Offre publiée', 'Votre offre « ' . $post['title'] . ' » a été validée et publiée.'],
            'reject' => ['REFUSEE', 'Offre refusée', 'Votre offre « ' . $post['title'] . ' » a été refusée. Motif : ' . $data['reason']],
            'suspend' => ['ANNULEE', 'Offre retirée', 'Votre offre « ' . $post['title'] . ' » a été retirée par l\'équipe. Motif : ' . $data['reason']],
        };
        if ($data['action'] === 'approve' && $post['status'] !== 'EN_MODERATION') {
            return $this->back('/admin/offres', 'Cette offre n\'est pas en attente de modération.');
        }
        $this->posts->setStatus((int) $post['id'], $status, $adminId, $data['reason']);
        $this->db->query('UPDATE job_posts SET moderated_by = ?, moderated_at = NOW(), rejection_reason = ? WHERE id = ?', [$adminId, $data['action'] === 'approve' ? null : $data['reason'], (int) $post['id']]);
        $this->audit->record($adminId, 'job_post.' . $data['action'], 'job_post', (int) $post['id'], ['status' => $post['status']], ['status' => $status], $data['reason']);
        $this->notifications->notify((int) $post['client_user_id'], 'job_post.moderated', $title, $body, '/recruteur/offres/' . $post['uuid']);
        return $this->redirect('/admin/offres', 'Décision enregistrée et transmise au recruteur.');
    }

    public function applications(Request $request): Response
    {
        $f = ['q' => mb_substr($request->str('q'), 0, 100), 'status' => $request->str('status')];
        return $this->render('admin/recruitment/applications', ['title' => 'Candidatures et entretiens', 'result' => $this->admin->applications($f, (int) $request->query('page', 1)), 'f' => $f]);
    }

    public function employments(Request $request): Response
    {
        $f = ['q' => mb_substr($request->str('q'), 0, 100), 'status' => $request->str('status')];
        return $this->render('admin/recruitment/employments', ['title' => 'Emplois et contrats', 'result' => $this->admin->employments($f, (int) $request->query('page', 1)), 'f' => $f]);
    }

    public function employment(Request $request): Response
    {
        $e = app(\App\Services\Employment\EmploymentService::class)->find((string) $request->param('uuid')) ?? throw new HttpException(404);
        return $this->render('admin/recruitment/employment', [
            'title' => 'Contrat ' . $e['reference'], 'e' => $e, 'terms' => json_decode((string) $e['terms_snapshot'], true) ?: [],
            'payslips' => $this->db->fetchAll('SELECT uuid, number, period_label, gross_amount, commission_amount, net_amount, status FROM payslips WHERE employment_id = ? ORDER BY period_start', [(int) $e['id']]),
        ]);
    }
}
