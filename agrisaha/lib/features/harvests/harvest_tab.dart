import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/models/harvest.dart';
import '../../domain/yield_calculator.dart';
import '../../shared/widgets/common_widgets.dart';

/// Récoltes réelles d'une culture, rendement et comparaison avec
/// l'estimation.
class HarvestTab extends ConsumerWidget {
  const HarvestTab({super.key, required this.crop});

  final Crop crop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final harvests = ref.watch(cropHarvestsProvider(crop.id));
    final converter =
        ref.watch(areaConverterProvider).value ?? const AreaConverter();
    return AsyncValueView<List<Harvest>>(
      value: harvests,
      onRetry: () => ref.invalidate(cropHarvestsProvider(crop.id)),
      data: (harvests) {
        final canAdd = crop.status != CropStatus.harvested;
        if (harvests.isEmpty) {
          return EmptyState(
            icon: Icons.inventory_2_outlined,
            title: 'Aucune récolte enregistrée',
            message:
                'Le jour de la récolte, enregistrez la quantité obtenue '
                'pour calculer le rendement et le comparer à l’estimation.',
            actionLabel: canAdd ? 'Enregistrer la récolte' : null,
            onAction: canAdd
                ? () => context.push(Routes.newHarvest(crop.id))
                : null,
          );
        }
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            for (final harvest in harvests) ...[
              _HarvestCard(harvest: harvest, crop: crop, converter: converter),
              const SizedBox(height: 12),
            ],
            if (canAdd)
              OutlinedButton.icon(
                onPressed: () => context.push(Routes.newHarvest(crop.id)),
                icon: const Icon(Icons.add),
                label: const Text('Ajouter une récolte'),
              ),
          ],
        );
      },
    );
  }
}

class _HarvestCard extends ConsumerWidget {
  const _HarvestCard({
    required this.harvest,
    required this.crop,
    required this.converter,
  });

  final Harvest harvest;
  final Crop crop;
  final AreaConverter converter;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final yieldResult = YieldCalculator.compute(harvest, crop, converter);
    final comparison = HarvestComparison.of(harvest, crop);
    final unit = harvest.unitSymbol;
    String qty(double? v) => v == null ? '—' : '${Formatters.number(v)} $unit';
    return SectionCard(
      title: 'Récolte du ${Formatters.date(harvest.harvestDate)}',
      icon: Icons.inventory_2,
      trailing: IconButton(
        tooltip: 'Supprimer',
        icon: const Icon(Icons.delete_outline),
        onPressed: () => _delete(context, ref),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InfoRow(label: 'Quantité récoltée', value: qty(harvest.quantity)),
          if (yieldResult.quantityKg != null && harvest.unit != HarvestUnit.kg)
            InfoRow(
              label: 'Équivalent',
              value:
                  '${Formatters.number(yieldResult.quantityKg!, maxDecimals: 0)} kg',
            ),
          InfoRow(label: 'Pertes estimées', value: qty(harvest.lossesQuantity)),
          InfoRow(label: 'Vendu', value: qty(harvest.soldQuantity)),
          InfoRow(label: 'Conservé', value: qty(harvest.storedQuantity)),
          if (harvest.unitPrice != null)
            InfoRow(
              label: 'Prix de vente',
              value: '${Formatters.money(harvest.unitPrice!)} / $unit',
            ),
          if (harvest.salesRevenue != null)
            InfoRow(
              label: 'Recette de la vente',
              value: Formatters.money(harvest.salesRevenue!),
            ),
          InfoRow(
            label: 'Type',
            value: harvest.isFinal ? 'Récolte finale' : 'Récolte partielle',
          ),
          const Divider(height: 24),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.lightGreen,
              borderRadius: BorderRadius.circular(12),
            ),
            child: yieldResult.kgPerHectare != null
                ? Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Rendement',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      Text(
                        '${Formatters.number(yieldResult.kgPerHectare!, maxDecimals: 0)} kg/ha',
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      Text(
                        'Sur ${Formatters.number(yieldResult.areaHectares!, maxDecimals: 4)} ha',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  )
                : Text(yieldResult.reason ?? 'Rendement non calculable.'),
          ),
          const SizedBox(height: 12),
          Text(
            'Comparaison avec l’estimation',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          InfoRow(
            label: 'Date estimée',
            value: Formatters.date(harvest.estimatedHarvestDate),
          ),
          InfoRow(
            label: 'Cycle réel / estimé',
            value:
                '${comparison.actualCycleDays} j / '
                '${comparison.estimatedCycleDays} j',
          ),
          Text(
            comparison.summary,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          if (harvest.notes != null) ...[
            const SizedBox(height: 8),
            Text('Observations : ${harvest.notes}'),
          ],
        ],
      ),
    );
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final ok = await confirmDialog(
      context,
      title: 'Supprimer cette récolte ?',
      message:
          'La récolte et la recette de vente associée seront '
          'supprimées.',
      confirmLabel: 'Supprimer',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(harvestRepositoryProvider).delete(harvest.id);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Récolte supprimée.');
  }
}
