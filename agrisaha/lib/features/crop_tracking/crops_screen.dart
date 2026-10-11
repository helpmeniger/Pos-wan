import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../core/providers/providers.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/models/plot.dart';
import '../../shared/widgets/common_widgets.dart';
import 'widgets/crop_card.dart';

/// Onglet « Mes cultures » : cultures en cours, récoltées et abandonnées.
class CropsScreen extends ConsumerStatefulWidget {
  const CropsScreen({super.key});

  @override
  ConsumerState<CropsScreen> createState() => _CropsScreenState();
}

class _CropsScreenState extends ConsumerState<CropsScreen> {
  CropStatus _filter = CropStatus.active;

  @override
  Widget build(BuildContext context) {
    final crops = ref.watch(cropsProvider);
    final plots = ref.watch(plotsProvider).value ?? const <Plot>[];
    final plotNames = {for (final p in plots) p.id: p.name};
    final today = ref.watch(todayProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mes cultures'),
        actions: [
          TextButton.icon(
            onPressed: () => context.push(Routes.plots),
            icon: const Icon(Icons.crop_square),
            label: const Text('Parcelles'),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'new-crop',
        onPressed: () => context.push(Routes.newCrop),
        icon: const Icon(Icons.add),
        label: const Text('Nouvelle culture'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: SegmentedButton<CropStatus>(
              segments: const [
                ButtonSegment(
                  value: CropStatus.active,
                  label: Text('En cours'),
                ),
                ButtonSegment(
                  value: CropStatus.harvested,
                  label: Text('Récoltées'),
                ),
                ButtonSegment(
                  value: CropStatus.abandoned,
                  label: Text('Abandonnées'),
                ),
              ],
              selected: {_filter},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _filter = s.first),
            ),
          ),
          Expanded(
            child: AsyncValueView<List<Crop>>(
              value: crops,
              onRetry: () => ref.invalidate(cropsProvider),
              data: (all) {
                final list = all.where((c) => c.status == _filter).toList();
                if (_filter == CropStatus.active) {
                  list.sort(
                    (a, b) => a.estimatedHarvestDate.compareTo(
                      b.estimatedHarvestDate,
                    ),
                  );
                }
                if (list.isEmpty) {
                  return EmptyState(
                    icon: Icons.grass,
                    title: _filter == CropStatus.active
                        ? 'Aucune culture en cours'
                        : 'Aucune culture dans cette catégorie',
                    message:
                        'Enregistrez un semis pour obtenir la date '
                        'estimée de récolte et le calendrier des travaux.',
                    actionLabel: _filter == CropStatus.active
                        ? 'Nouvelle culture'
                        : null,
                    onAction: _filter == CropStatus.active
                        ? () => context.push(Routes.newCrop)
                        : null,
                  );
                }
                return ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 12),
                  itemBuilder: (context, index) {
                    final crop = list[index];
                    return CropCard(
                      crop: crop,
                      today: today,
                      plotName: plotNames[crop.plotId],
                      onTap: () => context.push(Routes.crop(crop.id)),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
