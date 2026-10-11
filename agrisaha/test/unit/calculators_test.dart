import 'package:agrisaha/data/models/crop.dart';
import 'package:agrisaha/data/models/crop_task.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/data/models/finance_entry.dart';
import 'package:agrisaha/data/models/harvest.dart';
import 'package:agrisaha/data/models/plot.dart';
import 'package:agrisaha/domain/alert_builder.dart';
import 'package:agrisaha/domain/dashboard_calculator.dart';
import 'package:agrisaha/domain/finance_calculator.dart';
import 'package:agrisaha/domain/yield_calculator.dart';
import 'package:flutter_test/flutter_test.dart';

final _now = DateTime.utc(2025, 9, 1);

Crop _crop({
  String id = 'c1',
  CropType type = CropType.mil,
  double area = 2,
  AreaUnit unit = AreaUnit.hectare,
  DateTime? sowing,
  DateTime? harvest,
  CropStatus status = CropStatus.active,
}) => Crop(
  id: id,
  plotId: 'p1',
  cropType: type,
  seedType: SeedType.amelioree,
  sowingDate: sowing ?? DateTime.utc(2025, 6, 1),
  area: area,
  areaUnit: unit,
  irrigationMode: IrrigationMode.pluvial,
  maturationDays: 90,
  estimateSource: EstimateSource.catalogVariety,
  estimatedHarvestDate: harvest ?? DateTime.utc(2025, 8, 30),
  status: status,
  createdAt: _now,
  updatedAt: _now,
);

Harvest _harvest({
  double quantity = 20,
  HarvestUnit unit = HarvestUnit.sac,
  double? kgPerUnit = 100,
  double? area,
  AreaUnit? areaUnit,
}) => Harvest(
  id: 'h1',
  cropId: 'c1',
  harvestDate: DateTime.utc(2025, 9, 4),
  quantity: quantity,
  unit: unit,
  kgPerUnit: kgPerUnit,
  harvestedArea: area,
  harvestedAreaUnit: areaUnit,
  estimatedHarvestDate: DateTime.utc(2025, 8, 30),
  estimatedMaturationDays: 90,
  createdAt: _now,
  updatedAt: _now,
);

FinanceEntry _entry(
  FinanceCategory category,
  double amount, {
  String? cropId,
  DateTime? date,
}) => FinanceEntry(
  id: '$category$amount',
  category: category,
  amount: amount,
  entryDate: date ?? DateTime.utc(2025, 8, 1),
  cropId: cropId,
  createdAt: _now,
  updatedAt: _now,
);

void main() {
  group('Rendement', () {
    test('sacs convertis en kg, rendement par hectare', () {
      final result = YieldCalculator.compute(
        _harvest(),
        _crop(),
        const AreaConverter(),
      );
      expect(result.quantityKg, 2000);
      expect(result.areaHectares, 2);
      expect(result.kgPerHectare, 1000);
    });

    test('surface récoltée en m² prioritaire sur la surface cultivée', () {
      final result = YieldCalculator.compute(
        _harvest(
          quantity: 300,
          unit: HarvestUnit.kg,
          kgPerUnit: null,
          area: 2500,
          areaUnit: AreaUnit.squareMeter,
        ),
        _crop(),
        const AreaConverter(),
      );
      expect(result.areaHectares, 0.25);
      expect(result.kgPerHectare, 1200);
    });

    test('tonnes', () {
      expect(QuantityConverter.toKg(1.5, HarvestUnit.tonne, null), 1500);
    });

    test('non calculable sans poids du sac', () {
      final result = YieldCalculator.compute(
        _harvest(kgPerUnit: null),
        _crop(),
        const AreaConverter(),
      );
      expect(result.kgPerHectare, isNull);
      expect(result.reason, isNotNull);
    });

    test('unité locale : nécessite l’équivalence du profil', () {
      final crop = _crop(area: 4, unit: AreaUnit.local);
      expect(
        YieldCalculator.compute(
          _harvest(),
          crop,
          const AreaConverter(),
        ).kgPerHectare,
        isNull,
      );
      final withFactor = YieldCalculator.compute(
        _harvest(),
        crop,
        const AreaConverter(localUnitHectares: 0.25),
      );
      expect(withFactor.areaHectares, 1);
      expect(withFactor.kgPerHectare, 2000);
    });

    test('comparaison avec l’estimation', () {
      final comparison = HarvestComparison.of(_harvest(), _crop());
      expect(comparison.actualCycleDays, 95);
      expect(comparison.estimatedCycleDays, 90);
      expect(comparison.differenceDays, 5);
      expect(comparison.summary, contains('plus tard'));
    });
  });

  group('Finances', () {
    final entries = [
      _entry(FinanceCategory.semences, 10000, cropId: 'c1'),
      _entry(FinanceCategory.engrais, 15000, cropId: 'c1'),
      _entry(
        FinanceCategory.mainOeuvre,
        5000,
        cropId: 'c2',
        date: DateTime.utc(2024, 8, 1),
      ),
      _entry(FinanceCategory.vente, 60000, cropId: 'c1'),
    ];

    test('dépenses, recettes et marge', () {
      final summary = FinanceCalculator.summarize(entries);
      expect(summary.totalExpenses, 30000);
      expect(summary.totalIncomes, 60000);
      expect(summary.margin, 30000);
      expect(summary.byCategory[FinanceCategory.engrais], 15000);
    });

    test('filtre par culture et par période', () {
      final byCrop = FinanceCalculator.summarize(
        entries,
        filter: const FinanceFilter(cropId: 'c1'),
      );
      expect(byCrop.totalExpenses, 25000);
      final byPeriod = FinanceCalculator.summarize(
        entries,
        filter: FinanceFilter(from: DateTime.utc(2025, 1, 1)),
      );
      expect(byPeriod.totalExpenses, 25000);
      expect(byPeriod.entryCount, 3);
    });

    test('coût de production par kg', () {
      expect(FinanceCalculator.productionCostPerKg(30000, 2000), 15);
      expect(FinanceCalculator.productionCostPerKg(30000, 0), isNull);
    });
  });

  group('Alertes', () {
    CropTask task(
      String key,
      DateTime due,
      DateTime end, {
      TaskStatus status = TaskStatus.todo,
    }) => CropTask(
      id: key,
      cropId: 'c1',
      templateKey: key,
      title: key,
      description: '',
      anchor: TaskAnchor.sowing,
      offsetStartDays: 0,
      offsetEndDays: 0,
      dueDate: due,
      windowEndDate: end,
      status: status,
      createdAt: _now,
      updatedAt: _now,
    );

    test('retard, jour même, à venir et récolte proche', () {
      final today = DateTime.utc(2025, 8, 20);
      final alerts = AlertBuilder.build(
        crops: [_crop()],
        tasks: [
          task('late', DateTime.utc(2025, 8, 1), DateTime.utc(2025, 8, 10)),
          task('today', DateTime.utc(2025, 8, 18), DateTime.utc(2025, 8, 22)),
          task('soon', DateTime.utc(2025, 8, 23), DateTime.utc(2025, 8, 25)),
          task(
            'done',
            DateTime.utc(2025, 8, 1),
            DateTime.utc(2025, 8, 2),
            status: TaskStatus.done,
          ),
          task(
            'old',
            DateTime.utc(2025, 5, 1),
            DateTime.utc(2025, 5, 2),
            status: TaskStatus.notTracked,
          ),
        ],
        today: today,
      );
      final kinds = alerts.map((a) => a.kind).toList();
      expect(kinds.first, AlertKind.taskOverdue);
      expect(kinds, contains(AlertKind.taskToday));
      expect(kinds, contains(AlertKind.taskUpcoming));
      expect(kinds, contains(AlertKind.harvestSoon));
      expect(alerts.where((a) => a.taskId == 'done'), isEmpty);
      expect(alerts.where((a) => a.taskId == 'old'), isEmpty);
    });

    test('aucune alerte pour une culture récoltée', () {
      final alerts = AlertBuilder.build(
        crops: [_crop(status: CropStatus.harvested)],
        tasks: [
          task('late', DateTime.utc(2025, 8, 1), DateTime.utc(2025, 8, 10)),
        ],
        today: DateTime.utc(2025, 9, 20),
      );
      expect(alerts, isEmpty);
    });
  });

  group('Tableau de bord', () {
    test('vide sans données', () {
      final stats = DashboardCalculator.compute(
        plots: const [],
        crops: const [],
        tasks: const [],
        harvests: const [],
        financeEntries: const [],
        journalEntries: const [],
        areaConverter: const AreaConverter(),
        today: _now,
      );
      expect(stats.isEmpty, isTrue);
      expect(stats.totalHarvestKg, 0);
      expect(stats.nextHarvestCrop, isNull);
    });

    test('indicateurs calculés depuis les données', () {
      final plot = Plot(
        id: 'p1',
        name: 'Champ',
        area: 3,
        areaUnit: AreaUnit.hectare,
        createdAt: _now,
        updatedAt: _now,
      );
      final harvested = _crop(status: CropStatus.harvested);
      final active = _crop(
        id: 'c2',
        type: CropType.niebe,
        harvest: DateTime.utc(2025, 9, 10),
      );
      final stats = DashboardCalculator.compute(
        plots: [plot],
        crops: [harvested, active],
        tasks: const [],
        harvests: [_harvest()],
        financeEntries: [
          _entry(FinanceCategory.semences, 10000, cropId: 'c1'),
          _entry(FinanceCategory.vente, 50000, cropId: 'c1'),
        ],
        journalEntries: const [],
        areaConverter: const AreaConverter(),
        today: _now,
      );
      expect(stats.plotCount, 1);
      expect(stats.activeCropCount, 1);
      expect(stats.nearHarvestCount, 1);
      expect(stats.nextHarvestCrop?.id, 'c2');
      expect(stats.totalHarvestKg, 2000);
      expect(stats.finance.margin, 40000);
      final mil = stats.performances.firstWhere((p) => p.label == 'Mil');
      expect(mil.yieldKgPerHa, 1000);
      expect(mil.margin, 40000);
    });
  });
}
