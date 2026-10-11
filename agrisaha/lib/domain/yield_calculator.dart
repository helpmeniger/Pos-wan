import '../core/utils/date_utils.dart';
import '../data/models/crop.dart';
import '../data/models/enums.dart';
import '../data/models/harvest.dart';

/// Conversion des surfaces en hectares.
class AreaConverter {
  const AreaConverter({this.localUnitHectares});

  /// Équivalence en hectares de l'unité locale configurée dans le profil.
  final double? localUnitHectares;

  double? toHectares(double value, AreaUnit unit) => switch (unit) {
    AreaUnit.hectare => value,
    AreaUnit.squareMeter => value / 10000,
    AreaUnit.local =>
      localUnitHectares == null ? null : value * localUnitHectares!,
  };
}

/// Conversion des quantités récoltées en kilogrammes.
class QuantityConverter {
  const QuantityConverter._();

  static double? toKg(double quantity, HarvestUnit unit, double? kgPerUnit) =>
      switch (unit) {
        HarvestUnit.kg => quantity,
        HarvestUnit.tonne => quantity * 1000,
        HarvestUnit.sac ||
        HarvestUnit.autre => kgPerUnit == null ? null : quantity * kgPerUnit,
      };
}

class YieldResult {
  const YieldResult({this.quantityKg, this.areaHectares, this.reason});

  final double? quantityKg;
  final double? areaHectares;

  /// Explication lorsque le rendement ne peut pas être calculé.
  final String? reason;

  double? get kgPerHectare =>
      quantityKg != null && areaHectares != null && areaHectares! > 0
      ? quantityKg! / areaHectares!
      : null;
}

class YieldCalculator {
  const YieldCalculator._();

  /// Rendement en kg/ha d'une récolte. Utilise la surface récoltée si elle
  /// est renseignée, sinon la surface cultivée de la culture.
  static YieldResult compute(
    Harvest harvest,
    Crop crop,
    AreaConverter converter,
  ) {
    final kg = QuantityConverter.toKg(
      harvest.quantity,
      harvest.unit,
      harvest.kgPerUnit,
    );
    final area = harvest.harvestedArea ?? crop.area;
    final unit = harvest.harvestedArea != null
        ? (harvest.harvestedAreaUnit ?? crop.areaUnit)
        : crop.areaUnit;
    final hectares = converter.toHectares(area, unit);
    String? reason;
    if (kg == null) {
      reason =
          'Indiquez le poids d’une unité (kg par sac) pour calculer '
          'le rendement.';
    } else if (hectares == null) {
      reason =
          'Définissez l’équivalence en hectares de l’unité locale dans '
          'votre profil pour calculer le rendement.';
    }
    return YieldResult(quantityKg: kg, areaHectares: hectares, reason: reason);
  }
}

/// Comparaison entre la récolte réelle et l'estimation initiale.
class HarvestComparison {
  const HarvestComparison({
    required this.actualCycleDays,
    required this.estimatedCycleDays,
    required this.differenceDays,
  });

  final int actualCycleDays;
  final int estimatedCycleDays;

  /// Écart en jours (positif = récolte plus tardive que prévu).
  final int differenceDays;

  static HarvestComparison of(Harvest harvest, Crop crop) {
    final actual = DateOnly.daysBetween(crop.sowingDate, harvest.harvestDate);
    return HarvestComparison(
      actualCycleDays: actual,
      estimatedCycleDays: harvest.estimatedMaturationDays,
      differenceDays: DateOnly.daysBetween(
        harvest.estimatedHarvestDate,
        harvest.harvestDate,
      ),
    );
  }

  String get summary {
    if (differenceDays == 0) return 'Récolte à la date estimée.';
    final days = differenceDays.abs();
    final plural = days > 1 ? 's' : '';
    return differenceDays > 0
        ? 'Récolte $days jour$plural plus tard que l’estimation.'
        : 'Récolte $days jour$plural plus tôt que l’estimation.';
  }
}
