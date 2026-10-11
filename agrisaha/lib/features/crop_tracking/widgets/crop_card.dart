import 'package:flutter/material.dart';

import '../../../app/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../data/models/crop.dart';
import '../../../data/models/enums.dart';
import '../../../domain/crop_progress.dart';

/// Carte résumant l'avancement d'une culture.
class CropCard extends StatelessWidget {
  const CropCard({
    super.key,
    required this.crop,
    required this.today,
    this.plotName,
    this.onTap,
  });

  final Crop crop;
  final DateTime today;
  final String? plotName;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final progress = CropProgress.compute(crop, today);
    final theme = Theme.of(context);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  CircleAvatar(
                    backgroundColor: AppColors.lightGreen,
                    child: Icon(
                      cropIcon(crop.cropType),
                      color: AppColors.green,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          crop.displayName,
                          style: theme.textTheme.titleMedium,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(
                          [
                            ?plotName,
                            'Semis : ${Formatters.date(crop.sowingDate)}',
                          ].join(' · '),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: AppColors.textMuted,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                  if (crop.isDemo)
                    const Padding(
                      padding: EdgeInsets.only(left: 4),
                      child: Chip(
                        label: Text('Démo'),
                        visualDensity: VisualDensity.compact,
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: LinearProgressIndicator(
                  value: progress.fraction,
                  minHeight: 10,
                  color: progress.isHarvestOverdue
                      ? AppColors.harvestYellow
                      : AppColors.green,
                  backgroundColor: AppColors.sand,
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${progress.stage.label} (estimé)',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  Text(
                    _remainingLabel(progress),
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: progress.isHarvestOverdue
                          ? const Color(0xFF8D6E00)
                          : AppColors.darkGreen,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _remainingLabel(CropProgress progress) {
    if (crop.status == CropStatus.harvested) return 'Récoltée';
    if (crop.status == CropStatus.abandoned) return 'Abandonnée';
    if (progress.daysRemaining < 0) {
      return 'Récolte estimée dépassée';
    }
    if (progress.daysRemaining == 0) return 'Récolte estimée aujourd’hui';
    return 'Récolte dans ${Formatters.days(progress.daysRemaining)}';
  }
}

IconData cropIcon(CropType type) => switch (type.group) {
  CropGroup.cereal => Icons.grass,
  CropGroup.rice => Icons.water,
  CropGroup.legume => Icons.spa,
  CropGroup.vegetable => Icons.eco,
  CropGroup.other => Icons.agriculture,
};
