<div class="mc-error-code">500</div>
<h1 class="h3 fw-bold mt-2"><i class="bi bi-bug text-primary me-1"></i>Erreur interne</h1>
<p class="text-muted"><?= e($message) ?></p>
<?php if (!empty($exception)): ?>
    <pre class="text-start small bg-body-tertiary p-3 rounded border"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) ?></pre>
<?php endif; ?>
<a class="btn btn-primary mt-2" href="<?= e(url('/')) ?>"><i class="bi bi-house me-1"></i> Retour à l'accueil</a>
