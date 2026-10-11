import 'package:agrisaha/core/errors/app_exception.dart';
import 'package:agrisaha/data/catalog/crop_catalog.dart';
import 'package:agrisaha/data/models/crop.dart';
import 'package:agrisaha/data/models/crop_variety.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/domain/harvest_estimator.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final hkp = CropCatalog.varietyById('catalog:mil:hkp')!;

  test('date estimée = date de semis + durée de la variété', () {
    final estimate = HarvestEstimator.fromVariety(
      DateTime.utc(2025, 6, 15),
      hkp,
    );
    expect(estimate.maturationDays, 90);
    expect(estimate.estimatedDate, DateTime.utc(2025, 9, 13));
    expect(estimate.source, EstimateSource.catalogVariety);
    expect(estimate.confidence, EstimateConfidence.medium);
    expect(estimate.earliestDate, DateTime.utc(2025, 9, 8));
    expect(estimate.latestDate, DateTime.utc(2025, 9, 18));
    expect(estimate.isGeneric, isFalse);
  });

  test('estimation générique signalée comme telle', () {
    final estimate = HarvestEstimator.generic(
      DateTime.utc(2025, 7, 1),
      CropType.niebe,
    )!;
    expect(estimate.isGeneric, isTrue);
    expect(estimate.confidence, EstimateConfidence.low);
    expect(estimate.estimatedDate, DateTime.utc(2025, 9, 14));
  });

  test('pas d’estimation générique pour « Autre culture »', () {
    expect(
      HarvestEstimator.generic(DateTime.utc(2025, 7, 1), CropType.autre),
      isNull,
    );
    expect(
      HarvestEstimator.estimate(
        sowingDate: DateTime.utc(2025, 7, 1),
        cropType: CropType.autre,
      ),
      isNull,
    );
  });

  test('traverse un 29 février', () {
    final estimate = HarvestEstimator.fromDuration(
      DateTime.utc(2024, 1, 1),
      90,
    );
    // 31 (janv.) + 29 (févr. 2024) + 30 = 90 → 31 mars 2024.
    expect(estimate.estimatedDate, DateTime.utc(2024, 3, 31));
    final nonLeap = HarvestEstimator.fromDuration(DateTime.utc(2023, 1, 1), 90);
    expect(nonLeap.estimatedDate, DateTime.utc(2023, 4, 1));
  });

  test('change d’année', () {
    final estimate = HarvestEstimator.fromDuration(
      DateTime.utc(2025, 11, 20),
      110,
    );
    expect(estimate.estimatedDate, DateTime.utc(2026, 3, 10));
  });

  test('date manuelle : durée calculée et validée', () {
    final estimate = HarvestEstimator.manual(
      DateTime.utc(2025, 6, 1),
      DateTime.utc(2025, 9, 1),
    );
    expect(estimate.maturationDays, 92);
    expect(estimate.source, EstimateSource.manual);
    expect(
      () => HarvestEstimator.manual(
        DateTime.utc(2025, 6, 1),
        DateTime.utc(2025, 5, 1),
      ),
      throwsA(isA<ValidationException>()),
    );
  });

  test('priorité : manuelle > variété > durée saisie > générique', () {
    final sowing = DateTime.utc(2025, 6, 1);
    final manual = HarvestEstimator.estimate(
      sowingDate: sowing,
      cropType: CropType.mil,
      variety: hkp,
      customCycleDays: 100,
      manualHarvestDate: DateTime.utc(2025, 8, 20),
    )!;
    expect(manual.source, EstimateSource.manual);

    final variety = HarvestEstimator.estimate(
      sowingDate: sowing,
      cropType: CropType.mil,
      variety: hkp,
      customCycleDays: 100,
    )!;
    expect(variety.maturationDays, 90);

    final custom = HarvestEstimator.estimate(
      sowingDate: sowing,
      cropType: CropType.mil,
      customCycleDays: 100,
    )!;
    expect(custom.maturationDays, 100);
    expect(custom.source, EstimateSource.userVariety);

    final generic = HarvestEstimator.estimate(
      sowingDate: sowing,
      cropType: CropType.mil,
    )!;
    expect(generic.source, EstimateSource.generic);
  });

  test('variété personnalisée : confiance « selon votre saisie »', () {
    const variety = CropVariety(
      id: 'x',
      cropType: CropType.sorgho,
      name: 'Locale',
      cycleMinDays: 100,
      cycleMaxDays: 100,
      typicalCycleDays: 100,
      source: 'Agriculteur',
      validationStatus: ValidationStatus.userProvided,
      isUserDefined: true,
    );
    final estimate = HarvestEstimator.fromVariety(
      DateTime.utc(2025, 6, 1),
      variety,
    );
    expect(estimate.source, EstimateSource.userVariety);
    expect(estimate.confidence, EstimateConfidence.userDefined);
  });

  test('le catalogue est cohérent (min ≤ typique ≤ max)', () {
    for (final cycle in CropCatalog.genericCycles) {
      expect(cycle.minDays <= cycle.typicalDays, isTrue);
      expect(cycle.typicalDays <= cycle.maxDays, isTrue);
    }
    for (final v in CropCatalog.varieties) {
      expect(v.cycleMinDays <= v.typicalCycleDays, isTrue);
      expect(v.typicalCycleDays <= v.cycleMaxDays, isTrue);
      expect(v.validationStatus, ValidationStatus.toValidate);
    }
  });
}
