import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/models/plot.dart';
import '../../shared/widgets/common_widgets.dart';

class PlotsScreen extends ConsumerWidget {
  const PlotsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plots = ref.watch(plotsProvider);
    final crops = ref.watch(cropsProvider).value ?? const <Crop>[];
    return Scaffold(
      appBar: AppBar(title: const Text('Mes parcelles')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push(Routes.newPlot),
        icon: const Icon(Icons.add),
        label: const Text('Nouvelle parcelle'),
      ),
      body: AsyncValueView<List<Plot>>(
        value: plots,
        onRetry: () => ref.invalidate(plotsProvider),
        data: (plots) {
          if (plots.isEmpty) {
            return EmptyState(
              icon: Icons.crop_square,
              title: 'Aucune parcelle',
              message:
                  'Ajoutez vos champs ou jardins pour y enregistrer '
                  'vos cultures.',
              actionLabel: 'Ajouter une parcelle',
              onAction: () => context.push(Routes.newPlot),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            itemCount: plots.length,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final plot = plots[index];
              final plotCrops = crops.where((c) => c.plotId == plot.id);
              final active = plotCrops
                  .where((c) => c.status == CropStatus.active)
                  .length;
              return _PlotCard(
                plot: plot,
                activeCrops: active,
                totalCrops: plotCrops.length,
              );
            },
          );
        },
      ),
    );
  }
}

class _PlotCard extends ConsumerWidget {
  const _PlotCard({
    required this.plot,
    required this.activeCrops,
    required this.totalCrops,
  });

  final Plot plot;
  final int activeCrops;
  final int totalCrops;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.crop_square, color: AppColors.green),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(plot.name, style: theme.textTheme.titleMedium),
                ),
                PopupMenuButton<String>(
                  tooltip: 'Actions',
                  onSelected: (value) => _onAction(context, ref, value),
                  itemBuilder: (context) => const [
                    PopupMenuItem(value: 'edit', child: Text('Modifier')),
                    PopupMenuItem(value: 'delete', child: Text('Supprimer')),
                  ],
                ),
              ],
            ),
            Text(
              '${Formatters.number(plot.area)} ${plot.areaUnit.symbol}'
              '${plot.locationLabel.isEmpty ? '' : ' · ${plot.locationLabel}'}',
              style: theme.textTheme.bodyMedium,
            ),
            Text(
              'Sol : ${plot.soilType.label}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textMuted,
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    '$activeCrops culture(s) en cours · $totalCrops au total',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
                TextButton.icon(
                  onPressed: () =>
                      context.push('${Routes.newCrop}?parcelle=${plot.id}'),
                  icon: const Icon(Icons.add),
                  label: const Text('Culture'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _onAction(
    BuildContext context,
    WidgetRef ref,
    String action,
  ) async {
    if (action == 'edit') {
      context.push(Routes.editPlot(plot.id));
      return;
    }
    final confirmed = await confirmDialog(
      context,
      title: 'Supprimer la parcelle ?',
      message: 'La parcelle « ${plot.name} » sera supprimée de ce téléphone.',
      confirmLabel: 'Supprimer',
      destructive: true,
    );
    if (!confirmed || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(plotRepositoryProvider).delete(plot.id);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Parcelle supprimée.');
  }
}
