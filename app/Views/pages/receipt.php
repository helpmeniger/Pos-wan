<?php /** Reçu imprimable (Imprimer → Enregistrer au format PDF). */ $o = $order; ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>"></head>
<body style="background:#fff"><div class="container" style="max-width:720px;padding:24px 16px">
  <div class="row between"><img src="<?= asset('img/logo-text.svg') ?>" alt="MARWA" style="height:46px"><div style="text-align:right"><strong>Reçu <?= e($invoice['number']) ?></strong><br><span class="muted">Émis le <?= e(date('d/m/Y H:i', strtotime($invoice['issued_at']))) ?></span></div></div>
  <hr class="divider">
  <dl class="kv">
    <dt>Commande</dt><dd><?= e($o['reference']) ?></dd>
    <dt>Service</dt><dd><?= e(['taxi' => 'Taxi', 'pro' => 'Transport professionnel', 'food' => 'Livraison de repas', 'shop' => 'Livraison commerce', 'parcel' => 'Colis', 'document' => 'Documents', 'errand' => 'Courses', 'urgent' => 'Livraison urgente'][$o['service_type']] ?? $o['service_type']) ?></dd>
    <dt>Date</dt><dd><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></dd>
    <dt>Départ</dt><dd><?= e($o['pickup']['text']) ?></dd>
    <dt>Arrivée</dt><dd><?= e($o['dropoff']['text']) ?></dd>
    <?php if (!empty($o['provider'])): ?><dt>Prestataire</dt><dd><?= e($o['provider']['name']) ?> <?= e($o['provider']['vehicle']['plate'] ?? '') ?></dd><?php endif; ?>
    <?php if (!empty($o['merchant'])): ?><dt>Commerce</dt><dd><?= e($o['merchant']['name']) ?></dd><?php endif; ?>
  </dl>
  <hr class="divider">
  <table class="table">
    <?php foreach ($o['items'] ?? [] as $it): ?><tr><td><?= (int) $it['quantity'] ?> × <?= e($it['name']) ?></td><td class="num"><?= e(fcfa($it['line_total'])) ?></td></tr><?php endforeach; ?>
    <?php if ($o['flow'] !== 'merchant'): ?><tr><td>Prix convenu</td><td class="num"><?= e(fcfa($o['final_price'])) ?></td></tr><?php endif; ?>
    <?php if ($o['delivery_fee']): ?><tr><td>Frais de livraison</td><td class="num"><?= e(fcfa($o['delivery_fee'])) ?></td></tr><?php endif; ?>
    <?php if ($o['booking_fee']): ?><tr><td>Frais de réservation</td><td class="num"><?= e(fcfa($o['booking_fee'])) ?></td></tr><?php endif; ?>
    <?php if ($o['service_fee']): ?><tr><td>Frais de service</td><td class="num"><?= e(fcfa($o['service_fee'])) ?></td></tr><?php endif; ?>
    <?php if ($o['discount']): ?><tr><td>Réduction</td><td class="num">−<?= e(fcfa($o['discount'])) ?></td></tr><?php endif; ?>
    <tfoot><tr><td>Total payé (<?= e(['cash' => 'espèces', 'demo_mobile_money' => 'Mobile Money démo', 'business_account' => 'compte entreprise', 'mobile_money' => 'Mobile Money', 'card' => 'carte'][$o['payment_method']] ?? $o['payment_method']) ?>)</td><td class="num"><?= e(fcfa($o['total'])) ?></td></tr></tfoot>
  </table>
  <p class="small muted" style="margin-top:16px">Reçu non fiscal émis par la plateforme MARWA. Les mentions légales (NIF, RCCM, TVA) doivent être complétées selon la réglementation applicable avant exploitation commerciale.</p>
  <p class="no-print"><button class="btn btn-primary" data-print>Imprimer / PDF</button></p>
</div><script src="<?= asset('js/print.js') ?>"></script></body></html>
