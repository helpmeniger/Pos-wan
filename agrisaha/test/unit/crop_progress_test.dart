import 'package:agrisaha/data/models/crop.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/domain/crop_progress.dart';
import 'package:flutter_test/flutter_test.dart';

Crop buildCrop({
  required DateTime sowing,
  int days = 100,
  CropStatus status = CropStatus.active,
}) {
  final now = DateTime.utc(2025, 1, 1);
  return Crop(
    id: 'c1',
    plotId: 'p1',
    cropType: CropType.mil,
    seedType: SeedType.amelioree,
    sowingDate: sowing,
    area: 1,
    areaUnit: AreaUnit.hectare,
    irrigationMode: IrrigationMode.pluvial,
    maturationDays: days,
    estimateSource: EstimateSource.generic,
    estimatedHarvestDate: sowing.add(Duration(days: days)),
    status: status,
    createdAt: now,
    updatedAt: now,
  );
}

void main() {
  test('âge et jours restants', () {
    final crop = buildCrop(sowing: DateTime.utc(2025, 6, 1));
    final progress = CropProgress.compute(crop, DateTime.utc(2025, 7, 1));
    expect(progress.ageDays, 30);
    expect(progress.daysRemaining, 70);
    expect(progress.fraction, closeTo(0.30, 0.0001));
    expect(progress.stage, GrowthStage.vegetative);
  });

  test(
    'âge correct à travers un changement de mois et une année bissextile',
    () {
      final crop = buildCrop(sowing: DateTime.utc(2024, 2, 15));
      final progress = CropProgress.compute(crop, DateTime.utc(2024, 3, 1));
      expect(progress.ageDays, 15);
    },
  );

  test('l’heure du jour n’influence pas le calcul', () {
    final crop = buildCrop(sowing: DateTime.utc(2025, 6, 1));
    final morning = CropProgress.compute(crop, DateTime(2025, 6, 11, 0, 5));
    final evening = CropProgress.compute(crop, DateTime(2025, 6, 11, 23, 55));
    expect(morning.ageDays, 10);
    expect(evening.ageDays, 10);
  });

  test('semis futur → « Semis prévu »', () {
    final crop = buildCrop(sowing: DateTime.utc(2025, 8, 1));
    final progress = CropProgress.compute(crop, DateTime.utc(2025, 7, 25));
    expect(progress.ageDays, -7);
    expect(progress.stage, GrowthStage.planned);
    expect(progress.fraction, 0);
  });

  test('récolte estimée dépassée', () {
    final crop = buildCrop(sowing: DateTime.utc(2025, 6, 1), days: 90);
    final progress = CropProgress.compute(crop, DateTime.utc(2025, 9, 5));
    expect(progress.daysRemaining, -6);
    expect(progress.isHarvestOverdue, isTrue);
    expect(progress.stage, GrowthStage.harvestDue);
  });

  test('culture récoltée', () {
    final crop = buildCrop(
      sowing: DateTime.utc(2025, 6, 1),
      status: CropStatus.harvested,
    );
    final progress = CropProgress.compute(crop, DateTime.utc(2025, 12, 1));
    expect(progress.stage, GrowthStage.harvested);
    expect(progress.isHarvestOverdue, isFalse);
  });

  test('stades par avancement', () {
    expect(CropProgress.stageForFraction(0.05), GrowthStage.establishment);
    expect(CropProgress.stageForFraction(0.5), GrowthStage.flowering);
    expect(CropProgress.stageForFraction(0.7), GrowthStage.filling);
    expect(CropProgress.stageForFraction(0.9), GrowthStage.maturation);
    expect(CropProgress.stageForFraction(1.0), GrowthStage.harvestDue);
  });
}
