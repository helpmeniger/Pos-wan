import '../core/utils/date_utils.dart';
import '../data/models/crop.dart';
import '../data/models/enums.dart';

/// Stade de développement estimé à partir de l'avancement du cycle.
enum GrowthStage {
  planned('Semis prévu'),
  establishment('Levée / installation'),
  vegetative('Développement végétatif'),
  flowering('Floraison'),
  filling('Formation des grains, gousses ou fruits'),
  maturation('Maturation'),
  harvestDue('Récolte attendue'),
  harvested('Récoltée'),
  abandoned('Abandonnée');

  const GrowthStage(this.label);
  final String label;
}

/// Indicateurs de suivi d'une culture à une date donnée.
class CropProgress {
  const CropProgress({
    required this.ageDays,
    required this.daysRemaining,
    required this.fraction,
    required this.stage,
  });

  /// Âge de la culture en jours depuis le semis (négatif si semis futur).
  final int ageDays;

  /// Jours restants avant la date estimée de récolte (négatif si dépassée).
  final int daysRemaining;

  /// Avancement du cycle entre 0 et 1.
  final double fraction;
  final GrowthStage stage;

  bool get isHarvestOverdue =>
      daysRemaining < 0 &&
      stage != GrowthStage.harvested &&
      stage != GrowthStage.abandoned;

  static CropProgress compute(Crop crop, DateTime today) {
    final day = DateOnly.of(today);
    final age = DateOnly.daysBetween(crop.sowingDate, day);
    final remaining = DateOnly.daysBetween(day, crop.estimatedHarvestDate);
    final total = crop.maturationDays <= 0 ? 1 : crop.maturationDays;
    final rawFraction = age / total;
    final fraction = rawFraction.clamp(0.0, 1.0).toDouble();

    final GrowthStage stage;
    if (crop.status == CropStatus.harvested) {
      stage = GrowthStage.harvested;
    } else if (crop.status == CropStatus.abandoned) {
      stage = GrowthStage.abandoned;
    } else {
      stage = stageForFraction(age < 0 ? -1 : rawFraction);
    }
    return CropProgress(
      ageDays: age,
      daysRemaining: remaining,
      fraction: crop.status == CropStatus.harvested ? 1 : fraction,
      stage: stage,
    );
  }

  /// Correspondance générique avancement → stade (approximation affichée
  /// comme « estimée » dans l'interface).
  static GrowthStage stageForFraction(double fraction) {
    if (fraction < 0) return GrowthStage.planned;
    if (fraction < 0.10) return GrowthStage.establishment;
    if (fraction < 0.40) return GrowthStage.vegetative;
    if (fraction < 0.60) return GrowthStage.flowering;
    if (fraction < 0.85) return GrowthStage.filling;
    if (fraction < 1.0) return GrowthStage.maturation;
    return GrowthStage.harvestDue;
  }
}
