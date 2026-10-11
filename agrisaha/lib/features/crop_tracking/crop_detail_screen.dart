import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../domain/crop_progress.dart';
import '../../domain/harvest_estimator.dart';
import '../../shared/widgets/common_widgets.dart';
import '../calendar/crop_calendar_tab.dart';
import '../harvests/harvest_tab.dart';
import 'journal_tab.dart';

class CropDetailScreen extends ConsumerWidget {
  const CropDetailScreen({super.key, required this.cropId});

  final String cropId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final crop = ref.watch(cropProvider(cropId));
    return AsyncValueView<Crop?>(
      isPage: true,
      value: crop,
      onRetry: () => ref.invalidate(cropProvider(cropId)),
      data: (crop) {
        if (crop == null) {
          return Scaffold(
            appBar: AppBar(),
            body: const ErrorState(message: 'Cette culture n’existe plus.'),
          );
        }
        return DefaultTabController(
          length: 4,
          child: Scaffold(
            appBar: AppBar(
              title: Text(crop.displayName),
              actions: [_CropMenu(crop: crop)],
              bottom: const TabBar(
                isScrollable: true,
                tabAlignment: TabAlignment.start,
                tabs: [
                  Tab(text: 'Aperçu'),
                  Tab(text: 'Calendrier'),
                  Tab(text: 'Journal'),
                  Tab(text: 'Récolte'),
                ],
              ),
            ),
            body: TabBarView(
              children: [
                _OverviewTab(crop: crop),
                CropCalendarTab(crop: crop),
                JournalTab(crop: crop),
                HarvestTab(crop: crop),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _CropMenu extends ConsumerWidget {
  const _CropMenu({required this.crop});

  final Crop crop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PopupMenuButton<String>(
      tooltip: 'Actions',
      onSelected: (value) async {
        switch (value) {
          case 'abandon':
            final ok = await confirmDialog(
              context,
              title: 'Marquer comme abandonnée ?',
              message:
                  'Les rappels de cette culture ne seront plus '
                  'affichés. Son historique est conservé.',
            );
            if (!ok || !context.mounted) return;
            await runGuarded(context, () async {
              await ref
                  .read(cropRepositoryProvider)
                  .setStatus(crop.id, CropStatus.abandoned);
              ref.read(dataRevisionProvider.notifier).bump();
            }, successMessage: 'Culture marquée comme abandonnée.');
          case 'reactivate':
            await runGuarded(context, () async {
              await ref
                  .read(cropRepositoryProvider)
                  .setStatus(crop.id, CropStatus.active);
              ref.read(dataRevisionProvider.notifier).bump();
            }, successMessage: 'Culture remise en cours.');
          case 'delete':
            final ok = await confirmDialog(
              context,
              title: 'Supprimer la culture ?',
              message:
                  'Le calendrier, le journal et les récoltes de cette '
                  'culture seront définitivement supprimés.',
              confirmLabel: 'Supprimer',
              destructive: true,
            );
            if (!ok || !context.mounted) return;
            final router = GoRouter.of(context);
            final deleted = await runGuarded(context, () async {
              await ref.read(cropRepositoryProvider).delete(crop.id);
              ref.read(dataRevisionProvider.notifier).bump();
            }, successMessage: 'Culture supprimée.');
            if (deleted) router.pop();
        }
      },
      itemBuilder: (context) => [
        if (crop.status == CropStatus.active)
          const PopupMenuItem(
            value: 'abandon',
            child: Text('Marquer comme abandonnée'),
          ),
        if (crop.status == CropStatus.abandoned)
          const PopupMenuItem(
            value: 'reactivate',
            child: Text('Remettre en cours'),
          ),
        const PopupMenuItem(value: 'delete', child: Text('Supprimer')),
      ],
    );
  }
}

class _OverviewTab extends ConsumerWidget {
  const _OverviewTab({required this.crop});

  final Crop crop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final today = ref.watch(todayProvider);
    final progress = CropProgress.compute(crop, today);
    final plot = ref.watch(plotProvider(crop.plotId)).value;
    final confidence = HarvestEstimator.confidenceOf(crop.estimateSource);
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (crop.isDemo) ...[
          const InfoBanner(message: 'Donnée de démonstration.'),
          const SizedBox(height: 12),
        ],
        SectionCard(
          title: 'Suivi de la croissance',
          icon: Icons.timeline,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: LinearProgressIndicator(
                  value: progress.fraction,
                  minHeight: 14,
                  backgroundColor: AppColors.sand,
                ),
              ),
              const SizedBox(height: 12),
              InfoRow(
                label: crop.cropType.isUsuallyTransplanted
                    ? 'Date de semis / repiquage'
                    : 'Date de semis',
                value: Formatters.date(crop.sowingDate),
              ),
              InfoRow(
                label: 'Âge de la culture',
                value: progress.ageDays < 0
                    ? 'Semis dans ${Formatters.days(progress.ageDays)}'
                    : Formatters.days(progress.ageDays),
              ),
              InfoRow(label: 'Stade estimé', value: progress.stage.label),
              InfoRow(label: 'Statut', value: crop.status.label),
            ],
          ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Récolte prévisionnelle',
          icon: Icons.agriculture,
          color: AppColors.lightYellow,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                Formatters.longDate(crop.estimatedHarvestDate),
                style: theme.textTheme.titleLarge,
              ),
              const SizedBox(height: 4),
              if (crop.status == CropStatus.active)
                Text(
                  progress.daysRemaining > 0
                      ? 'Dans ${Formatters.days(progress.daysRemaining)}'
                      : progress.daysRemaining == 0
                      ? 'Aujourd’hui'
                      : 'Date estimée dépassée de '
                            '${Formatters.days(progress.daysRemaining)}',
                  style: theme.textTheme.titleMedium,
                ),
              const SizedBox(height: 8),
              InfoRow(
                label: 'Durée de maturation',
                value: '${crop.maturationDays} jours',
              ),
              if (crop.maturationMinDays != null &&
                  crop.maturationMaxDays != null &&
                  crop.maturationMinDays != crop.maturationMaxDays)
                InfoRow(
                  label: 'Fenêtre probable',
                  value:
                      '${Formatters.shortDate(DateOnly.addDays(crop.sowingDate, crop.maturationMinDays!))}'
                      ' – ${Formatters.date(DateOnly.addDays(crop.sowingDate, crop.maturationMaxDays!))}',
                ),
              InfoRow(label: 'Source', value: crop.estimateSource.label),
              InfoRow(label: 'Confiance', value: confidence.label),
              const SizedBox(height: 8),
              const InfoBanner(
                message:
                    'Estimation : la date peut changer selon la '
                    'variété, la météo, l’eau, le sol et les pratiques '
                    'agricoles.',
              ),
              if (crop.status == CropStatus.active) ...[
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      onPressed: () => _correctDate(context, ref),
                      icon: const Icon(Icons.edit_calendar),
                      label: const Text('Corriger la date'),
                    ),
                    FilledButton.icon(
                      onPressed: () => context.push(Routes.newHarvest(crop.id)),
                      icon: const Icon(Icons.inventory_2),
                      label: const Text('Enregistrer la récolte'),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Fiche de la culture',
          icon: Icons.description_outlined,
          child: Column(
            children: [
              InfoRow(label: 'Parcelle', value: plot?.name ?? '—'),
              InfoRow(label: 'Culture', value: crop.cropLabel),
              InfoRow(label: 'Variété', value: crop.varietyName ?? 'Inconnue'),
              InfoRow(label: 'Type de semence', value: crop.seedType.label),
              InfoRow(
                label: 'Superficie',
                value:
                    '${Formatters.number(crop.area)} ${crop.areaUnit.symbol}',
              ),
              InfoRow(label: 'Irrigation', value: crop.irrigationMode.label),
              InfoRow(label: 'Type de sol', value: crop.soilType.label),
              InfoRow(
                label: 'Localisation',
                value: [
                  crop.village,
                  crop.commune,
                  crop.region,
                ].whereType<String>().join(', ').ifEmpty('—'),
              ),
              if (crop.notes != null)
                InfoRow(label: 'Observations', value: crop.notes!),
            ],
          ),
        ),
        if (crop.photoPath != null) ...[
          const SizedBox(height: 16),
          ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: Image.file(
              File(crop.photoPath!),
              height: 200,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) =>
                  const Text('Photo introuvable sur ce téléphone.'),
            ),
          ),
        ],
      ],
    );
  }

  Future<void> _correctDate(BuildContext context, WidgetRef ref) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: crop.estimatedHarvestDate,
      firstDate: DateOnly.addDays(crop.sowingDate, 1),
      lastDate: DateOnly.addDays(
        crop.sowingDate,
        HarvestEstimator.maxCycleDays,
      ),
      locale: const Locale('fr', 'FR'),
      helpText: 'Nouvelle date de récolte estimée',
    );
    if (picked == null || !context.mounted) return;
    await runGuarded(
      context,
      () async {
        await ref
            .read(cropRepositoryProvider)
            .correctHarvestDate(crop.id, picked);
        ref.read(dataRevisionProvider.notifier).bump();
      },
      successMessage:
          'Date corrigée. Les tâches de fin de cycle ont été '
          'recalculées.',
    );
  }
}

extension on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}
