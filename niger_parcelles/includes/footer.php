<?php
/** Pied de page commun : scripts. Variable optionnelle $scripts (fichiers de assets/js). */
declare(strict_types=1);

$vendor = $vendor ?? [];
$scripts = $scripts ?? [];
?>
<?php if (current_user()): ?>
        </main>
        <footer class="np-footer small text-muted">
            Niger Parcelles v<?= e(config('version')) ?> · Les informations affichées sont des données de gestion et ne valent pas titre foncier.
        </footer>
    </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="npToasts"></div>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<?php if (in_array('leaflet', $vendor, true)): ?><script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><?php endif; ?>
<?php if (in_array('geoman', $vendor, true)): ?><script src="<?= e(asset('vendor/geoman/leaflet-geoman.js')) ?>"></script><?php endif; ?>
<?php if (in_array('turf', $vendor, true)): ?><script src="<?= e(asset('vendor/turf/turf.min.js')) ?>"></script><?php endif; ?>
<?php if (in_array('chart', $vendor, true)): ?><script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script><?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
