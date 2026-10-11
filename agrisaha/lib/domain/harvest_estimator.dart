import '../core/errors/app_exception.dart';
import '../core/utils/date_utils.dart';
import '../data/catalog/crop_catalog.dart';
import '../data/models/crop.dart';
import '../data/models/crop_variety.dart';
import '../data/models/enums.dart';

enum EstimateConfidence {
  low('Faible — estimation générique, à préciser'),
  medium('Moyenne — durée de la variété, à ajuster selon les conditions'),
  userDefined('Selon la durée ou la date que vous avez saisie');

  const EstimateConfidence(this.label);
  final String label;
}

/// Résultat du calcul de la date prévisionnelle de récolte.
class HarvestEstimate {
  const HarvestEstimate({
    required this.sowingDate,
    required this.estimatedDate,
    required this.maturationDays,
    this.minDays,
    this.maxDays,
    required this.source,
    required this.confidence,
    this.sourceNote,
  });

  final DateTime sowingDate;
  final DateTime estimatedDate;
  final int maturationDays;
  final int? minDays;
  final int? maxDays;
  final EstimateSource source;
  final EstimateConfidence confidence;
  final String? sourceNote;

  bool get isGeneric => source == EstimateSource.generic;

  DateTime? get earliestDate =>
      minDays == null ? null : DateOnly.addDays(sowingDate, minDays!);

  DateTime? get latestDate =>
      maxDays == null ? null : DateOnly.addDays(sowingDate, maxDays!);
}

/// Calcule la date estimée de récolte :
/// date estimée = date de semis + durée estimée de maturation.
class HarvestEstimator {
  const HarvestEstimator._();

  static const int maxCycleDays = 400;

  static HarvestEstimate fromVariety(DateTime sowingDate, CropVariety variety) {
    final sowing = DateOnly.of(sowingDate);
    return HarvestEstimate(
      sowingDate: sowing,
      estimatedDate: DateOnly.addDays(sowing, variety.typicalCycleDays),
      maturationDays: variety.typicalCycleDays,
      minDays: variety.cycleMinDays,
      maxDays: variety.cycleMaxDays,
      source: variety.isUserDefined
          ? EstimateSource.userVariety
          : EstimateSource.catalogVariety,
      confidence: variety.isUserDefined
          ? EstimateConfidence.userDefined
          : EstimateConfidence.medium,
      sourceNote: variety.source,
    );
  }

  /// Estimation générique de la culture (variété inconnue). Retourne `null`
  /// si aucune durée générique n'est documentée (ex. « Autre culture »).
  static HarvestEstimate? generic(DateTime sowingDate, CropType cropType) {
    final cycle = CropCatalog.genericFor(cropType);
    if (cycle == null) return null;
    final sowing = DateOnly.of(sowingDate);
    return HarvestEstimate(
      sowingDate: sowing,
      estimatedDate: DateOnly.addDays(sowing, cycle.typicalDays),
      maturationDays: cycle.typicalDays,
      minDays: cycle.minDays,
      maxDays: cycle.maxDays,
      source: EstimateSource.generic,
      confidence: EstimateConfidence.low,
      sourceNote: '${CropCatalog.genericSource}. ${cycle.note}',
    );
  }

  /// Durée de cycle saisie par l'agriculteur ou le conseiller.
  static HarvestEstimate fromDuration(DateTime sowingDate, int days) {
    _checkDuration(days);
    final sowing = DateOnly.of(sowingDate);
    return HarvestEstimate(
      sowingDate: sowing,
      estimatedDate: DateOnly.addDays(sowing, days),
      maturationDays: days,
      source: EstimateSource.userVariety,
      confidence: EstimateConfidence.userDefined,
      sourceNote: 'Durée de cycle saisie par l’utilisateur',
    );
  }

  /// Date de récolte saisie ou corrigée manuellement.
  static HarvestEstimate manual(DateTime sowingDate, DateTime harvestDate) {
    final sowing = DateOnly.of(sowingDate);
    final days = DateOnly.daysBetween(sowing, harvestDate);
    _checkDuration(days);
    return HarvestEstimate(
      sowingDate: sowing,
      estimatedDate: DateOnly.of(harvestDate),
      maturationDays: days,
      source: EstimateSource.manual,
      confidence: EstimateConfidence.userDefined,
      sourceNote: 'Date saisie ou corrigée manuellement',
    );
  }

  /// Choisit la meilleure estimation disponible, par ordre de priorité :
  /// date manuelle, variété connue, durée saisie, durée générique.
  static HarvestEstimate? estimate({
    required DateTime sowingDate,
    required CropType cropType,
    CropVariety? variety,
    int? customCycleDays,
    DateTime? manualHarvestDate,
  }) {
    if (manualHarvestDate != null) return manual(sowingDate, manualHarvestDate);
    if (variety != null) return fromVariety(sowingDate, variety);
    if (customCycleDays != null) {
      return fromDuration(sowingDate, customCycleDays);
    }
    return generic(sowingDate, cropType);
  }

  static EstimateConfidence confidenceOf(EstimateSource source) =>
      switch (source) {
        EstimateSource.catalogVariety => EstimateConfidence.medium,
        EstimateSource.generic => EstimateConfidence.low,
        EstimateSource.userVariety ||
        EstimateSource.manual => EstimateConfidence.userDefined,
      };

  static void _checkDuration(int days) {
    if (days <= 0) {
      throw const ValidationException(
        'La date de récolte doit être postérieure à la date de semis.',
      );
    }
    if (days > maxCycleDays) {
      throw const ValidationException(
        'La durée de cycle dépasse $maxCycleDays jours : vérifiez les dates.',
      );
    }
  }
}
